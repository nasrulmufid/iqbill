@extends('layouts.app', ['title' => 'Customer Service Portal'])

@section('content')
@php
    $formatBytes = static function (int|float $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, '.', ',') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, '.', ',') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, '.', ',') . ' KB';
        }

        return (int) $bytes . ' B';
    };

    $primarySub = $subscriptions->first();
    $companyWaDigits = \App\Models\Customer::normalizeWhatsappPhone($company->company_phone ?? '');
@endphp

<div class="space-y-6">
    {{-- Top Welcome Header Card --}}
    <div class="rounded-2xl bg-slate-900 text-white p-6 shadow-lg shadow-slate-900/10 border border-slate-800 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-500/20 px-3 py-1 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-400/30">
                    {{ $company->company_name ?: 'IQ Bill' }} &middot; Client Portal
                </span>
                @if($customer)
                    <span class="inline-flex items-center rounded-full bg-slate-800 px-3 py-1 font-mono text-xs font-semibold text-slate-200 ring-1 ring-slate-700">
                        ID: {{ $customer->customer_code }}
                    </span>
                @endif
            </div>
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight">
                Welcome, {{ $customer?->full_name ?? auth()->user()?->name }}
            </h2>
            <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">
                Monitor your internet connection status, monthly data usage, download official PDF invoices, pay bills instantly via QRIS, or contact our support team.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <a href="{{ route('portal.invoices.index') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-slate-700 transition">
                <svg class="h-4 w-4 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                All Invoices
            </a>
            <a href="{{ route('portal.tickets.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-indigo-500 transition shadow-sm">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                </svg>
                Report Issue / Tickets
            </a>
            @if($companyWaDigits !== '')
                <a href="https://wa.me/{{ $companyWaDigits }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-emerald-500 transition">
                    WhatsApp Support
                </a>
            @endif
        </div>
    </div>

    {{-- Priority Unpaid / Overdue Invoice Callout --}}
    @if($activeInvoice)
        <div class="rounded-2xl bg-gradient-to-r {{ $activeInvoice->status->value === 'overdue' ? 'from-rose-600 via-rose-600 to-orange-600' : 'from-indigo-600 via-indigo-600 to-violet-600' }} p-6 text-white shadow-lg shadow-indigo-950/10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div class="space-y-1.5">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white backdrop-blur">
                    <span class="h-1.5 w-1.5 rounded-full {{ $activeInvoice->status->value === 'overdue' ? 'bg-amber-200 animate-pulse' : 'bg-amber-300' }}"></span>
                    Open Invoice — {{ $activeInvoice->status->value === 'overdue' ? 'OVERDUE' : 'UNPAID' }}
                </span>
                <h2 class="text-xl sm:text-2xl font-bold tracking-tight">
                    {{ $activeInvoice->invoice_number }} &middot; Rp {{ number_format((float) $activeInvoice->total_amount, 0, ',', '.') }}
                </h2>
                <p class="text-sm text-white/90">
                    Due on <strong>{{ $activeInvoice->due_date->format('d M Y') }}</strong>
                    @if($activeInvoice->subscription?->package)
                        &middot; Package {{ $activeInvoice->subscription->package->name }}
                    @endif
                </p>
            </div>
            <div class="flex flex-col sm:flex-row flex-wrap gap-2.5 shrink-0">
                <a href="{{ route('public.pay', $activeInvoice->public_token) }}"
                   class="inline-flex w-full sm:w-auto min-h-11 items-center justify-center rounded-xl bg-white px-5 py-2.5 text-sm font-semibold {{ $activeInvoice->status->value === 'overdue' ? 'text-rose-700 hover:bg-rose-50' : 'text-indigo-700 hover:bg-indigo-50' }} shadow-sm transition">
                    Pay Now
                </a>
                <a href="{{ route('portal.invoices.show', $activeInvoice) }}"
                   class="inline-flex w-full sm:w-auto min-h-11 items-center justify-center rounded-xl border border-white/30 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/20 transition">
                    View Details &amp; QRIS
                </a>
                <a href="{{ route('portal.invoices.download', $activeInvoice) }}"
                   class="inline-flex w-full sm:w-auto min-h-11 items-center justify-center gap-1.5 rounded-xl border border-white/30 bg-slate-900/25 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-900/40 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    PDF
                </a>
            </div>
        </div>
    @endif

    {{-- 4 KPI Summary Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Service Status</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl {{ $primarySub?->status->value === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                    </svg>
                </span>
            </div>
            <div class="mt-2 text-lg sm:text-xl font-bold text-slate-900">
                @if($primarySub)
                    {{ strtoupper(str_replace('_', ' ', $primarySub->status->value)) }}
                @else
                    INACTIVE
                @endif
            </div>
            <div class="mt-1 text-xs text-slate-500 truncate">
                {{ $primarySub?->package?->name ?? 'No active package yet' }}
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Open Balance</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl {{ $unpaidCount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600' }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                    </svg>
                </span>
            </div>
            <div class="mt-2 text-lg sm:text-xl font-bold text-slate-900">
                Rp {{ number_format($unpaidTotal, 0, ',', '.') }}
            </div>
            <div class="mt-1 text-xs text-slate-500">
                {{ $unpaidCount }} awaiting payment &middot; {{ $paidCount }} paid
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Monthly Usage</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                    </svg>
                </span>
            </div>
            <div class="mt-2 text-lg sm:text-xl font-bold text-slate-900">
                {{ $formatBytes($totalMonthBytes) }}
            </div>
            <div class="mt-1 text-xs text-slate-500">
                Period {{ now('Asia/Jakarta')->format('M Y') }}
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Support Tickets</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                    </svg>
                </span>
            </div>
            <div class="mt-2 text-lg sm:text-xl font-bold text-slate-900">
                {{ $openTicketCount }} Active
            </div>
            <div class="mt-1 text-xs text-slate-500">
                <a href="{{ route('portal.tickets.index') }}" class="text-indigo-600 font-semibold hover:underline">Open Help Center &rarr;</a>
            </div>
        </div>
    </div>

    {{-- Main Two-Column Grid: Subscriptions + Support & Account Info --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-7 space-y-5">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Internet Service &amp; Connection Details</h3>
                <span class="text-xs text-slate-500">{{ $subscriptions->count() }} registered connection(s)</span>
            </div>

            @forelse($subscriptions as $sub)
                @php
                    $usage = $usageBySub->get($sub->id);
                    $rxBytes = (int) ($usage?->month_rx ?? 0);
                    $txBytes = (int) ($usage?->month_tx ?? 0);
                @endphp
                <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Service Package</span>
                            <h4 class="mt-0.5 text-lg font-bold text-slate-900">{{ $sub->package->name }}</h4>
                            @if($sub->package->description)
                                <p class="text-xs text-slate-500 mt-0.5">{{ $sub->package->description }}</p>
                            @endif
                        </div>
                        <x-status-badge :status="$sub->status" />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                            &darr; Download {{ $sub->package->speed_download_mbps }} Mbps
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                            &uarr; Upload {{ $sub->package->speed_upload_mbps }} Mbps
                        </span>
                        <span class="inline-flex items-center rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 uppercase">
                            {{ $sub->package->service_type }}
                        </span>
                        @if($sub->mikrotikRouter)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
                                <span class="h-1.5 w-1.5 rounded-full {{ $sub->mikrotikRouter->is_reachable ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                                POP {{ $sub->mikrotikRouter->name }}
                            </span>
                        @endif
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs sm:text-sm">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-[11px] uppercase tracking-wider text-slate-400">PPPoE Username</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-slate-900">{{ $sub->service_username }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-[11px] uppercase tracking-wider text-slate-400">IP Address</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-slate-900">{{ $sub->static_ip_address ?: 'Dynamic Pool' }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-[11px] uppercase tracking-wider text-slate-400">Active Until</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900">{{ $sub->next_billing_date->format('d M Y') }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-[11px] uppercase tracking-wider text-slate-400">Monthly Rate</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900">
                                Rp {{ number_format((float) $sub->package->price, 0, ',', '.') }}
                                @if((float) $sub->package->tax_rate > 0)
                                    <span class="text-xs font-normal text-slate-500">(+VAT {{ rtrim(rtrim((string) $sub->package->tax_rate, '0'), '.') }}%)</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Monthly Usage Breakdown --}}
                    <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-xs">
                        <div class="font-semibold text-slate-700">This Month Traffic Statistics:</div>
                        <div class="flex flex-wrap items-center gap-4 text-slate-600">
                            <span>&darr; Download: <strong class="font-mono text-slate-900">{{ $formatBytes($rxBytes) }}</strong></span>
                            <span>&uarr; Upload: <strong class="font-mono text-slate-900">{{ $formatBytes($txBytes) }}</strong></span>
                            <span>Total: <strong class="font-mono text-indigo-700">{{ $formatBytes($rxBytes + $txBytes) }}</strong></span>
                        </div>
                    </div>

                    @if($sub->hasPendingMikrotikSync())
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-900">
                            <strong>Connection Activation Awaiting Router Online:</strong> Your payment/status is active and access will be restored automatically once the network router reconnects.
                        </div>
                    @endif
                </div>
            @empty
                <x-empty-state title="No internet service yet" description="Your account does not have an active service package yet. Please contact Customer Service for activation." />
            @endforelse
        </div>

        {{-- Right Column: Recent Support Tickets & Account / ISP Info --}}
        <div class="lg:col-span-5 space-y-5">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm space-y-4">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Recent Support Tickets</h3>
                        <p class="text-xs text-slate-500">Track the progress of your technical or billing inquiries.</p>
                    </div>
                    <a href="{{ route('portal.tickets.index') }}" class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                        + Create Ticket
                    </a>
                </div>

                @forelse($recentTickets as $ticket)
                    <a href="{{ route('portal.tickets.show', $ticket) }}"
                       class="block rounded-xl border border-slate-200/80 p-3.5 hover:border-indigo-300 hover:bg-slate-50/70 transition">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs font-bold text-indigo-600">{{ $ticket->ticket_number }}</span>
                            <x-status-badge :status="$ticket->status" />
                        </div>
                        <div class="mt-1 text-sm font-semibold text-slate-900 truncate">{{ $ticket->subject }}</div>
                        <div class="mt-1 text-[11px] text-slate-500">
                            {{ strtoupper($ticket->category) }} &middot; {{ $ticket->updated_at->diffForHumans() }}
                        </div>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 p-5 text-center text-xs text-slate-500">
                        No support tickets yet. Open a ticket anytime if you experience connection issues.
                    </div>
                @endforelse
            </div>

            <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">Customer &amp; Service Information</h3>
                    <a href="{{ route('portal.account.edit') }}" class="text-xs font-semibold text-indigo-600 hover:underline">Manage Account</a>
                </div>
                <dl class="space-y-2.5 text-xs">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Customer ID</dt>
                        <dd class="font-mono font-semibold text-slate-900">{{ $customer?->customer_code ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Registered WhatsApp</dt>
                        <dd class="font-mono font-medium text-slate-800">{{ $customer?->phone_whatsapp ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Installation Address</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $customer?->installation_address ?? '-' }}</dd>
                    </div>
                    @if($company->company_phone || $company->company_address)
                        <div class="border-t border-slate-100 pt-2.5">
                            <dt class="text-slate-500 font-semibold">{{ $company->company_name }} Customer Support</dt>
                            @if($company->company_phone)
                                <dd class="mt-0.5 font-mono text-slate-700">Phone / WA: {{ $company->company_phone }}</dd>
                            @endif
                            @if($company->company_address)
                                <dd class="mt-0.5 text-slate-500">{{ $company->company_address }}</dd>
                            @endif
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Recent Invoices Table with Download PDF + Print + Pay --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-bold text-slate-900">Recent Billing History</h3>
                <p class="text-xs text-slate-500">Download official PDF invoices or pay online.</p>
            </div>
            <a href="{{ route('portal.invoices.index') }}" class="text-xs font-semibold text-indigo-600 hover:underline">
                View All Invoices &rarr;
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Invoice</th>
                        <th class="px-6 py-3">Package</th>
                        <th class="px-6 py-3">Period</th>
                        <th class="px-6 py-3">Due Date</th>
                        <th class="px-6 py-3">Total</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($invoices as $inv)
                        <tr class="hover:bg-slate-50/60 transition">
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
                                       title="Download PDF Invoice"
                                       class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                        <svg class="h-3.5 w-3.5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        PDF
                                    </a>
                                    <a href="{{ route('portal.invoices.print', $inv) }}" target="_blank" rel="noopener"
                                       class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                        Print
                                    </a>
                                    <a href="{{ route('portal.invoices.show', $inv) }}"
                                       class="rounded-lg {{ in_array($inv->status->value, ['unpaid', 'overdue'], true) ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' }} px-2.5 py-1 text-xs font-semibold transition">
                                        {{ in_array($inv->status->value, ['unpaid', 'overdue'], true) ? 'Pay / Details' : 'Details' }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No billing history yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
