<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\SumopodException;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SumopodPaymentSessionService
{
    private const MAX_PAYMENT_ATTEMPTS = 50;

    public function __construct(
        private readonly SumopodGatewayService $gateway
    ) {
    }

    public function getOrCreateActiveSession(Invoice $invoice): Payment
    {
        if (!in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
            throw new SumopodException('Invoice is not payable.');
        }

        $active = $this->findActiveSession($invoice);
        if ($active !== null) {
            return $active;
        }

        return Cache::lock("reissue_payment:{$invoice->id}", 25)
            ->block(12, function () use ($invoice): Payment {
                $invoice->refresh();

                if (!in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
                    throw new SumopodException('Invoice is no longer payable.');
                }

                $active = $this->findActiveSession($invoice);
                if ($active !== null) {
                    return $active;
                }

                if ($invoice->payment_attempt_count >= self::MAX_PAYMENT_ATTEMPTS) {
                    throw new SumopodException('Maximum payment session attempts reached for this invoice. Please contact support.');
                }

                $nextAttempt = $invoice->payment_attempt_count + 1;
                $paymentCode = $this->buildPaymentCode($invoice, $nextAttempt);
                $response = $this->gateway->createQrisPayment($paymentCode, $invoice);

                try {
                    $expiresAt = Carbon::parse((string) ($response['expires_at'] ?? ''))->timezone('Asia/Jakarta');
                } catch (\Throwable) {
                    $expiresAt = now('Asia/Jakarta')->addHours(24);
                }

                return DB::transaction(function () use ($invoice, $nextAttempt, $paymentCode, $response, $expiresAt): Payment {
                    $lockedInvoice = Invoice::query()
                        ->whereKey($invoice->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (!in_array($lockedInvoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
                        throw new SumopodException('Invoice is no longer payable.');
                    }

                    Payment::query()
                        ->where('invoice_id', $lockedInvoice->id)
                        ->where('status', PaymentStatus::Pending->value)
                        ->where(function ($q): void {
                            $q->whereNull('qris_expires_at')
                                ->orWhere('qris_expires_at', '<=', now()->addMinutes(2));
                        })
                        ->update(['status' => PaymentStatus::Expired->value]);

                    $lockedInvoice->forceFill([
                        'payment_attempt_count' => $nextAttempt,
                    ])->save();

                    $invoiceAmount = (float) $lockedInvoice->total_amount;
                    $expectedInvoiceInt = (int) round($invoiceAmount);
                    $fee = isset($response['fee']) && is_numeric($response['fee'])
                        ? max(0.0, (float) $response['fee'])
                        : 0.0;

                    $responseAmount = isset($response['amount']) && is_numeric($response['amount'])
                        ? (float) $response['amount']
                        : $invoiceAmount;
                    $responseNetAmount = isset($response['net_amount']) && is_numeric($response['net_amount'])
                        ? (float) $response['net_amount']
                        : null;

                    // When Sumopod passes gateway fees to the customer, Sumopod charges
                    // amount = invoice_total + fee and settles net_amount = invoice_total.
                    $isGatewayFeeAddedOnTop = $responseNetAmount !== null
                        && $fee > 0.0
                        && (int) round($responseNetAmount) === $expectedInvoiceInt
                        && (int) round($responseAmount) === (int) round($responseNetAmount + $fee);

                    $amount = $isGatewayFeeAddedOnTop ? $responseAmount : $invoiceAmount;
                    $netAmount = $responseNetAmount ?? max(0.0, $amount - $fee);

                    return Payment::query()->create([
                        'invoice_id' => $lockedInvoice->id,
                        'attempt_number' => $nextAttempt,
                        'payment_code' => $paymentCode,
                        'external_reference' => (string) $response['payment_id'],
                        'gateway' => 'sumopod_qris',
                        'amount' => $amount,
                        'gateway_fee' => $fee,
                        'net_amount' => $netAmount,
                        'qris_string' => !empty($response['payment_code']) ? (string) $response['payment_code'] : null,
                        'payment_url' => (string) $response['payment_link_url'],
                        'qris_expires_at' => $expiresAt,
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
