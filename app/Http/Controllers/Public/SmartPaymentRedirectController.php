<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\InvoiceStatus;
use App\Exceptions\SumopodException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class SmartPaymentRedirectController extends Controller
{
    public function __construct(
        private readonly SumopodPaymentSessionService $sessionService
    ) {
    }

    public function __invoke(string $public_token): RedirectResponse|Response
    {
        $invoice = Invoice::query()
            ->with(['customer', 'subscription.package'])
            ->where('public_token', $public_token)
            ->first();

        if ($invoice === null || $invoice->status === InvoiceStatus::Cancelled) {
            abort(404);
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            return response()
                ->view('public.payment-paid', [
                    'invoice' => $invoice,
                    'maskedName' => $invoice->customer->maskedName(),
                ])
                ->header('Cache-Control', 'no-store, private');
        }

        try {
            $payment = $this->sessionService->getOrCreateActiveSession($invoice);
            $paymentUrl = (string) $payment->payment_url;

            if (!preg_match('#^https?://#i', $paymentUrl)) {
                throw new SumopodException('Invalid payment redirect URL received from gateway.');
            }

            return redirect()
                ->away($paymentUrl, 302)
                ->header('Cache-Control', 'no-store, private');
        } catch (SumopodException $e) {
            return response()
                ->view('public.payment-fallback', [
                    'invoice' => $invoice,
                    'errorMessage' => $e->getMessage(),
                ])
                ->header('Cache-Control', 'no-store, private');
        } catch (LockTimeoutException) {
            return response()
                ->view('public.payment-fallback', [
                    'invoice' => $invoice,
                    'errorMessage' => 'Payment session is currently being prepared. Please refresh in a few seconds.',
                ], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
