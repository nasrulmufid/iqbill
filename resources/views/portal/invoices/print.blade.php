<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }} — {{ $company->company_name ?: 'IQ Bill' }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .invoice-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased py-8 px-4">
@php
    $paidPayment = $invoice->payments->firstWhere('status.value', 'paid') ?? $invoice->payments->first();
    $isPaid = $invoice->status === \App\Enums\InvoiceStatus::Paid;
@endphp

<div class="max-w-3xl mx-auto space-y-4">
    {{-- Action Toolbar --}}
    <div class="no-print flex flex-wrap items-center justify-between gap-3 bg-white rounded-2xl border border-slate-200 px-5 py-3.5 shadow-sm">
        <a href="{{ route('portal.invoices.show', $invoice) }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900">
            &larr; Back to Invoice Page
        </a>
        <div class="flex items-center gap-2">
            <a href="{{ route('portal.invoices.download', $invoice) }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                <svg class="h-4 w-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Download PDF
            </a>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                Print Invoice
            </button>
        </div>
    </div>

    {{-- A4 Sheet --}}
    <div class="invoice-sheet bg-white rounded-2xl border border-slate-200 shadow-lg overflow-hidden">
        <div class="bg-slate-900 text-white px-8 py-7 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <img src="{{ asset('images/iqbill-logo-192.png') }}"
                     alt="{{ $company->company_name ?: 'IQ Bill' }}"
                     width="48"
                     height="48"
                     class="h-12 w-12 shrink-0 rounded-full object-contain ring-1 ring-white/20">
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $company->company_name ?: 'IQ Bill' }}</h1>
                    @if($company->company_address)
                        <p class="text-xs text-slate-300 mt-1">{{ $company->company_address }}</p>
                    @endif
                    @if($company->company_phone)
                        <p class="text-xs text-slate-400 font-mono mt-0.5">Phone / WA: {{ $company->company_phone }}</p>
                    @endif
                </div>
            </div>
            <div class="sm:text-right">
                <div class="text-2xl font-extrabold tracking-wide">INVOICE</div>
                <div class="font-mono text-sm text-indigo-200 mt-0.5">{{ $invoice->invoice_number }}</div>
                <span class="mt-2 inline-flex items-center rounded-full px-3 py-0.5 text-xs font-bold uppercase
                    {{ $isPaid ? 'bg-emerald-500 text-white' : ($invoice->status->value === 'overdue' ? 'bg-rose-500 text-white' : 'bg-amber-400 text-slate-950') }}">
                    {{ $isPaid ? 'PAID' : ($invoice->status->value === 'overdue' ? 'OVERDUE' : 'UNPAID') }}
                </span>
            </div>
        </div>

        <div class="p-8 space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="rounded-xl bg-slate-50 border border-slate-200/80 p-4 space-y-1 text-xs">
                    <div class="font-bold uppercase tracking-wider text-slate-400">Billed To</div>
                    <div class="text-sm font-bold text-slate-900 pt-1">{{ $invoice->customer->full_name }}</div>
                    <div class="font-mono text-slate-600">Customer ID: {{ $invoice->customer->customer_code }}</div>
                    <div class="text-slate-600">WhatsApp: {{ $invoice->customer->phone_whatsapp }}</div>
                    <div class="text-slate-600">{{ $invoice->customer->installation_address }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-slate-200/80 p-4 space-y-1.5 text-xs">
                    <div class="font-bold uppercase tracking-wider text-slate-400">Invoice Details</div>
                    <div class="flex justify-between pt-1">
                        <span class="text-slate-500">Issue Date</span>
                        <span class="font-semibold text-slate-900">{{ $invoice->created_at?->timezone('Asia/Jakarta')->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Due Date</span>
                        <span class="font-semibold text-slate-900">{{ $invoice->due_date->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Service Period</span>
                        <span class="font-semibold text-slate-900">{{ $invoice->billing_period_start->format('d M Y') }} – {{ $invoice->billing_period_end->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">PPPoE Account</span>
                        <span class="font-mono font-semibold text-slate-900">{{ $invoice->subscription?->service_username ?? '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-5 py-3">Service Description</th>
                            <th class="px-5 py-3">Notes</th>
                            <th class="px-5 py-3 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr>
                            <td class="px-5 py-3.5 font-semibold text-slate-900">
                                {{ $invoice->subscription?->package?->name ?? 'Internet Service' }}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-600">
                                {{ $invoice->billing_period_start->format('d M Y') }} – {{ $invoice->billing_period_end->format('d M Y') }}
                            </td>
                            <td class="px-5 py-3.5 text-right font-mono font-medium">
                                Rp {{ number_format((float) $invoice->subtotal, 0, ',', '.') }}
                            </td>
                        </tr>
                        @if((float) $invoice->tax_amount > 0)
                            <tr>
                                <td class="px-5 py-3 text-slate-700">VAT / Tax</td>
                                <td class="px-5 py-3 text-xs text-slate-500">Value Added Tax</td>
                                <td class="px-5 py-3 text-right font-mono">Rp {{ number_format((float) $invoice->tax_amount, 0, ',', '.') }}</td>
                            </tr>
                        @endif
                        @if((float) $invoice->gateway_fee_charged > 0)
                            <tr>
                                <td class="px-5 py-3 text-slate-700">Gateway Service Fee</td>
                                <td class="px-5 py-3 text-xs text-slate-500">QRIS / Payment Gateway</td>
                                <td class="px-5 py-3 text-right font-mono">Rp {{ number_format((float) $invoice->gateway_fee_charged, 0, ',', '.') }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot class="bg-indigo-50/70 border-t border-indigo-200">
                        <tr>
                            <td colspan="2" class="px-5 py-4 text-right text-sm font-bold text-indigo-950">Total Amount</td>
                            <td class="px-5 py-4 text-right font-mono text-base font-extrabold text-indigo-700">
                                Rp {{ number_format((float) $invoice->total_amount, 0, ',', '.') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if($isPaid)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-900 space-y-1">
                    <div class="font-bold text-sm text-emerald-800">Official Payment Receipt (PAID)</div>
                    <div>Paid on: <strong>{{ $invoice->paid_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong></div>
                    @if($paidPayment)
                        <div>Payment Code: <span class="font-mono font-semibold">{{ $paidPayment->payment_code }}</span> ({{ strtoupper($paidPayment->gateway) }})</div>
                    @endif
                </div>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900 space-y-1">
                    <div class="font-bold text-amber-800">Instant Payment Link</div>
                    <div>You can pay this invoice via QRIS using the link below:</div>
                    <div class="font-mono font-semibold text-indigo-700 break-all">{{ route('public.pay', $invoice->public_token) }}</div>
                </div>
            @endif

            <div class="border-t border-slate-200 pt-4 text-[11px] text-slate-400 flex flex-wrap justify-between gap-2">
                <span>Official electronic document — valid without a wet signature.</span>
                <span>Printed on {{ now('Asia/Jakarta')->format('d M Y H:i') }} WIB</span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
