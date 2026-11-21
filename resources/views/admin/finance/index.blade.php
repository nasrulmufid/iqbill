@extends('layouts.app', ['title' => 'Financial Reports'])

@section('content')
<div class="space-y-6">
    <x-page-header title="Financial Reports & Aggregation" subtitle="Reconcile gross revenue, Sumopod QRIS gateway fees, manual cash receipts, and net earnings.">
        <x-slot:actions>
            <a href="{{ route('admin.finance.export', ['from' => $from, 'to' => $to]) }}"
               class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                Export Excel (.xlsx)
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap items-end gap-3 shadow-sm">
        <div>
            <label class="block text-xs text-slate-500">From Date</label>
            <input type="date" name="from" value="{{ $from }}" class="mt-1 rounded-xl border-slate-300 text-sm">
        </div>
        <div>
            <label class="block text-xs text-slate-500">To Date</label>
            <input type="date" name="to" value="{{ $to }}" class="mt-1 rounded-xl border-slate-300 text-sm">
        </div>
        <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Apply Filter</button>
        @if($from || $to)
            <a href="{{ route('admin.finance.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600">Reset</a>
        @endif
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="text-xs text-slate-500 uppercase">Paid Transactions</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ $totals->tx_count }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="text-xs text-slate-500 uppercase">Gross Revenue</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">Rp {{ number_format((float) $totals->gross_total, 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="text-xs text-slate-500 uppercase">Gateway Fees</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">Rp {{ number_format((float) $totals->fee_total, 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="text-xs text-slate-500 uppercase">Net Revenue</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">Rp {{ number_format((float) $totals->net_total, 0, ',', '.') }}</div>
        </div>
    </div>

    @if($byGateway->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($byGateway as $gw)
                <div class="bg-white rounded-2xl border border-slate-200 p-5 flex items-center justify-between shadow-sm">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $gw->gateway === 'manual_cash' ? 'Manual Cash (Counter)' : 'Sumopod QRIS' }}</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">Rp {{ number_format((float) $gw->net, 0, ',', '.') }}</div>
                        <div class="text-xs text-slate-500">{{ $gw->tx_count }} transaction(s) &middot; Gross Rp {{ number_format((float) $gw->gross, 0, ',', '.') }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($payments->isEmpty())
        <x-empty-state title="No paid transactions yet" description="QRIS and Manual Cash payments will appear here automatically." />
    @else
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Paid Date</th>
                            <th class="px-6 py-3">Payment Code</th>
                            <th class="px-6 py-3">Customer</th>
                            <th class="px-6 py-3">Channel</th>
                            <th class="px-6 py-3">Gross</th>
                            <th class="px-6 py-3">Fee</th>
                            <th class="px-6 py-3">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($payments as $p)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">{{ $p->paid_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</td>
                                <td class="px-6 py-3 font-mono text-xs">{{ $p->payment_code }}</td>
                                <td class="px-6 py-3">{{ $p->invoice->customer->full_name ?? '-' }}</td>
                                <td class="px-6 py-3 text-xs font-semibold">{{ $p->gateway }}</td>
                                <td class="px-6 py-3">Rp {{ number_format((float) $p->amount, 0, ',', '.') }}</td>
                                <td class="px-6 py-3 text-rose-600">Rp {{ number_format((float) $p->gateway_fee, 0, ',', '.') }}</td>
                                <td class="px-6 py-3 font-semibold text-emerald-600">Rp {{ number_format((float) $p->net_amount, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $payments->links() }}
    @endif
</div>
@endsection
