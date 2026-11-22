<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\Mikrotik\MikrotikProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IsolateOverdueCommand extends Command
{
    protected $signature = 'billing:isolate-overdue';

    protected $description = 'Isolate overdue subscriptions on their final active day (23:59 WIB) or after exceeding the grace period.';

    public function handle(): int
    {
        $settings = CompanySetting::current();
        $today = now('Asia/Jakarta')->startOfDay();
        $globalCutoff = $today->copy()->subDays($settings->grace_period_days)->toDateString();

        $invoices = Invoice::query()
            ->with(['subscription', 'customer'])
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
            ->whereHas('subscription', function ($q): void {
                $q->where('status', SubscriptionStatus::Active->value)
                    ->where('auto_isolate', true);
            })
            ->where(function ($q) use ($today, $globalCutoff, $settings): void {
                // Customers with a custom isolir_day: isolate on their last active day (due_date <= today at 23:59 WIB)
                $q->where(function ($subQ) use ($today): void {
                    $subQ->whereHas('subscription', fn ($s) => $s->whereNotNull('isolir_day'))
                        ->whereDate('due_date', '<=', $today->toDateString());
                })->orWhere(function ($subQ) use ($today, $globalCutoff, $settings): void {
                    $subQ->whereHas('subscription', fn ($s) => $s->whereNull('isolir_day'));
                    if ($settings->grace_period_days <= 0) {
                        $subQ->whereDate('due_date', '<=', $today->toDateString());
                    } else {
                        $subQ->whereDate('due_date', '<', $globalCutoff);
                    }
                });
            })
            ->get();

        $isolatedCount = 0;

        foreach ($invoices as $invoice) {
            $isolated = DB::transaction(function () use ($invoice): bool {
                $lockedInvoice = Invoice::query()
                    ->with('customer')
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $lockedInvoice === null
                    || !in_array($lockedInvoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)
                ) {
                    return false;
                }

                $lockedSubscription = \App\Models\Subscription::query()
                    ->whereKey($lockedInvoice->subscription_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $lockedSubscription === null
                    || $lockedSubscription->status !== SubscriptionStatus::Active
                    || !$lockedSubscription->auto_isolate
                ) {
                    return false;
                }

                $lockedInvoice->forceFill(['status' => InvoiceStatus::Overdue])->save();

                $lockedSubscription->forceFill([
                    'status' => SubscriptionStatus::Isolated,
                    'isolated_at' => now(),
                ])->save();

                app(MikrotikProvisioningService::class)->queueAction(
                    $lockedSubscription,
                    'isolate',
                    'scheduler_isolir',
                    afterCommit: true
                );

                SendWhatsAppNotificationJob::dispatch($lockedInvoice->customer, 'service_isolated', $lockedInvoice)
                    ->afterCommit();

                return true;
            });

            if ($isolated) {
                $isolatedCount++;
            }
        }

        $this->info("Isolated {$isolatedCount} subscription(s).");

        return self::SUCCESS;
    }
}
