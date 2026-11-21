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
        $eventType = (string) ($payload['event_type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $externalRef = isset($data['payment_id']) ? (string) $data['payment_id'] : null;
        $orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;

        if (!empty($svixId) && PaymentWebhookLog::query()->where('svix_id', $svixId)->exists()) {
            return [
                'http_status' => 200,
                'body' => ['received' => true, 'status' => 'duplicate_ignored'],
            ];
        }

        if ($eventType === '' || ($externalRef === null && $orderId === null)) {
            $this->recordLog(
                $request,
                $svixId,
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
            $payment = Payment::query()
                ->when($externalRef !== null, fn ($q) => $q->where('external_reference', $externalRef))
                ->when($externalRef === null && $orderId !== null, fn ($q) => $q->where('payment_code', $orderId))
                ->when($externalRef !== null && $orderId !== null, fn ($q) => $q->orWhere('payment_code', $orderId))
                ->lockForUpdate()
                ->first();

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

            $incomingAmount = (int) ($data['amount'] ?? -1);
            $expectedAmount = (int) round((float) $payment->amount);

            if ($incomingAmount !== $expectedAmount) {
                return [
                    'outcome' => 'amount_mismatch',
                    'error' => "Amount mismatch: expected {$expectedAmount}, got {$incomingAmount}.",
                ];
            }

            $wasExpired = $payment->status === PaymentStatus::Expired;
            $fee = isset($data['fee']) ? (float) $data['fee'] : (float) $payment->gateway_fee;
            $netAmount = isset($data['net_amount'])
                ? (float) $data['net_amount']
                : max(0.0, (float) $payment->amount - $fee);

            $paidAt = !empty($data['paid_at'])
                ? Carbon::parse((string) $data['paid_at'])->timezone('Asia/Jakarta')
                : now();
            $settledAt = !empty($data['settled_at'])
                ? Carbon::parse((string) $data['settled_at'])->timezone('Asia/Jakarta')
                : now();

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
            $this->recordLog($request, $svixId, $externalRef, $orderId, 'payment.completed', $verificationMethod, 422, 'failed', $payload, 'Matching payment not found.');

            return [
                'http_status' => 422,
                'body' => ['received' => false, 'error' => 'payment_not_found'],
            ];
        }

        if ($result['outcome'] === 'amount_mismatch') {
            $this->recordLog($request, $svixId, $externalRef, $orderId, 'payment.completed', $verificationMethod, 422, 'failed', $payload, $result['error']);

            return [
                'http_status' => 422,
                'body' => ['received' => false, 'error' => 'amount_mismatch'],
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

        $payment = Payment::query()
            ->when($externalRef !== null, fn ($q) => $q->where('external_reference', $externalRef))
            ->when($externalRef === null && $orderId !== null, fn ($q) => $q->where('payment_code', $orderId))
            ->first();

        if ($payment !== null && $payment->status === PaymentStatus::Pending) {
            $payment->forceFill(['status' => $targetStatus])->save();
        }

        $this->recordLog($request, $svixId, $externalRef, $orderId, $eventType, $verificationMethod, 200, 'processed', $payload);

        return [
            'http_status' => 200,
            'body' => ['received' => true, 'status' => 'processed'],
        ];
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
        PaymentWebhookLog::query()->create([
            'svix_id' => $svixId ?: null,
            'external_reference' => $externalRef,
            'payment_code' => $paymentCode,
            'event_type' => $eventType,
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
    }
}
