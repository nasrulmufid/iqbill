@extends('layouts.app', ['title' => 'Invoices'])

@section('content')
<div class="space-y-6">
    <x-page-header title="Invoices" subtitle="Manage billing cycle invoices, copy Smart Payment Links, and track QRIS or Cash settlements.">
        <x-slot:actions>
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search invoice # or customer..." class="rounded-xl border-slate-300 text-sm">
                <select name="status" class="rounded-xl border-slate-300 text-sm">
                    <option value="">All Statuses</option>
                    @foreach(['unpaid', 'paid', 'overdue', 'cancelled'] as $opt)
                        <option value="{{ $opt }}" @selected($status === $opt)>{{ strtoupper($opt) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if($invoices->isEmpty())
        <x-empty-state title="No invoices yet" description="Invoices are issued automatically 7 days before due date or when a subscription is activated." actionLabel="Open Subscriptions" :actionHref="route('admin.subscriptions.index')" />
    @else
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Invoice</th>
                            <th class="px-6 py-3">Customer</th>
                            <th class="px-6 py-3">Package</th>
                            <th class="px-6 py-3">Total</th>
                            <th class="px-6 py-3">Due Date</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Smart Payment Link</th>
                            <th class="px-6 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($invoices as $inv)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="px-6 py-3.5 font-mono font-semibold text-slate-900">{{ $inv->invoice_number }}</td>
                                <td class="px-6 py-3.5">{{ $inv->customer->full_name }}</td>
                                <td class="px-6 py-3.5 text-xs text-slate-600">{{ $inv->subscription->package->name ?? '-' }}</td>
                                <td class="px-6 py-3.5 font-semibold whitespace-nowrap">Rp {{ number_format((float) $inv->total_amount, 0, ',', '.') }}</td>
                                <td class="px-6 py-3.5 whitespace-nowrap">{{ $inv->due_date->format('d M Y') }}</td>
                                <td class="px-6 py-3.5"><x-status-badge :status="$inv->status" /></td>
                                <td class="px-6 py-3.5">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ route('public.pay', $inv->public_token) }}'); window.dispatchEvent(new CustomEvent('notify-copy'));"
                                            class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">
                                        Copy Link
                                    </button>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <a href="{{ route('admin.invoices.show', $inv) }}" class="text-indigo-600 font-semibold hover:underline">Details</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $invoices->links() }}
    @endif
</div>
@endsection
