<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Exceptions\SumopodException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PortalInvoiceApiController extends Controller
{
    public function session(Invoice $invoice, SumopodPaymentSessionService $sessionService): JsonResponse
    {
        Gate::authorize('pay', $invoice);

        if ($invoice->status === InvoiceStatus::Paid || $invoice->status === InvoiceStatus::Cancelled) {
            return response()->json(['message' => 'Invoice is not payable.'], 422);
        }

        try {
            $payment = $sessionService->getOrCreateActiveSession($invoice);
        } catch (SumopodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Payment session is currently being prepared. Please retry shortly.'], 503);
        }

        return response()->json([
            'payment_code' => $payment->payment_code,
            'amount' => (int) round((float) $payment->amount),
            'qris_string' => $payment->qris_string,
            'payment_url' => $payment->payment_url,
            'qris_expires_at' => $payment->qris_expires_at?->toIso8601String(),
        ]);
    }

    public function status(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);
        $invoice->load('subscription');

        return response()->json([
            'invoice_status' => $invoice->status->value,
            'paid_at' => $invoice->paid_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'service_status' => $invoice->subscription->status->value,
        ]);
    }
}
