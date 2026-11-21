<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SumopodPaymentSessionService
{
    public function __construct(
        private readonly SumopodGatewayService $gateway
    ) {
    }

    public function getOrCreateActiveSession(Invoice $invoice): Payment
    {
        $active = $this->findActiveSession($invoice);
        if ($active !== null) {
            return $active;
        }

        return Cache::lock("reissue_payment:{$invoice->id}", 10)
            ->block(5, function () use ($invoice): Payment {
                $invoice->refresh();

                $active = $this->findActiveSession($invoice);
                if ($active !== null) {
                    return $active;
                }

                $nextAttempt = $invoice->payment_attempt_count + 1;
                $paymentCode = $this->buildPaymentCode($invoice, $nextAttempt);
                $response = $this->gateway->createQrisPayment($paymentCode, $invoice);

                return DB::transaction(function () use ($invoice, $nextAttempt, $paymentCode, $response): Payment {
                    Payment::query()
                        ->where('invoice_id', $invoice->id)
                        ->where('status', PaymentStatus::Pending->value)
                        ->update(['status' => PaymentStatus::Expired->value]);

                    $invoice->forceFill([
                        'payment_attempt_count' => $nextAttempt,
                    ])->save();

                    $amount = (float) $invoice->total_amount;
                    $fee = isset($response['fee']) ? (float) $response['fee'] : 0.0;
                    $netAmount = isset($response['net_amount'])
                        ? (float) $response['net_amount']
                        : max(0.0, $amount - $fee);

                    return Payment::query()->create([
                        'invoice_id' => $invoice->id,
                        'attempt_number' => $nextAttempt,
                        'payment_code' => $paymentCode,
                        'external_reference' => (string) $response['payment_id'],
                        'gateway' => 'sumopod_qris',
                        'amount' => $amount,
                        'gateway_fee' => $fee,
                        'net_amount' => $netAmount,
                        'qris_string' => (string) $response['payment_code'],
                        'payment_url' => (string) $response['payment_link_url'],
                        'qris_expires_at' => Carbon::parse((string) $response['expires_at'])->timezone('Asia/Jakarta'),
                        'status' => PaymentStatus::Pending,
                    ]);
                });
            });
    }

    private function findActiveSession(Invoice $invoice): ?Payment
    {
        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('qris_expires_at', '>', now()->addMinutes(2))
            ->latest('id')
            ->first();
    }

    private function buildPaymentCode(Invoice $invoice, int $attemptNumber): string
    {
        $settings = CompanySetting::current();
        $prefix = $settings->payment_prefix ?: 'PAY';
        $attemptSuffix = str_pad((string) $attemptNumber, 2, '0', STR_PAD_LEFT);

        if (preg_match('/^[A-Z]+-(\d{6}-\d+)$/', $invoice->invoice_number, $matches) === 1) {
            return "{$prefix}-{$matches[1]}-{$attemptSuffix}";
        }

        $period = ($invoice->created_at ?? now())->format('Ym');
        $seq = str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);

        return "{$prefix}-{$period}-{$seq}-{$attemptSuffix}";
    }
}
