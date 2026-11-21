<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class InvoiceService
{
    public const FEE_PERCENT = 0.007;

    public const FEE_FLAT = 300;

    public function __construct(
        private readonly SumopodPaymentSessionService $sessionService
    ) {
    }

    /**
     * Sumopod QRIS gateway fee (0.7% + Rp 300) rounded up to the nearest integer.
     */
    public static function calculateGatewayFee(float $base): float
    {
        return (float) ceil($base * self::FEE_PERCENT + self::FEE_FLAT);
    }

    /**
     * Issue an invoice for the current subscription cycle (idempotent per billing cycle).
     * Returns null if an invoice already exists for the cycle.
     */
    public function issueForSubscription(Subscription $subscription, bool $notify = true): ?Invoice
    {
        $subscription->loadMissing(['customer', 'package']);
        $settings = CompanySetting::current();

        $periodStart = $subscription->next_billing_date->copy();
        $periodEnd = $periodStart->copy()->addMonthNoOverflow()->subDay();

        $exists = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('billing_period_start', $periodStart->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            $invoice = DB::transaction(function () use ($subscription, $settings, $periodStart, $periodEnd): Invoice {
                $subtotal = (float) $subscription->package->price;
                $taxAmount = round($subtotal * ((float) $subscription->package->tax_rate / 100), 2);
                $base = round($subtotal + $taxAmount, 2);

                $fee = $settings->sumopod_fee_bearer === 'customer'
                    ? self::calculateGatewayFee($base)
                    : 0.0;

                $dueDate = $subscription->isolir_day !== null
                    ? $this->resolveIsolirDayDate($periodStart, $subscription->isolir_day)
                    : $periodStart->copy()->addDays($settings->default_due_days);

                $invoice = Invoice::query()->create([
                    'invoice_number' => 'TMP-' . Str::uuid(),
                    'public_token' => Str::random(32),
                    'subscription_id' => $subscription->id,
                    'customer_id' => $subscription->customer_id,
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'gateway_fee_charged' => $fee,
                    'total_amount' => $base + $fee,
                    'payment_attempt_count' => 0,
                    'status' => InvoiceStatus::Unpaid,
                    'due_date' => $dueDate->toDateString(),
                ]);

                $invoice->forceFill([
                    'invoice_number' => sprintf(
                        '%s-%s-%06d',
                        $settings->invoice_prefix ?: 'INV',
                        $periodStart->format('Ym'),
                        $invoice->id
                    ),
                ])->save();

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent process already issued the invoice for this period.
            return null;
        }

        if ($settings->pregenerate_qris_on_invoice) {
            try {
                $this->sessionService->getOrCreateActiveSession($invoice);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($notify) {
            SendWhatsAppNotificationJob::dispatch($subscription->customer, 'invoice_created', $invoice);
        }

        return $invoice;
    }

    private function resolveIsolirDayDate(\Carbon\Carbon $periodStart, int $isolirDay): \Carbon\Carbon
    {
        $candidate = $periodStart->copy()->day(min($isolirDay, $periodStart->daysInMonth));
        if ($candidate->lessThan($periodStart)) {
            $nextMonth = $periodStart->copy()->addMonthNoOverflow();
            $candidate = $nextMonth->day(min($isolirDay, $nextMonth->daysInMonth));
        }

        return $candidate;
    }
}
