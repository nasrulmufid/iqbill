@extends('layouts.app', ['title' => 'My Billing & Invoices'])

@section('content')
<div class="space-y-6">
    <x-page-header title="My Billing & Invoices" subtitle="Manage all your subscription invoices, download official PDFs, or pay instantly via QRIS.">
        <x-slot:actions>
            <a href="{{ route('portal.dashboard') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                &larr; Back to Dashboard
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Summary Strip --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">Unpaid Balance</div>
            <div class="mt-1.5 text-xl font-bold {{ $unpaidAmount > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                Rp {{ number_format($unpaidAmount, 0, ',', '.') }}
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Paid</div>
            <div class="mt-1.5 text-xl font-bold text-emerald-600">
                Rp {{ number_format($paidAmount, 0, ',', '.') }}
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Recorded Invoices</div>
            <div class="mt-1.5 text-xl font-bold text-slate-900">{{ $totalInvoices }} Invoices</div>
        </div>
    </div>

    {{-- Filter & Search --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach(['' => 'All', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $opt => $label)
                <a href="{{ route('portal.invoices.index', array_filter(['status' => $opt, 'search' => $search])) }}"
                   class="rounded-xl px-3.5 py-1.5 text-xs font-semibold transition {{ $status === $opt ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('portal.invoices.index') }}" class="flex items-center gap-2">
            @if($status !== '')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <input type="text" name="search" value="{{ $search }}" placeholder="Search invoice no..."
                   class="w-full sm:w-56 rounded-xl border-slate-300 text-xs font-mono">
            <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition">
                Search
            </button>
        </form>
    </div>

    {{-- Table --}}
    @if($invoices->isEmpty())
        <x-empty-state title="No invoices found" description="No invoices match your current filter or search criteria." />
    @else
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Invoice No.</th>
                            <th class="px-6 py-3">Package</th>
                            <th class="px-6 py-3">Service Period</th>
                            <th class="px-6 py-3">Due Date</th>
                            <th class="px-6 py-3">Total</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($invoices as $inv)
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="px-6 py-3.5 font-mono font-semibold text-slate-900 whitespace-nowrap">{{ $inv->invoice_number }}</td>
                                <td class="px-6 py-3.5 text-slate-700">{{ $inv->subscription?->package?->name ?? '-' }}</td>
                                <td class="px-6 py-3.5 text-xs text-slate-600 whitespace-nowrap">
                                    {{ $inv->billing_period_start->format('d M Y') }} – {{ $inv->billing_period_end->format('d M Y') }}
                                </td>
                                <td class="px-6 py-3.5 text-xs text-slate-600 whitespace-nowrap">{{ $inv->due_date->format('d M Y') }}</td>
                                <td class="px-6 py-3.5 font-semibold text-slate-900 whitespace-nowrap">Rp {{ number_format((float) $inv->total_amount, 0, ',', '.') }}</td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <x-status-badge :status="$inv->status" />
                                </td>
                                <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        <a href="{{ route('portal.invoices.download', $inv) }}"
                                           class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                            <svg class="h-3.5 w-3.5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                            </svg>
                                            Download PDF
                                        </a>
                                        <a href="{{ route('portal.invoices.print', $inv) }}" target="_blank" rel="noopener"
                                           class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                            Print
                                        </a>
                                        <a href="{{ route('portal.invoices.show', $inv) }}"
                                           class="rounded-lg {{ in_array($inv->status->value, ['unpaid', 'overdue'], true) ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' }} px-3 py-1 text-xs font-semibold transition">
                                            {{ in_array($inv->status->value, ['unpaid', 'overdue'], true) ? 'Pay' : 'Details' }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(method_exists($invoices, 'links'))
                <div class="px-6 py-4 border-t border-slate-100">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
