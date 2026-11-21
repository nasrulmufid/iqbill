@extends('layouts.app', ['title' => 'Invoice ' . $invoice->invoice_number])

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 font-mono">{{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-slate-500">{{ $invoice->customer->full_name }} ({{ $invoice->customer->phone_whatsapp }})</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button"
                    onclick="navigator.clipboard.writeText('{{ route('public.pay', $invoice->public_token) }}'); window.dispatchEvent(new CustomEvent('notify-copy'));"
                    class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                Copy Smart Payment Link
            </button>

            @if($invoice->status->value !== 'paid' && $invoice->status->value !== 'cancelled' && auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Finance))
                <form method="POST" action="{{ route('admin.invoices.manual-payment', $invoice) }}"
                      onsubmit="return confirm('Record manual cash payment and extend active validity?')">
                    @csrf
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        Receive Cash
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div>
            <div class="text-slate-500">Status</div>
            <div class="mt-1 font-bold uppercase">{{ $invoice->status->value }}</div>
        </div>
        <div>
            <div class="text-slate-500">Total Amount</div>
            <div class="mt-1 font-bold">Rp {{ number_format((float) $invoice->total_amount, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-slate-500">Due Date</div>
            <div class="mt-1 font-medium">{{ $invoice->due_date->format('d M Y') }}</div>
        </div>
        <div>
            <div class="text-slate-500">Payment Attempts</div>
            <div class="mt-1 font-medium">{{ $invoice->payment_attempt_count }} attempt(s)</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 font-semibold text-slate-900">Payment Session History</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Payment Code</th>
                        <th class="px-6 py-3">External Ref</th>
                        <th class="px-6 py-3">Channel</th>
                        <th class="px-6 py-3">Gross</th>
                        <th class="px-6 py-3">Fee</th>
                        <th class="px-6 py-3">Net</th>
                        <th class="px-6 py-3">Expires At</th>
                        <th class="px-6 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($invoice->payments as $payment)
                        <tr>
                            <td class="px-6 py-3 font-mono font-medium">{{ $payment->payment_code }}</td>
                            <td class="px-6 py-3 font-mono text-xs">{{ $payment->external_reference ?? '-' }}</td>
                            <td class="px-6 py-3">{{ $payment->gateway }}</td>
                            <td class="px-6 py-3">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                            <td class="px-6 py-3">Rp {{ number_format((float) $payment->gateway_fee, 0, ',', '.') }}</td>
                            <td class="px-6 py-3 font-semibold">Rp {{ number_format((float) $payment->net_amount, 0, ',', '.') }}</td>
                            <td class="px-6 py-3">{{ $payment->qris_expires_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</td>
                            <td class="px-6 py-3 uppercase font-semibold">{{ $payment->status->value }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-6 text-center text-slate-500">No payment sessions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
