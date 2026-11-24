<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendTelegramNotificationJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Services\Mikrotik\MikrotikProvisioningService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentSettlementService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function handle(Request $request, array $payload, string $verificationMethod): array
    {
        $svixId = $request->header('svix-id');
        $eventType = is_scalar($payload['event_type'] ?? null) ? trim((string) $payload['event_type']) : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $externalRef = is_scalar($data['payment_id'] ?? null) && trim((string) $data['payment_id']) !== ''
            ? trim((string) $data['payment_id'])
            : null;
        $orderId = is_scalar($data['order_id'] ?? null) && trim((string) $data['order_id']) !== ''
            ? trim((string) $data['order_id'])
            : null;

        if (
            !empty($svixId)
            && PaymentWebhookLog::query()
                ->where('svix_id', $svixId)
                ->whereIn('processing_status', ['processed', 'late_settled', 'duplicate_ignored'])
                ->exists()
        ) {
            return [
                'http_status' => 200,
                'body' => ['received' => true, 'status' => 'duplicate_ignored'],
            ];
        }

        if ($eventType === 'payment.test') {
            $this->recordLog(
                $request,
                $svixId,
                $externalRef,
                $orderId,
                $eventType,
                $verificationMethod,
                200,
                'processed',
                $payload
            );

            return [
                'http_status' => 200,
                'body' => ['received' => true, 'status' => 'test_ok'],
            ];
        }

        if ($eventType === '' || ($externalRef === null && $orderId === null)) {
            $this->recordLog(
                $request,
                null,
                $externalRef,
                $orderId,
                $eventType ?: 'unknown',
                $verificationMethod,
                422,
                'failed',
                $payload,
                'Missing event_type or payment identifier.'
            );

            return [
                'http_status' => 422,
                'body' => ['received' => false, 'error' => 'invalid_payload'],
            ];
        }

        if ($eventType === 'payment.completed') {
            return $this->handlePaymentCompleted(
                $request,
                $payload,
                $data,
                $svixId,
                $externalRef,
                $orderId,
                $verificationMethod
            );
        }

        if (in_array($eventType, ['payment.failed', 'payment.expired'], true)) {
            return $this->handleTerminalNonPaid(
                $request,
                $payload,
                $eventType,
                $svixId,
                $externalRef,
                $orderId,
                $verificationMethod
            );
        }

        $this->recordLog(
            $request,
            $svixId,
            $externalRef,
            $orderId,
            $eventType,
            $verificationMethod,
            200,
            'processed',
            $payload
        );

        return [
            'http_status' => 200,
            'body' => ['received' => true, 'status' => 'processed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function handlePaymentCompleted(
        Request $request,
        array $payload,
        array $data,
        ?string $svixId,
        ?string $externalRef,
        ?string $orderId,
        string $verificationMethod
    ): array {
        $result = DB::transaction(function () use ($data, $externalRef, $orderId): array {
            $paymentQuery = Payment::query()->where('gateway', 'sumopod_qris');

            if ($externalRef !== null && $orderId !== null) {
                $paymentQuery->where('external_reference', $externalRef)
                    ->where('payment_code', $orderId);
            } elseif ($externalRef !== null) {
                $paymentQuery->where('external_reference', $externalRef);
            } else {
                $paymentQuery->where('payment_code', $orderId);
            }

            $payment = $paymentQuery->lockForUpdate()->first();

            if ($payment === null) {
                return ['outcome' => 'not_found'];
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->with(['subscription', 'customer'])
                ->where('id', $payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status === InvoiceStatus::Paid || $payment->status === PaymentStatus::Paid) {
                return ['outcome' => 'duplicate'];
            }

            if (
                $invoice->status === InvoiceStatus::Cancelled
                || in_array($payment->status, [PaymentStatus::Cancelled, PaymentStatus::Failed], true)
            ) {
                return [
                    'outcome' => 'invalid_state',
                    'error' => "Cannot settle invoice in status '{$invoice->status->value}' or payment in status '{$payment->status->value}'.",
                ];
            }

            if (isset($data['currency']) && is_scalar($data['currency']) && strtoupper(trim((string) $data['currency'])) !== 'IDR') {
                return [
                    'outcome' => 'currency_mismatch',
                    'error' => 'Unsupported payment currency.',
                ];
            }

            $incomingAmount = is_numeric($data['amount'] ?? null) ? (int) round((float) $data['amount']) : -1;
            $expectedAmount = (int) round((float) $payment->amount);

            if ($incomingAmount !== $expectedAmount) {
                return [
                    'outcome' => 'amount_mismatch',
                    'error' => "Amount mismatch: expected {$expectedAmount}, got {$incomingAmount}.",
                ];
            }

            $wasExpired = $payment->status === PaymentStatus::Expired;
            $fee = isset($data['fee']) && is_numeric($data['fee']) ? (float) $data['fee'] : (float) $payment->gateway_fee;
            $netAmount = isset($data['net_amount']) && is_numeric($data['net_amount'])
                ? (float) $data['net_amount']
                : max(0.0, (float) $payment->amount - $fee);

            $paidAt = $this->parseTimestamp($data['paid_at'] ?? null);
            $settledAt = $this->parseTimestamp($data['settled_at'] ?? null);

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'gateway_fee' => $fee,
                'net_amount' => $netAmount,
                'paid_at' => $paidAt,
                'settled_at' => $settledAt,
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ])->save();

            // Cancel any other pending attempts on the same invoice (late webhook handling)
            Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('id', '!=', $payment->id)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);

            $subscription = $invoice->subscription;
            $needsRestore = in_array(
                $subscription->status,
                [SubscriptionStatus::Isolated, SubscriptionStatus::PendingInstallation],
                true
            );

            // Advance billing cycle: next cycle starts the day after this invoice period ends.
            $nextBilling = $invoice->billing_period_end->copy()->addDay();
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
                    'webhook_paid',
                    invoiceId: $invoice->id,
                    notifyRestored: true,
                    afterCommit: true
                );
            }

            SendWhatsAppNotificationJob::dispatch($invoice->customer, 'payment_receipt', $invoice)->afterCommit();
            SendTelegramNotificationJob::forPayment($payment)->afterCommit();

            return [
                'outcome' => $wasExpired ? 'late_settled' : 'processed',
            ];
        });

        if ($result['outcome'] === 'not_found') {
            $this->recordLog($request, null, $externalRef, $orderId, 'payment.completed', $verificationMethod, 422, 'failed', $payload, 'Matching payment not found.');

            return [
                'http_status' => 422,
                'body' => ['received' => false, 'error' => 'payment_not_found'],
            ];
        }

        if (in_array($result['outcome'], ['amount_mismatch', 'invalid_state', 'currency_mismatch'], true)) {
            $this->recordLog($request, null, $externalRef, $orderId, 'payment.completed', $verificationMethod, 422, 'failed', $payload, $result['error']);

            return [
                'http_status' => 422,
                'body' => ['received' => false, 'error' => $result['outcome']],
            ];
        }

        if ($result['outcome'] === 'duplicate') {
            $this->recordLog($request, $svixId, $externalRef, $orderId, 'payment.completed', $verificationMethod, 200, 'duplicate_ignored', $payload);

            return [
                'http_status' => 200,
                'body' => ['received' => true, 'status' => 'duplicate_ignored'],
            ];
        }

        $this->recordLog($request, $svixId, $externalRef, $orderId, 'payment.completed', $verificationMethod, 200, $result['outcome'], $payload);

        return [
            'http_status' => 200,
            'body' => ['received' => true, 'status' => 'processed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function handleTerminalNonPaid(
        Request $request,
        array $payload,
        string $eventType,
        ?string $svixId,
        ?string $externalRef,
        ?string $orderId,
        string $verificationMethod
    ): array {
        $targetStatus = $eventType === 'payment.failed'
            ? PaymentStatus::Failed
            : PaymentStatus::Expired;

        DB::transaction(function () use ($externalRef, $orderId, $targetStatus): void {
            $paymentQuery = Payment::query()->where('gateway', 'sumopod_qris');

            if ($externalRef !== null && $orderId !== null) {
                $paymentQuery->where('external_reference', $externalRef)
                    ->where('payment_code', $orderId);
            } elseif ($externalRef !== null) {
                $paymentQuery->where('external_reference', $externalRef);
            } else {
                $paymentQuery->where('payment_code', $orderId);
            }

            $payment = $paymentQuery->lockForUpdate()->first();

            if ($payment !== null && $payment->status === PaymentStatus::Pending) {
                $payment->forceFill(['status' => $targetStatus])->save();
            }
        });

        $this->recordLog($request, $svixId, $externalRef, $orderId, $eventType, $verificationMethod, 200, 'processed', $payload);

        return [
            'http_status' => 200,
            'body' => ['received' => true, 'status' => 'processed'],
        ];
    }

    private function parseTimestamp(mixed $value): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->timezone('Asia/Jakarta');
            } catch (\Throwable) {
                // Fall back to current server timestamp on malformed gateway date
            }
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordLog(
        Request $request,
        ?string $svixId,
        ?string $externalRef,
        ?string $paymentCode,
        string $eventType,
        string $verificationMethod,
        int $httpStatus,
        string $processingStatus,
        array $payload,
        ?string $errorMessage = null
    ): void {
        try {
            PaymentWebhookLog::query()->create([
                'svix_id' => $svixId ?: null,
                'external_reference' => $externalRef !== null ? mb_substr($externalRef, 0, 100) : null,
                'payment_code' => $paymentCode !== null ? mb_substr($paymentCode, 0, 60) : null,
                'event_type' => mb_substr($eventType, 0, 60),
                'verification_method' => $verificationMethod,
                'signature_valid' => true,
                'http_status_returned' => $httpStatus,
                'processing_status' => $processingStatus,
                'error_message' => $errorMessage,
                'headers_json' => [
                    'svix-id' => $request->header('svix-id'),
                    'svix-timestamp' => $request->header('svix-timestamp'),
                    'user-agent' => $request->userAgent(),
                ],
                'payload_json' => $payload,
                'processed_at' => now(),
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            // Concurrent duplicate svix_id insert is safe to ignore after idempotent processing
        }
    }
}
