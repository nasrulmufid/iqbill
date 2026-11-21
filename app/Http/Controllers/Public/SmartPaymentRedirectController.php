<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\InvoiceStatus;
use App\Exceptions\SumopodException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmartPaymentRedirectController extends Controller
{
    public function __construct(
        private readonly SumopodPaymentSessionService $sessionService
    ) {
    }

    public function __invoke(string $public_token): RedirectResponse|View
    {
        $invoice = Invoice::query()
            ->with(['customer', 'subscription.package'])
            ->where('public_token', $public_token)
            ->first();

        if ($invoice === null || $invoice->status === InvoiceStatus::Cancelled) {
            abort(404);
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            return view('public.payment-paid', [
                'invoice' => $invoice,
                'maskedName' => $invoice->customer->maskedName(),
            ]);
        }

        try {
            $payment = $this->sessionService->getOrCreateActiveSession($invoice);

            return redirect()->away((string) $payment->payment_url, 302);
        } catch (SumopodException $e) {
            return view('public.payment-fallback', [
                'invoice' => $invoice,
                'errorMessage' => $e->getMessage(),
            ]);
        }
    }
}
