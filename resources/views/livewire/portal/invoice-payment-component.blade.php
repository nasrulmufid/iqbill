<div wire:poll.4s="checkLocalStatus" class="space-y-6">
    @php
        $isPaid = $invoice->status === \App\Enums\InvoiceStatus::Paid;
        $isOverdue = $invoice->status === \App\Enums\InvoiceStatus::Overdue;
    @endphp

    {{-- Top Invoice Action Header --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('portal.invoices.index') }}" class="text-xs font-semibold text-slate-500 hover:text-indigo-600 transition">
                    &larr; All Invoices
                </a>
                <span class="text-slate-300">/</span>
                <span class="font-mono text-xs font-semibold text-indigo-600">{{ $invoice->invoice_number }}</span>
                <x-status-badge :status="$invoice->status" />
            </div>
            <h2 class="text-lg sm:text-xl font-bold text-slate-900">
                Invoice Details &amp; Payment
            </h2>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <a href="{{ route('portal.invoices.download', $invoice) }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                <svg class="h-4 w-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Download PDF
            </a>
            <a href="{{ route('portal.invoices.print', $invoice) }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print Invoice
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {{-- Left Column: Invoice Breakdown & History --}}
        <div class="lg:col-span-7 space-y-5">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-5">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $company->company_name ?: 'IQ Bill' }}</div>
                        <h3 class="mt-0.5 text-lg font-bold text-slate-900">Invoice Breakdown</h3>
                    </div>
                    <div class="text-right text-xs">
                        <div class="text-slate-400">Due Date</div>
                        <div class="font-semibold {{ $isOverdue ? 'text-rose-600' : 'text-slate-900' }}">
                            {{ $invoice->due_date->format('d M Y') }}
                        </div>
                    </div>
                </div>

                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Invoice Number</dt>
                        <dd class="font-mono font-semibold text-slate-900">{{ $invoice->invoice_number }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Customer</dt>
                        <dd class="font-medium text-slate-900 text-right">
                            {{ $invoice->customer->full_name }}
                            <span class="font-mono text-xs text-slate-400">({{ $invoice->customer->customer_code }})</span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Service Package</dt>
                        <dd class="font-medium text-slate-900">{{ $invoice->subscription->package->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">PPPoE Account</dt>
                        <dd class="font-mono text-slate-800">{{ $invoice->subscription->service_username ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Service Period</dt>
                        <dd class="text-slate-800">{{ $invoice->billing_period_start->format('d M Y') }} – {{ $invoice->billing_period_end->format('d M Y') }}</dd>
                    </div>
                    <div class="border-t border-slate-100 pt-3 flex justify-between gap-4">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="font-mono">Rp {{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">VAT</dt>
                        <dd class="font-mono">Rp {{ number_format((float) $invoice->tax_amount, 0, ',', '.') }}</dd>
                    </div>
                    @if((float) $invoice->gateway_fee_charged > 0)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Payment Gateway Fee</dt>
                            <dd class="font-mono">Rp {{ number_format((float) $invoice->gateway_fee_charged, 0, ',', '.') }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-slate-200 pt-3 text-base font-bold">
                        <dt class="text-slate-900">Total Amount</dt>
                        <dd class="font-mono text-indigo-600">Rp {{ number_format((float) $invoice->total_amount, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>

            @if($invoice->payments->isNotEmpty())
                <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-sm">
                    <div class="px-6 py-3.5 border-b border-slate-100 text-xs font-bold uppercase tracking-wider text-slate-500">
                        Payment Transaction History
                    </div>
                    <div class="divide-y divide-slate-100">
                        @foreach($invoice->payments as $pmt)
                            <div class="px-6 py-3 flex items-center justify-between gap-3 text-xs">
                                <div>
                                    <div class="font-mono font-semibold text-slate-900">{{ $pmt->payment_code }}</div>
                                    <div class="text-slate-500">{{ strtoupper($pmt->gateway) }} &middot; {{ $pmt->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-mono font-semibold text-slate-900">Rp {{ number_format((float) $pmt->amount, 0, ',', '.') }}</div>
                                    <x-status-badge :status="$pmt->status" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Right Column: QRIS Checkout / Official Paid Confirmation --}}
        <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm text-center">
            @if($isPaid)
                <div class="py-6 space-y-4">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-slate-900">Payment Successfully Received</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Paid on {{ $invoice->paid_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                        </p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-2.5 pt-2 justify-center">
                        <a href="{{ route('portal.invoices.download', $invoice) }}"
                           class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-5 py-2.5 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            Download PDF Receipt
                        </a>
                        <a href="{{ route('portal.invoices.print', $invoice) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Print Invoice
                        </a>
                    </div>

                    @if($invoice->subscription?->hasPendingMikrotikSync())
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 text-left">
                            <strong>Automatic Network Sync in Progress:</strong> Your payment is recorded as paid. The system is restoring access on your network router automatically (waiting for the router to come back online if unreachable) — no duplicate payment is required.
                        </div>
                    @endif
                </div>
            @elseif($errorMessage)
                <div class="py-6 space-y-4">
                    <p class="text-sm text-rose-600">{{ $errorMessage }}</p>
                    <button wire:click="renewSession" type="button"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                        Generate QRIS Session
                    </button>
                </div>
            @elseif($activePayment)
                <h3 class="text-lg font-bold text-slate-900">Dynamic QRIS Payment</h3>
                <p class="text-xs font-mono text-slate-500 mt-1">{{ $activePayment->payment_code }}</p>

                @if($qrSvg)
                    <div class="my-5 inline-block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        {!! $qrSvg !!}
                    </div>
                @endif

                <div x-data="{
                        expiresAt: {{ $activePayment->qris_expires_at ? $activePayment->qris_expires_at->timestamp * 1000 : 0 }},
                        remaining: '',
                        expired: false,
                        tick() {
                            const diff = Math.max(0, Math.floor((this.expiresAt - Date.now()) / 1000));
                            this.expired = diff <= 0;
                            const h = String(Math.floor(diff / 3600)).padStart(2, '0');
                            const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
                            const s = String(diff % 60).padStart(2, '0');
                            this.remaining = `${h}:${m}:${s}`;
                        }
                     }"
                     x-init="tick(); setInterval(() => tick(), 1000)"
                     class="space-y-4">
                    <div class="text-sm text-slate-600">
                        Valid for: <span class="font-mono font-bold text-slate-900" x-text="remaining"></span>
                    </div>

                    <template x-if="!expired">
                        <a href="{{ $activePayment->payment_url }}" target="_blank" rel="noopener"
                           class="inline-flex w-full min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                            Open Sumopod Payment Page
                        </a>
                    </template>

                    <template x-if="expired">
                        <button wire:click="renewSession" type="button"
                                class="inline-flex w-full min-h-12 items-center justify-center rounded-xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-700 transition">
                            Create New QRIS Session
                        </button>
                    </template>

                    <p class="text-[11px] text-slate-400">
                        Payment status refreshes automatically every few seconds. Once settled, your internet access will be restored automatically.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
