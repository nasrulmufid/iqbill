<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\SumopodException;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class InvoicePaymentComponent extends Component
{
    public Invoice $invoice;

    public ?Payment $activePayment = null;

    public ?string $errorMessage = null;

    public function mount(Invoice $invoice, SumopodPaymentSessionService $sessionService): void
    {
        Gate::authorize('view', $invoice);

        $this->invoice = $invoice->load(['customer', 'subscription.package', 'payments' => fn ($q) => $q->latest('id')]);

        if (in_array($this->invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
            if (Gate::allows('pay', $this->invoice)) {
                $this->loadOrCreateSession($sessionService);
            } else {
                $this->activePayment = $this->invoice->payments->firstWhere('status', PaymentStatus::Pending);
            }
        } elseif ($this->invoice->status === InvoiceStatus::Paid) {
            $this->activePayment = $this->invoice->payments->firstWhere('status', PaymentStatus::Paid);
        }
    }

    public function renewSession(SumopodPaymentSessionService $sessionService): void
    {
        Gate::authorize('pay', $this->invoice);
        $this->invoice->refresh();

        if (!in_array($this->invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
            return;
        }

        $rateKey = 'renew-session:' . (string) auth()->id() . ':' . $this->invoice->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->errorMessage = 'Too many payment session renewal requests. Please wait a minute and try again.';

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $this->loadOrCreateSession($sessionService);
    }

    public function checkLocalStatus(): void
    {
        Gate::authorize('view', $this->invoice);
        $this->invoice->refresh()->load(['subscription.package', 'customer', 'payments' => fn ($q) => $q->latest('id')]);

        if ($this->invoice->status === InvoiceStatus::Paid) {
            $this->activePayment = $this->invoice->payments->firstWhere('status', PaymentStatus::Paid);
        }
    }

    private function loadOrCreateSession(SumopodPaymentSessionService $sessionService): void
    {
        $this->errorMessage = null;

        try {
            $this->activePayment = $sessionService->getOrCreateActiveSession($this->invoice);
        } catch (SumopodException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (LockTimeoutException) {
            $this->errorMessage = 'Payment session is currently being prepared. Please retry in a few seconds.';
        }
    }

    public function render(): View
    {
        $qrSvg = null;
        if (
            $this->invoice->status !== InvoiceStatus::Paid
            && $this->activePayment !== null
            && !empty($this->activePayment->qris_string)
        ) {
            $qrSvg = (string) QrCode::format('svg')->size(220)->margin(1)->generate($this->activePayment->qris_string);
        }

        return view('livewire.portal.invoice-payment-component', [
            'qrSvg' => $qrSvg,
            'company' => CompanySetting::current(),
        ])->layout('layouts.app', ['title' => 'Invoice ' . $this->invoice->invoice_number]);
    }
}
