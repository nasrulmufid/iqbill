<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\ManualCashExtensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $invoices = Invoice::query()
            ->with(['customer', 'subscription.package'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load([
            'customer',
            'subscription.package',
            'subscription.mikrotikRouter',
            'payments' => fn ($q) => $q->orderBy('attempt_number'),
        ]);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
        ]);
    }

    public function manualPayment(
        Request $request,
        Invoice $invoice,
        ManualCashExtensionService $cashService
    ): RedirectResponse {
        if ($invoice->status === InvoiceStatus::Paid || $invoice->status === InvoiceStatus::Cancelled) {
            return back()->with('error', 'Invoice cannot be marked as paid.');
        }

        $cashService->receiveCash($invoice->subscription, $invoice, $request->user(), $request->ip());

        return back()->with('success', 'Cash payment recorded and service validity extended.');
    }
}
