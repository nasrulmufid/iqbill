<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PortalInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()?->customer;
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $invoices = $customer
            ? Invoice::query()
                ->with(['subscription.package', 'payments'])
                ->where('customer_id', $customer->id)
                ->when($status !== '' && in_array($status, ['unpaid', 'overdue', 'paid', 'cancelled'], true), fn ($q) => $q->where('status', $status))
                ->when($search !== '', fn ($q) => $q->where('invoice_number', 'like', "%{$search}%"))
                ->latest('id')
                ->paginate(12)
                ->withQueryString()
            : collect();

        $unpaidAmount = $customer
            ? (float) Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
                ->sum('total_amount')
            : 0.0;

        $paidAmount = $customer
            ? (float) Invoice::query()
                ->where('customer_id', $customer->id)
                ->where('status', InvoiceStatus::Paid->value)
                ->sum('total_amount')
            : 0.0;

        $totalInvoices = $customer
            ? Invoice::query()->where('customer_id', $customer->id)->count()
            : 0;

        return view('portal.invoices.index', [
            'customer' => $customer,
            'invoices' => $invoices,
            'status' => $status,
            'search' => $search,
            'unpaidAmount' => $unpaidAmount,
            'paidAmount' => $paidAmount,
            'totalInvoices' => $totalInvoices,
        ]);
    }

    public function download(Invoice $invoice, InvoicePdfService $pdfService): Response
    {
        Gate::authorize('view', $invoice);

        $pdf = $pdfService->render($invoice);
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->invoice_number) ?: 'invoice';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"Invoice-{$safeNumber}.pdf\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function print(Invoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['customer', 'subscription.package', 'subscription.mikrotikRouter', 'payments' => fn ($q) => $q->latest('id')]);

        return view('portal.invoices.print', [
            'invoice' => $invoice,
            'company' => CompanySetting::current(),
        ]);
    }
}
