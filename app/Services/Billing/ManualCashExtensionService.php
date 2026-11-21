<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendTelegramNotificationJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mikrotik\MikrotikProvisioningService;
use Illuminate\Support\Facades\DB;

class ManualCashExtensionService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    /**
     * Record a manual cash payment to settle an invoice or extend subscription validity.
     *
     * @return array{invoice: Invoice, payment: Payment}
     */
    public function receiveCash(
        Subscription $subscription,
        ?Invoice $invoice,
        ?User $actor,
        ?string $ipAddress = null,
        bool $sendWaReceipt = true
    ): array {
        $targetInvoice = $invoice;

        if ($targetInvoice === null) {
            $targetInvoice = Invoice::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
                ->latest('id')
                ->first();
        }

        if ($targetInvoice === null) {
            $targetInvoice = $this->invoiceService->issueForSubscription($subscription, notify: false);
            if ($targetInvoice === null) {
                $targetInvoice = Invoice::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)
                    ->latest('id')
                    ->firstOrFail();

                if ($targetInvoice->status === InvoiceStatus::Paid) {
                    // Subscriber already paid the current period; advance by 1 month and issue next invoice
                    $nextStart = $targetInvoice->billing_period_end->copy()->addDay();
                    if ($subscription->next_billing_date->lessThanOrEqualTo($targetInvoice->billing_period_start)) {
                        $subscription->forceFill(['next_billing_date' => $nextStart])->save();
                    }
                    $targetInvoice = $this->invoiceService->issueForSubscription($subscription, notify: false) ?? $targetInvoice;
                }
            }
        }

        return DB::transaction(function () use ($targetInvoice, $actor, $ipAddress, $sendWaReceipt): array {
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::query()
                ->with(['subscription', 'customer'])
                ->where('id', $targetInvoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status === InvoiceStatus::Paid) {
                $paidPayment = $lockedInvoice->payments()
                    ->where('status', PaymentStatus::Paid->value)
                    ->latest('id')
                    ->firstOrFail();

                return ['invoice' => $lockedInvoice, 'payment' => $paidPayment];
            }

            Payment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);

            $attempt = $lockedInvoice->payment_attempt_count + 1;
            $code = sprintf('CASH-%s-%02d', $lockedInvoice->invoice_number, $attempt);
            $amount = (float) $lockedInvoice->total_amount;

            $payment = Payment::query()->create([
                'invoice_id' => $lockedInvoice->id,
                'attempt_number' => $attempt,
                'payment_code' => $code,
                'gateway' => 'manual_cash',
                'amount' => $amount,
                'gateway_fee' => 0,
                'net_amount' => $amount,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'settled_at' => now(),
                'recorded_by_user_id' => $actor?->id,
            ]);

            $oldStatus = $lockedInvoice->status->value;

            $lockedInvoice->forceFill([
                'payment_attempt_count' => $attempt,
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $subscription = $lockedInvoice->subscription;
            $needsRestore = in_array(
                $subscription->status,
                [SubscriptionStatus::Isolated, SubscriptionStatus::PendingInstallation],
                true
            );

            $nextBilling = $lockedInvoice->billing_period_end->copy()->addDay();
            $updates = [
                'next_billing_date' => $subscription->next_billing_date->greaterThan($nextBilling)
                    ? $subscription->next_billing_date
                    : $nextBilling,
            ];

            if ($needsRestore) {
                $updates += [
                    'status' => SubscriptionStatus::Active,
                    'activated_at' => $subscription->activated_at ?? now(),
                    'isolated_at' => null,
                ];
            }

            $subscription->forceFill($updates)->save();

            if ($needsRestore) {
                app(MikrotikProvisioningService::class)->queueAction(
                    $subscription,
                    'restore',
                    'manual_noc',
                    invoiceId: $lockedInvoice->id,
                    notifyRestored: true,
                    afterCommit: true
                );
            }

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'invoice.manual_cash',
                'auditable_type' => Invoice::class,
                'auditable_id' => $lockedInvoice->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => ['status' => InvoiceStatus::Paid->value, 'payment_code' => $code],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            if ($sendWaReceipt) {
                SendWhatsAppNotificationJob::dispatch($lockedInvoice->customer, 'payment_receipt', $lockedInvoice)
                    ->afterCommit();
            }
            SendTelegramNotificationJob::forPayment($payment)->afterCommit();

            return ['invoice' => $lockedInvoice, 'payment' => $payment];
        });
    }
}
