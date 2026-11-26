@extends('layouts.app', ['title' => 'Executive Dashboard'])

@section('content')
<div class="space-y-6">
    {{-- Welcome Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">Operations & Billing Overview</h2>
            <p class="mt-0.5 text-sm text-slate-500">Monitor Sumopod QRIS revenue, current billing cycle status, and MikroTik service health.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
                <a href="{{ route('admin.subscriptions.index') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                    <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    Subscription
                </a>
            @endif
            <a href="{{ route('admin.invoices.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                View All Invoices
            </a>
        </div>
    </div>

    {{-- Alert Pending MikroTik Sync (when routers were offline) --}}
    @if(($pendingSyncCount ?? 0) > 0 || ($offlineRoutersCount ?? 0) > 0)
        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
                <div class="text-sm text-amber-900">
                    <div class="font-bold">MikroTik Network Attention Required</div>
                    <p class="text-xs text-amber-800 mt-0.5">
                        @if(($offlineRoutersCount ?? 0) > 0)
                            <strong>{{ $offlineRoutersCount }} MikroTik router(s)</strong> are currently unreachable.
                        @endif
                        @if(($pendingSyncCount ?? 0) > 0)
                            <strong>{{ $pendingSyncCount }} customer subscription(s)</strong> (including paid renewals) are awaiting automatic router reconciliation.
                        @endif
                    </p>
                </div>
            </div>
            @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
                <div class="flex flex-wrap gap-2 shrink-0">
                    <a href="{{ route('admin.routers.index') }}" class="rounded-xl border border-amber-300 bg-white px-3.5 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                        View Router Queue
                    </a>
                    @if(($pendingSyncCount ?? 0) > 0)
                        <form method="POST" action="{{ route('admin.mikrotik.reconcile') }}">
                            @csrf
                            <button type="submit" class="rounded-xl bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-amber-700">
                                Sync Pending Now
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Alert Queue Worker & Laravel Scheduler --}}
    @php
        $schedulerStatus = $automation['scheduler'] ?? [];
        $workerStatus = $automation['queue_worker'] ?? [];
        $schedulerNeedsAttention = !($schedulerStatus['ok'] ?? true);
        $workerNeedsAttention = ($workerStatus['state'] ?? 'ok') === 'failed' || (($workerStatus['failed_jobs'] ?? 0) > 0);
        $isAutomationCritical = ($schedulerStatus['state'] ?? '') === 'failed' || $workerNeedsAttention;
    @endphp
    @if(($schedulerNeedsAttention || $workerNeedsAttention) && auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
        <div class="rounded-2xl border {{ $isAutomationCritical ? 'border-rose-300 bg-rose-50' : 'border-amber-300 bg-amber-50' }} p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 {{ $isAutomationCritical ? 'text-rose-600' : 'text-amber-600' }} shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div class="text-sm {{ $isAutomationCritical ? 'text-rose-950' : 'text-amber-900' }}">
                    <div class="font-bold">System Automation Attention Required (Scheduler / Queue)</div>
                    <ul class="mt-0.5 space-y-0.5 text-xs {{ $isAutomationCritical ? 'text-rose-800' : 'text-amber-800' }}">
                        @if($schedulerNeedsAttention)
                            <li><strong>{{ $schedulerStatus['label'] ?? 'Laravel Scheduler' }} ({{ $schedulerStatus['status_label'] ?? 'Inactive' }}):</strong> {{ $schedulerStatus['detail'] ?? '' }}</li>
                        @endif
                        @if($workerNeedsAttention)
                            <li><strong>{{ $workerStatus['label'] ?? 'Queue Worker' }} ({{ $workerStatus['status_label'] ?? 'Unhealthy' }}):</strong> {{ $workerStatus['detail'] ?? '' }}</li>
                        @endif
                    </ul>
                </div>
            </div>
            <div class="shrink-0">
                <a href="{{ route('admin.monitoring.index') }}"
                   class="inline-flex items-center rounded-xl border {{ $isAutomationCritical ? 'border-rose-300 text-rose-900 hover:bg-rose-100' : 'border-amber-300 text-amber-900 hover:bg-amber-100' }} bg-white px-3.5 py-2 text-xs font-semibold transition">
                    Check System Health &rarr;
                </a>
            </div>
        </div>
    @endif

    {{-- Top KPI Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
        {{-- 1. Net Revenue --}}
        <div class="rounded-2xl bg-white border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Net Revenue</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        Rp {{ number_format((float) ($paidStats->net ?? 0), 0, ',', '.') }}
                    </p>
                </div>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
                <span>Gross: Rp {{ number_format((float) ($paidStats->gross ?? 0), 0, ',', '.') }}</span>
                <span class="font-semibold text-emerald-600">{{ $paidStats->count ?? 0 }} Paid</span>
            </div>
        </div>

        {{-- 2. Unpaid / Overdue Invoices --}}
        <div class="rounded-2xl bg-white border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Unpaid / Overdue Invoices</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        Rp {{ number_format($invoiceStats['unpaid_total'] + $invoiceStats['overdue_total'], 0, ',', '.') }}
                    </p>
                </div>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-amber-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="mt-4 flex items-center gap-3 border-t border-slate-100 pt-3 text-xs">
                <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 font-medium text-amber-700">
                    {{ $invoiceStats['unpaid_count'] }} Unpaid
                </span>
                <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 font-medium text-rose-700">
                    {{ $invoiceStats['overdue_count'] }} Overdue
                </span>
            </div>
        </div>

        {{-- 3. Active Subscriptions vs Isolated --}}
        <div class="rounded-2xl bg-white border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Active Subscriptions</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        {{ $subscriptionStats['active'] }}
                        <span class="text-sm font-medium text-slate-400">Customers</span>
                    </p>
                </div>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0" />
                    </svg>
                </span>
            </div>
            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs">
                <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-0.5 font-medium text-rose-700">
                    {{ $subscriptionStats['isolated'] }} Isolated
                </span>
                <span class="text-slate-500">{{ $subscriptionStats['pending_installation'] }} Pending Install</span>
            </div>
        </div>

        {{-- 4. Active MikroTik Routers --}}
        <div class="rounded-2xl bg-white border border-slate-200/80 p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Active MikroTik Routers</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        {{ $routerCount }}
                        <span class="text-sm font-medium text-slate-400">NAS</span>
                    </p>
                </div>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 ring-1 ring-sky-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2" />
                    </svg>
                </span>
            </div>
            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs">
                <span class="text-slate-500">RouterOS API</span>
                @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
                    <a href="{{ route('admin.routers.index') }}" class="font-semibold text-sky-600 hover:text-sky-700">Manage Routers &rarr;</a>
                @else
                    <span class="text-slate-400">Active</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Lower Two-Column Section --}}
    <script>
        window.dashboardRevenueChart = function (allItems) {
            return {
                items: Array.isArray(allItems) ? allItems : [],
                range: 12,
                metric: 'net',
                activeIdx: null,

                get visibleItems() {
                    return this.range === 6 ? this.items.slice(-6) : this.items;
                },
                metricValue(item) {
                    if (!item) return 0;
                    return Number(this.metric === 'gross' ? item.gross : item.net) || 0;
                },
                get totalPeriod() {
                    return this.visibleItems.reduce((sum, item) => sum + this.metricValue(item), 0);
                },
                get totalTransactions() {
                    return this.visibleItems.reduce((sum, item) => sum + (Number(item.count) || 0), 0);
                },
                get averagePerMonth() {
                    return this.visibleItems.length ? this.totalPeriod / this.visibleItems.length : 0;
                },
                get currentItem() {
                    return this.items.length ? this.items[this.items.length - 1] : null;
                },
                get prevItem() {
                    return this.items.length >= 2 ? this.items[this.items.length - 2] : null;
                },
                get currentValue() {
                    return this.currentItem ? this.metricValue(this.currentItem) : 0;
                },
                get prevValue() {
                    return this.prevItem ? this.metricValue(this.prevItem) : 0;
                },
                get momChange() {
                    const cur = this.currentValue;
                    const prev = this.prevValue;
                    if (prev === 0 && cur > 0) {
                        return { label: 'New this month', tone: 'up' };
                    }
                    if (prev === 0 && cur === 0) {
                        return { label: '0% vs last mo', tone: 'flat' };
                    }
                    const pct = ((cur - prev) / prev) * 100;
                    const absStr = Math.abs(pct).toFixed(1);
                    if (pct > 0.05) {
                        return { label: '+' + absStr + '% vs last mo', tone: 'up' };
                    }
                    if (pct < -0.05) {
                        return { label: '-' + absStr + '% vs last mo', tone: 'down' };
                    }
                    return { label: '0.0% vs last mo', tone: 'flat' };
                },
                get peakItem() {
                    let best = null;
                    let bestVal = 0;
                    for (const item of this.visibleItems) {
                        const v = this.metricValue(item);
                        if (v > bestVal) {
                            bestVal = v;
                            best = item;
                        }
                    }
                    return best;
                },
                get yMax() {
                    let raw = 0;
                    for (const item of this.visibleItems) {
                        const v = this.metricValue(item);
                        if (v > raw) raw = v;
                    }
                    if (raw <= 0) return 1000000;
                    const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
                    const steps = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];
                    const ratio = raw / magnitude;
                    const mult = steps.find((s) => s >= ratio) || 10;
                    return mult * magnitude;
                },
                get yTicks() {
                    const m = this.yMax;
                    return [m, m * 0.75, m * 0.5, m * 0.25, 0];
                },
                barHeightPercent(item) {
                    const v = this.metricValue(item);
                    if (v <= 0) return 0;
                    return Math.max(4, Math.min(100, (v / this.yMax) * 100));
                },
                formatRp(num) {
                    return 'Rp ' + Math.round(Number(num) || 0).toLocaleString('id-ID');
                },
                formatCompactRp(num) {
                    const v = Number(num) || 0;
                    if (v <= 0) return 'Rp 0';
                    if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
                    if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
                    if (v >= 1e3) return 'Rp ' + Math.round(v / 1e3).toLocaleString('id-ID') + 'K';
                    return 'Rp ' + Math.round(v);
                },
            };
        };
    </script>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {{-- Left Column (2/3): Monthly Revenue Chart --}}
        <div x-data="window.dashboardRevenueChart(@js($monthlyRevenue))"
             class="xl:col-span-2 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col">
            {{-- Chart Header --}}
            <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-900">Monthly Revenue</h3>
                    <p class="text-xs text-slate-500">
                        Paid revenue trend (<span x-text="range === 6 ? 'last 6 months' : 'last 12 months'"></span>)
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-xl bg-slate-100 p-0.5 text-xs font-semibold">
                        <button type="button"
                                @click="metric = 'net'; activeIdx = null"
                                :class="metric === 'net' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                class="rounded-lg px-2.5 py-1 transition">
                            Net
                        </button>
                        <button type="button"
                                @click="metric = 'gross'; activeIdx = null"
                                :class="metric === 'gross' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                class="rounded-lg px-2.5 py-1 transition">
                            Gross
                        </button>
                    </div>
                    <div class="inline-flex rounded-xl bg-slate-100 p-0.5 text-xs font-semibold">
                        <button type="button"
                                @click="range = 6; activeIdx = null"
                                :class="range === 6 ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                class="rounded-lg px-2.5 py-1 transition">
                            6M
                        </button>
                        <button type="button"
                                @click="range = 12; activeIdx = null"
                                :class="range === 12 ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                class="rounded-lg px-2.5 py-1 transition">
                            12M
                        </button>
                    </div>
                    @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Finance))
                        <a href="{{ route('admin.finance.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 ml-1">
                            Reports &rarr;
                        </a>
                    @endif
                </div>
            </div>

            {{-- Summary Strip --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 px-6 py-4 bg-slate-50/70 border-b border-slate-100">
                <div class="rounded-xl bg-white border border-slate-200/70 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Period Total (<span x-text="metric === 'gross' ? 'Gross' : 'Net'"></span>)
                    </div>
                    <div class="mt-1 text-lg font-bold text-slate-900 tabular-nums" x-text="formatRp(totalPeriod)"></div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        <span class="font-semibold text-slate-700" x-text="totalTransactions"></span> paid transactions
                    </div>
                </div>

                <div class="rounded-xl bg-white border border-slate-200/70 px-4 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">This Month</span>
                        <span class="text-[11px] font-medium text-slate-500" x-text="currentItem ? currentItem.full_label : ''"></span>
                    </div>
                    <div class="mt-1 text-lg font-bold text-slate-900 tabular-nums" x-text="formatRp(currentValue)"></div>
                    <div class="mt-1">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                              :class="momChange.tone === 'up' ? 'bg-emerald-50 text-emerald-700' : (momChange.tone === 'down' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600')"
                              x-text="momChange.label"></span>
                    </div>
                </div>

                <div class="rounded-xl bg-white border border-slate-200/70 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Monthly Average</div>
                    <div class="mt-1 text-lg font-bold text-slate-900 tabular-nums" x-text="formatRp(averagePerMonth)"></div>
                    <div class="mt-0.5 truncate text-xs text-slate-500">
                        <template x-if="peakItem">
                            <span>Peak: <span class="font-semibold text-slate-700" x-text="peakItem.full_label"></span></span>
                        </template>
                        <template x-if="!peakItem">
                            <span>No transactions yet</span>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Bar Chart Plot --}}
            <div class="px-5 sm:px-6 pt-6 pb-4 flex-1 flex flex-col justify-between">
                <div class="flex items-stretch gap-2">
                    {{-- Y-Axis Labels --}}
                    <div class="w-16 sm:w-20 shrink-0 h-64 flex flex-col justify-between items-end pr-1 text-[11px] font-medium text-slate-400 tabular-nums select-none">
                        <template x-for="(tick, tIdx) in yTicks" :key="tIdx">
                            <span class="leading-none -translate-y-1/2 first:translate-y-0 last:translate-y-0" x-text="formatCompactRp(tick)"></span>
                        </template>
                    </div>

                    {{-- Plot & X-Axis --}}
                    <div class="flex-1 min-w-0">
                        <div class="relative h-64 border-b border-slate-200">
                            {{-- Horizontal Gridlines --}}
                            <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                <div class="border-t border-dashed border-slate-200/80 w-full"></div>
                                <div class="border-t border-dashed border-slate-200/80 w-full"></div>
                                <div class="border-t border-dashed border-slate-200/80 w-full"></div>
                                <div class="border-t border-dashed border-slate-200/80 w-full"></div>
                                <div class="w-full"></div>
                            </div>

                            {{-- Empty Overlay Pill --}}
                            <div x-show="totalPeriod === 0"
                                 style="display: none;"
                                 class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                                <div class="rounded-full border border-slate-200 bg-white/95 px-4 py-2 text-xs font-medium text-slate-500 shadow-sm backdrop-blur-sm">
                                    No paid transactions in this period
                                </div>
                            </div>

                            {{-- Columns --}}
                            <div class="relative z-20 grid grid-rows-1 h-full gap-1.5 sm:gap-2.5"
                                 :class="range === 6 ? 'grid-cols-6' : 'grid-cols-12'">
                                <template x-for="(item, idx) in visibleItems" :key="item.key">
                                    <div class="group relative h-full flex flex-col justify-end items-center rounded-t-lg transition-colors focus:outline-none cursor-pointer"
                                         :class="activeIdx === idx ? 'bg-slate-100/70' : 'hover:bg-slate-50/70'"
                                         tabindex="0"
                                         @mouseenter="activeIdx = idx"
                                         @mouseleave="activeIdx = null"
                                         @focus="activeIdx = idx"
                                         @blur="activeIdx = null"
                                         @click="activeIdx = (activeIdx === idx ? null : idx)">

                                        {{-- Tooltip Popover --}}
                                        <div x-show="activeIdx === idx"
                                             x-transition.opacity.duration.150ms
                                             style="display: none;"
                                             class="pointer-events-none absolute bottom-full mb-2 z-30 w-48 rounded-xl bg-slate-900 p-3 text-left text-xs text-white shadow-xl ring-1 ring-slate-800"
                                             :class="idx < 2 ? 'left-0' : (idx >= visibleItems.length - 2 ? 'right-0' : 'left-1/2 -translate-x-1/2')">
                                            <div class="flex items-center justify-between gap-2 border-b border-slate-700/80 pb-1.5 mb-1.5">
                                                <span class="font-semibold text-white" x-text="item.full_label"></span>
                                                <span x-show="item.is_current" class="rounded bg-emerald-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-300">
                                                    This month
                                                </span>
                                            </div>
                                            <div class="space-y-1 tabular-nums">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-slate-400">Net</span>
                                                    <span class="font-semibold text-emerald-300" x-text="formatRp(item.net)"></span>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-slate-400">Gross</span>
                                                    <span class="font-medium text-slate-200" x-text="formatRp(item.gross)"></span>
                                                </div>
                                                <div x-show="Number(item.fee) > 0" class="flex items-center justify-between gap-2">
                                                    <span class="text-slate-400">Fee</span>
                                                    <span class="text-slate-300" x-text="formatRp(item.fee)"></span>
                                                </div>
                                            </div>
                                            <div class="mt-2 pt-1.5 border-t border-slate-700/80 text-[11px] text-slate-400">
                                                <span class="font-semibold text-slate-200" x-text="item.count"></span> paid transactions
                                            </div>
                                        </div>

                                        {{-- Bar --}}
                                        <div class="w-full max-w-[34px] shrink-0 transition-all duration-300"
                                             :class="metricValue(item) > 0
                                                 ? (item.is_current
                                                     ? (metric === 'gross' ? 'rounded-t-lg shadow-sm bg-gradient-to-t from-sky-600 to-sky-400 ring-2 ring-sky-200/70' : 'rounded-t-lg shadow-sm bg-gradient-to-t from-emerald-600 to-emerald-400 ring-2 ring-emerald-200/70')
                                                     : (metric === 'gross' ? 'rounded-t-lg shadow-sm bg-gradient-to-t from-violet-600 to-indigo-400' : 'rounded-t-lg shadow-sm bg-gradient-to-t from-indigo-600 to-indigo-400'))
                                                 : (item.is_current ? 'rounded-full bg-emerald-300' : 'rounded-full bg-slate-200')"
                                             :style="metricValue(item) > 0 ? ('height: ' + barHeightPercent(item) + '%') : 'height: 3px'">
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- X-Axis Labels --}}
                        <div class="grid gap-1.5 sm:gap-2.5 pt-2.5"
                             :class="range === 6 ? 'grid-cols-6' : 'grid-cols-12'">
                            <template x-for="(item, idx) in visibleItems" :key="'lbl-' + item.key">
                                <div class="text-center leading-tight select-none">
                                    <div class="text-[11px]"
                                         :class="item.is_current ? 'font-bold text-emerald-700' : 'font-medium text-slate-500'"
                                         x-text="item.short_label"></div>
                                    <div class="text-[10px] text-slate-400"
                                         x-show="item.show_year || idx === 0"
                                         x-text="item.year_short"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Footer Legend --}}
            <div class="px-6 py-3 bg-slate-50/60 border-t border-slate-100 rounded-b-2xl flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :class="metric === 'gross' ? 'bg-sky-500' : 'bg-emerald-500'"></span>
                        <span>Current Month</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :class="metric === 'gross' ? 'bg-violet-500' : 'bg-indigo-500'"></span>
                        <span>Other Months</span>
                    </span>
                </div>
                <span class="text-[11px] text-slate-400">Hover over bars for details</span>
            </div>
        </div>

        {{-- Right Column: Recent Invoices + Quick Actions --}}
        <div class="space-y-6">
            {{-- Recent Invoices Card --}}
            <div class="rounded-2xl bg-white border border-slate-200/80 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">Recent Invoices</h3>
                        <p class="text-xs text-slate-500">Latest 6 invoices &amp; Smart Payment Links</p>
                    </div>
                    <a href="{{ route('admin.invoices.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 shrink-0">
                        All &rarr;
                    </a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse($recentInvoices as $inv)
                        <div class="px-5 py-3.5 hover:bg-slate-50/60 transition space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-slate-900">{{ $inv->customer?->full_name ?? '-' }}</div>
                                    <div class="truncate font-mono text-xs text-slate-500">
                                        {{ $inv->invoice_number }} &middot; Due {{ $inv->due_date?->format('d M Y') ?? '-' }}
                                    </div>
                                </div>
                                <div class="text-right shrink-0 space-y-1">
                                    <div class="text-sm font-semibold text-slate-900 whitespace-nowrap">
                                        Rp {{ number_format((float) $inv->total_amount, 0, ',', '.') }}
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold
                                        {{ $inv->status->value === 'paid' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : ($inv->status->value === 'overdue' ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200') }}">
                                        {{ strtoupper($inv->status->value) }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-0.5">
                                <span class="truncate text-xs text-slate-500">{{ $inv->subscription?->package?->name ?? '-' }}</span>
                                <div class="inline-flex items-center gap-1.5 shrink-0">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ route('public.pay', $inv->public_token) }}'); window.dispatchEvent(new CustomEvent('notify-copy'));"
                                            class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 transition">
                                        Copy Link
                                    </button>
                                    <a href="{{ route('admin.invoices.show', $inv) }}"
                                       class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition">
                                        Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-xs text-slate-500">No invoices issued yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl bg-white border border-slate-200/80 p-5 shadow-sm space-y-3">
                <h3 class="text-sm font-semibold text-slate-900">Quick Shortcuts</h3>
                <div class="grid grid-cols-1 gap-2">
                    @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
                        <a href="{{ route('admin.customers.index') }}"
                           class="flex items-center justify-between rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                            <span>Add New Customer</span>
                            <span class="text-slate-400">&rarr;</span>
                        </a>
                        <a href="{{ route('admin.subscriptions.index') }}"
                           class="flex items-center justify-between rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                            <span>Create PPPoE Subscription</span>
                            <span class="text-slate-400">&rarr;</span>
                        </a>
                    @endif
                    @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin))
                        <a href="{{ route('admin.settings.payment.edit') }}"
                           class="flex items-center justify-between rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                            <span>Sumopod & Bot Settings</span>
                            <span class="text-slate-400">&rarr;</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
