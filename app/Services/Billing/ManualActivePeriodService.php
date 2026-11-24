<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mikrotik\MikrotikProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualActivePeriodService
{
    public const AUDIT_ACTION = 'subscription.manual_active_period_updated';

    public const BULK_AUDIT_ACTION = 'subscription.bulk_active_period_updated';

    public function __construct(
        private readonly MikrotikProvisioningService $provisioningService
    ) {}

    /**
     * Manually update a subscription's active period date (next_billing_date).
     * Automatically restores isolated subscriptions when the new date is in the future (Asia/Jakarta).
     *
     * @return array{
     *     subscription: Subscription,
     *     old_date: string,
     *     new_date: string,
     *     delta_days: int,
     *     changed: bool,
     *     auto_restored: bool,
     *     audit_log: ?AuditLog
     * }
     */
    public function updateActivePeriod(
        Subscription $subscription,
        string $nextBillingDate,
        ?User $actor,
        ?string $ipAddress = null,
        ?string $reason = null
    ): array {
        $newDate = CarbonImmutable::createFromFormat('!Y-m-d', $nextBillingDate, 'Asia/Jakarta');
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $cleanReason = $reason !== null ? trim($reason) : null;
        if ($cleanReason === '') {
            $cleanReason = null;
        }

        return DB::transaction(function () use ($subscription, $newDate, $today, $actor, $ipAddress, $cleanReason): array {
            /** @var Subscription $lockedSub */
            $lockedSub = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldDate = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $lockedSub->next_billing_date->toDateString(),
                'Asia/Jakarta'
            );

            $oldDateString = $oldDate->toDateString();
            $newDateString = $newDate->toDateString();
            $deltaDays = (int) $oldDate->diffInDays($newDate, false);
            $dateChanged = $oldDateString !== $newDateString;

            $isFutureDate = $newDate->greaterThan($today);
            $shouldAutoRestore = $lockedSub->status === SubscriptionStatus::Isolated && $isFutureDate;

            if (! $dateChanged && ! $shouldAutoRestore) {
                return [
                    'subscription' => $lockedSub,
                    'old_date' => $oldDateString,
                    'new_date' => $newDateString,
                    'delta_days' => 0,
                    'changed' => false,
                    'auto_restored' => false,
                    'audit_log' => null,
                ];
            }

            $oldStatus = $lockedSub->status;
            $oldIsolatedAt = $lockedSub->isolated_at;

            $updates = [
                'next_billing_date' => $newDateString,
            ];

            if ($shouldAutoRestore) {
                $updates += [
                    'status' => SubscriptionStatus::Active,
                    'activated_at' => $lockedSub->activated_at ?? now(),
                    'isolated_at' => null,
                ];
            }

            $lockedSub->forceFill($updates)->save();

            if ($shouldAutoRestore) {
                $this->provisioningService->queueAction(
                    $lockedSub,
                    'restore',
                    'manual_noc',
                    notifyRestored: true,
                    afterCommit: true
                );
            }

            $auditLog = AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => self::AUDIT_ACTION,
                'auditable_type' => Subscription::class,
                'auditable_id' => $lockedSub->id,
                'old_values' => [
                    'next_billing_date' => $oldDateString,
                    'status' => $oldStatus->value,
                    'isolated_at' => $oldIsolatedAt?->toIso8601String(),
                ],
                'new_values' => [
                    'next_billing_date' => $newDateString,
                    'status' => $lockedSub->status->value,
                    'isolated_at' => $lockedSub->isolated_at?->toIso8601String(),
                    'delta_days' => $deltaDays,
                    'auto_restored' => $shouldAutoRestore,
                    'reason' => $cleanReason,
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return [
                'subscription' => $lockedSub->fresh(),
                'old_date' => $oldDateString,
                'new_date' => $newDateString,
                'delta_days' => $deltaDays,
                'changed' => true,
                'auto_restored' => $shouldAutoRestore,
                'audit_log' => $auditLog,
            ];
        });
    }

    /**
     * Adjust active period dates relatively (+N or -N days) in bulk.
     * Strictly applies ONLY to subscriptions with SubscriptionStatus::Active.
     *
     * @param  array<int, int|string>  $customerIds
     * @return array{
     *     batch_id: string,
     *     target_scope: string,
     *     delta_days: int,
     *     updated_count: int,
     *     updated_customer_count: int,
     *     skipped_customer_count: int
     * }
     */
    public function bulkAdjustActivePeriod(
        string $targetScope,
        array $customerIds,
        int $deltaDays,
        ?User $actor,
        ?string $ipAddress = null,
        ?string $reason = null
    ): array {
        if ($deltaDays === 0) {
            throw new \InvalidArgumentException('Bulk adjustment delta days cannot be zero.');
        }

        $cleanReason = $reason !== null ? trim($reason) : null;
        if ($cleanReason === '') {
            $cleanReason = null;
        }

        $uniqueCustomerIds = array_values(array_unique(array_map('intval', $customerIds)));
        $batchId = (string) Str::uuid();

        return DB::transaction(function () use (
            $targetScope,
            $uniqueCustomerIds,
            $deltaDays,
            $actor,
            $ipAddress,
            $cleanReason,
            $batchId
        ): array {
            $query = Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->orderBy('id')
                ->lockForUpdate();

            if ($targetScope === 'selected') {
                if ($uniqueCustomerIds === []) {
                    return [
                        'batch_id' => $batchId,
                        'target_scope' => $targetScope,
                        'delta_days' => $deltaDays,
                        'updated_count' => 0,
                        'updated_customer_count' => 0,
                        'skipped_customer_count' => 0,
                    ];
                }

                $query->whereIn('customer_id', $uniqueCustomerIds);
            }

            $subscriptions = $query->get();
            $now = now();
            $updatedCount = 0;
            $updatedCustomerIds = [];

            foreach ($subscriptions as $lockedSub) {
                if ($lockedSub->status !== SubscriptionStatus::Active) {
                    continue;
                }

                $oldDate = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $lockedSub->next_billing_date->toDateString(),
                    'Asia/Jakarta'
                );
                $newDate = $oldDate->addDays($deltaDays);

                $oldDateString = $oldDate->toDateString();
                $newDateString = $newDate->toDateString();

                $lockedSub->forceFill([
                    'next_billing_date' => $newDateString,
                ])->save();

                AuditLog::query()->create([
                    'user_id' => $actor?->id,
                    'action' => self::BULK_AUDIT_ACTION,
                    'auditable_type' => Subscription::class,
                    'auditable_id' => $lockedSub->id,
                    'old_values' => [
                        'next_billing_date' => $oldDateString,
                        'status' => SubscriptionStatus::Active->value,
                    ],
                    'new_values' => [
                        'next_billing_date' => $newDateString,
                        'status' => SubscriptionStatus::Active->value,
                        'delta_days' => $deltaDays,
                        'auto_restored' => false,
                        'bulk' => true,
                        'bulk_batch_id' => $batchId,
                        'target_scope' => $targetScope,
                        'reason' => $cleanReason,
                    ],
                    'ip_address' => $ipAddress,
                    'created_at' => $now,
                ]);

                $updatedCount++;
                $updatedCustomerIds[$lockedSub->customer_id] = true;
            }

            $updatedCustomerCount = count($updatedCustomerIds);
            $skippedCustomerCount = $targetScope === 'selected'
                ? max(0, count($uniqueCustomerIds) - $updatedCustomerCount)
                : 0;

            return [
                'batch_id' => $batchId,
                'target_scope' => $targetScope,
                'delta_days' => $deltaDays,
                'updated_count' => $updatedCount,
                'updated_customer_count' => $updatedCustomerCount,
                'skipped_customer_count' => $skippedCustomerCount,
            ];
        });
    }
}
