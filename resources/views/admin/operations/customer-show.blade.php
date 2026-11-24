@extends('layouts.app', ['title' => 'Customer Details — ' . $customer->full_name])

@php
    $isActivePeriodError = $errors->any() && filled(old('active_period_subscription_id'));
    $initialActivePeriod = null;

    if ($isActivePeriodError) {
        $failedSub = $customer->subscriptions->firstWhere('id', (int) old('active_period_subscription_id'));
        if ($failedSub !== null) {
            $initialActivePeriod = [
                'id' => $failedSub->id,
                'package_name' => $failedSub->package->name ?? '-',
                'service_username' => $failedSub->service_username,
                'status' => $failedSub->status->value,
                'current_date' => $failedSub->next_billing_date->toDateString(),
                'next_billing_date' => (string) old('next_billing_date', $failedSub->next_billing_date->toDateString()),
                'reason' => (string) old('reason', ''),
            ];
        }
    }

    $subscriptionsById = $customer->subscriptions->keyBy('id');
@endphp

@section('content')
<div class="space-y-6"
     x-data="{
        editingActivePeriod: {{ Js::from($initialActivePeriod) }},
        todayWib: @js($todayWib),
        openActivePeriodModal(sub) {
            this.editingActivePeriod = {
                id: sub.id,
                package_name: sub.package_name,
                service_username: sub.service_username,
                status: sub.status,
                current_date: sub.current_date,
                next_billing_date: sub.current_date,
                reason: ''
            };
        },
        parseYmdUtc(ymd) {
            if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
            const [y, m, d] = ymd.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d));
            if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
            return dt.getTime();
        },
        formatDisplayDate(ymd) {
            const ms = this.parseYmdUtc(ymd);
            if (ms === null) return '-';
            const dt = new Date(ms);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return String(dt.getUTCDate()).padStart(2, '0') + ' ' + months[dt.getUTCMonth()] + ' ' + dt.getUTCFullYear();
        },
        activePeriodDeltaDays() {
            if (!this.editingActivePeriod) return 0;
            const a = this.parseYmdUtc(this.editingActivePeriod.current_date);
            const b = this.parseYmdUtc(this.editingActivePeriod.next_billing_date);
            if (a === null || b === null) return 0;
            return Math.round((b - a) / 86400000);
        },
        isFutureDate(ymd) {
            const target = this.parseYmdUtc(ymd);
            const today = this.parseYmdUtc(this.todayWib);
            return target !== null && today !== null && target > today;
        },
        willAutoRestore() {
            return this.editingActivePeriod
                && this.editingActivePeriod.status === 'isolated'
                && this.isFutureDate(this.editingActivePeriod.next_billing_date);
        }
     }"
     @keydown.escape.window="editingActivePeriod = null">
    <x-page-header :title="$customer->full_name" :subtitle="'Customer ID: ' . $customer->customer_code . ' · WhatsApp: ' . $customer->phone_whatsapp">
        <x-slot:actions>
            <a href="{{ route('admin.customers.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                &larr; Back to List
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Profile & Location Map --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">Profile &amp; Installation Location</h3>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">Customer ID</dt>
                    <dd class="font-mono font-bold text-indigo-600">{{ $customer->customer_code }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">WhatsApp</dt>
                    <dd class="font-mono">{{ $customer->phone_whatsapp }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">Email</dt>
                    <dd>{{ $customer->email ?? '-' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">National ID / Tax ID</dt>
                    <dd class="font-mono">{{ $customer->nik_npwp ?? '-' }}</dd>
                </div>
                <div class="border-t border-slate-100 pt-2">
                    <dt class="text-slate-500 text-xs">Installation Address</dt>
                    <dd class="mt-0.5 text-slate-800">{{ $customer->installation_address }}</dd>
                </div>
                @if($customer->latitude && $customer->longitude)
                    <div class="pt-1">
                        <a href="https://www.google.com/maps?q={{ $customer->latitude }},{{ $customer->longitude }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                            Open Pin in Google Maps ({{ $customer->latitude }}, {{ $customer->longitude }}) &rarr;
                        </a>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Customer Portal Account --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">Customer Portal Account</h3>
            <form method="POST" action="{{ route('admin.customers.portal-account', $customer) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600">Portal Login Username</label>
                    <input type="text" name="portal_username" value="{{ old('portal_username', $customer->user?->username) }}" required
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">New Password (Leave blank to keep unchanged)</label>
                    <input type="password" name="portal_password" placeholder="Initial default: 12345678"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>
                <button type="submit" class="w-full rounded-xl bg-slate-900 py-2.5 text-xs font-semibold text-white hover:bg-slate-800">
                    Save Portal Account
                </button>
            </form>
        </div>

        {{-- Billing & Ticket Summary --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">Activity Summary</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Total Invoices</div>
                    <div class="mt-1 text-xl font-bold text-slate-900">{{ $customer->invoices->count() }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Support Tickets</div>
                    <div class="mt-1 text-xl font-bold text-slate-900">{{ $customer->tickets->count() }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- PPPoE Services & Isolate / Restore Controls --}}
    <div class="space-y-4">
        <h3 class="text-lg font-bold text-slate-900">Service Connections &amp; MikroTik Control</h3>
        @forelse($customer->subscriptions as $sub)
            @php $usage = $usageBySub->get($sub->id); @endphp
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="text-lg font-bold text-slate-900">{{ $sub->package->name ?? '-' }}</span>
                            <x-status-badge :status="$sub->status" />
                            @if($sub->hasPendingMikrotikSync())
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-bold uppercase text-amber-800 ring-1 ring-amber-300">
                                    Router Sync {{ strtoupper($sub->mikrotik_sync_status) }}: {{ $sub->mikrotik_pending_action }}
                                </span>
                            @endif
                        </div>
                        @if($sub->hasPendingMikrotikSync())
                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                <strong>Pending Router Sync ({{ $sub->mikrotik_sync_attempts }}x attempts):</strong>
                                {{ $sub->mikrotik_last_sync_error ?: 'Awaiting automatic router synchronization queue.' }}
                            </div>
                        @endif
                        <div class="text-xs text-slate-500 font-mono">
                            Router: <strong>{{ $sub->mikrotikRouter->name ?? '-' }}</strong> &middot;
                            PPPoE: <strong>{{ $sub->service_username }}</strong> &middot;
                            Remote IP: <strong>{{ $sub->static_ip_address ?: 'Dynamic' }}</strong>
                        </div>
                        <div class="text-xs text-slate-500">
                            Active until: <strong>{{ $sub->next_billing_date->format('d M Y') }}</strong> &middot;
                            Isolation Day: <strong>{{ $sub->isolir_day ? "Day {$sub->isolir_day} (23:59 WIB)" : 'Global Grace Period' }}</strong> &middot;
                            Recorded Usage: <strong>{{ number_format((($usage?->total_rx ?? 0) + ($usage?->total_tx ?? 0)) / 1073741824, 2) }} GB</strong>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button type="button"
                                @click="openActivePeriodModal({{ Js::from([
                                    'id' => $sub->id,
                                    'package_name' => $sub->package->name ?? '-',
                                    'service_username' => $sub->service_username,
                                    'status' => $sub->status->value,
                                    'current_date' => $sub->next_billing_date->toDateString(),
                                ]) }})"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Edit Active Period
                        </button>

                        @if($sub->status->value === 'pending_installation')
                            <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}">
                                @csrf
                                <input type="hidden" name="action" value="activate">
                                <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                                    Activate Service
                                </button>
                            </form>
                        @endif

                        @if($sub->status->value === 'isolated')
                            <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}"
                                  onsubmit="return confirm('Restore this customer and re-enable MikroTik access?')">
                                @csrf
                                <input type="hidden" name="action" value="restore">
                                <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                    Restore Access
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}"
                                  onsubmit="return confirm('Isolate this customer on MikroTik right now?')">
                                @csrf
                                <input type="hidden" name="action" value="isolate">
                                <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-700">
                                    Isolate Now
                                </button>
                            </form>
                        @endif

                        @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Finance))
                            <form method="POST" action="{{ route('admin.subscriptions.receive-cash', $sub) }}"
                                  onsubmit="return confirm('Record Receive Cash & extend active period by 1 month?')">
                                @csrf
                                <button type="submit" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                    Receive Cash
                                </button>
                            </form>
                        @endif

                        @if($sub->hasPendingMikrotikSync())
                            <form method="POST" action="{{ route('admin.subscriptions.reconcile', $sub) }}">
                                @csrf
                                <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-700">
                                    Re-Sync to Router
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}">
                            @csrf
                            <input type="hidden" name="action" value="sync_secret">
                            <button type="submit" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Sync MikroTik Secret
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state title="Customer has no subscriptions yet" description="Add a subscription via the Billing Cycles & Isolation Schedule menu." />
        @endforelse
    </div>

    {{-- Manual Active Period Audit Log --}}
    @if($activePeriodLogs->isNotEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="px-6 py-4 border-b border-slate-100 font-semibold text-slate-900">
                Manual Active Period History
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Timestamp (WIB)</th>
                            <th class="px-6 py-3">PPPoE</th>
                            <th class="px-6 py-3">Date Change</th>
                            <th class="px-6 py-3">Details</th>
                            <th class="px-6 py-3">Updated By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @foreach($activePeriodLogs as $log)
                            @php
                                $oldDate = $log->old_values['next_billing_date'] ?? null;
                                $newDate = $log->new_values['next_billing_date'] ?? null;
                                $delta = (int) ($log->new_values['delta_days'] ?? 0);
                                $autoRestored = (bool) ($log->new_values['auto_restored'] ?? false);
                                $isBulk = (bool) ($log->new_values['bulk'] ?? false);
                                $reason = $log->new_values['reason'] ?? null;
                                $logSub = $subscriptionsById->get($log->auditable_id);
                            @endphp
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 font-mono">
                                    {{ $log->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}
                                </td>
                                <td class="px-6 py-3 font-mono font-semibold text-slate-700">
                                    {{ $logSub?->service_username ?? ('Sub #' . $log->auditable_id) }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-slate-500">{{ $oldDate ? \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $oldDate)->format('d M Y') : '-' }}</span>
                                    <span class="mx-1 text-slate-400">&rarr;</span>
                                    <span class="font-semibold text-slate-900">{{ $newDate ? \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $newDate)->format('d M Y') : '-' }}</span>
                                    @if($delta > 0)
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">+{{ $delta }}d</span>
                                    @elseif($delta < 0)
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200">{{ $delta }}d</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 space-y-1">
                                    <div class="flex flex-wrap gap-1">
                                        @if($isBulk)
                                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                                Bulk
                                            </span>
                                        @endif
                                        @if($autoRestored)
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                Auto-Restored
                                            </span>
                                        @endif
                                    </div>
                                    @if($reason)
                                        <div class="text-slate-600">{{ $reason }}</div>
                                    @elseif(!$autoRestored && !$isBulk)
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-slate-700 font-medium">
                                    {{ $log->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Invoice History --}}
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold text-slate-900">Customer Invoice History</div>
        @if($customer->invoices->isEmpty())
            <div class="p-6">
                <x-empty-state title="No invoices yet" description="Invoices will appear once the service is activated or online payment is selected." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Invoice</th>
                            <th class="px-6 py-3">Total</th>
                            <th class="px-6 py-3">Due Date</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($customer->invoices as $inv)
                            <tr>
                                <td class="px-6 py-3.5 font-mono font-semibold">{{ $inv->invoice_number }}</td>
                                <td class="px-6 py-3.5 font-semibold">Rp {{ number_format((float) $inv->total_amount, 0, ',', '.') }}</td>
                                <td class="px-6 py-3.5">{{ $inv->due_date->format('d M Y') }}</td>
                                <td class="px-6 py-3.5"><x-status-badge :status="$inv->status" /></td>
                                <td class="px-6 py-3.5 text-right space-x-2">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ route('public.pay', $inv->public_token) }}'); window.dispatchEvent(new CustomEvent('notify-copy'));"
                                            class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium">
                                        Copy Link
                                    </button>
                                    <a href="{{ route('admin.invoices.show', $inv) }}" class="text-indigo-600 font-semibold hover:underline">Details</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Edit Active Period Modal --}}
    <div x-show="editingActivePeriod"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
         style="display: none;">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
             @click.outside="editingActivePeriod = null">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Edit Active Period</h3>
                    <template x-if="editingActivePeriod">
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-semibold text-slate-700" x-text="editingActivePeriod.package_name"></span>
                            &middot;
                            <span class="font-mono" x-text="editingActivePeriod.service_username"></span>
                        </p>
                    </template>
                </div>
                <button type="button" @click="editingActivePeriod = null"
                        class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition"
                        aria-label="Close modal">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <template x-if="editingActivePeriod">
                <form method="POST"
                      :action="'{{ url('/admin/subscriptions') }}/' + editingActivePeriod.id + '/active-period'"
                      class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="active_period_subscription_id" :value="editingActivePeriod.id">

                    {{-- Current vs New Preview --}}
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 flex items-center justify-between gap-2 text-xs">
                        <div>
                            <div class="text-slate-500">Current Active Until</div>
                            <div class="font-bold text-slate-800 mt-0.5" x-text="formatDisplayDate(editingActivePeriod.current_date)"></div>
                        </div>
                        <div class="text-slate-400">&rarr;</div>
                        <div class="text-right">
                            <div class="text-slate-500">New Active Until</div>
                            <div class="font-bold text-indigo-700 mt-0.5" x-text="formatDisplayDate(editingActivePeriod.next_billing_date)"></div>
                        </div>
                    </div>

                    {{-- Delta Days Indicator --}}
                    <div class="flex items-center justify-between text-xs px-1">
                        <span class="text-slate-500">Adjustment</span>
                        <span x-show="activePeriodDeltaDays() > 0"
                              class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200"
                              x-text="'+' + activePeriodDeltaDays() + (activePeriodDeltaDays() === 1 ? ' day (extended)' : ' days (extended)')"></span>
                        <span x-show="activePeriodDeltaDays() < 0"
                              class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 font-semibold text-rose-700 ring-1 ring-rose-200"
                              x-text="activePeriodDeltaDays() + (activePeriodDeltaDays() === -1 ? ' day (reduced)' : ' days (reduced)')"></span>
                        <span x-show="activePeriodDeltaDays() === 0"
                              class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-600">
                            No date change
                        </span>
                    </div>

                    {{-- Date Picker Component --}}
                    <x-date-picker
                        name="next_billing_date"
                        id="manual-active-period-date"
                        model="editingActivePeriod.next_billing_date"
                        original-model="editingActivePeriod.current_date"
                        :today="$todayWib"
                        label="Active Until Date" />

                    {{-- Auto-Restore Notice for Isolated Subscriptions --}}
                    <div x-show="willAutoRestore()"
                         class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 space-y-1"
                         style="display: none;">
                        <div class="font-bold text-emerald-900">Automatic Restore Enabled</div>
                        <p>This subscription is currently <strong>isolated</strong>. Saving a future date will automatically change its status to <strong>Active</strong> and queue MikroTik access restoration.</p>
                    </div>

                    <div x-show="editingActivePeriod.status === 'isolated' && !isFutureDate(editingActivePeriod.next_billing_date)"
                         class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800"
                         style="display: none;">
                        This subscription is currently <strong>isolated</strong>. Choosing today or a past date will update the date only without auto-restoring access.
                    </div>

                    {{-- Optional Audit Note --}}
                    <div>
                        <label for="manual-active-period-reason" class="block text-xs font-semibold text-slate-700 mb-1">
                            Reason / Note <span class="font-normal text-slate-400">(optional, recorded in audit log)</span>
                        </label>
                        <input type="text"
                               id="manual-active-period-reason"
                               name="reason"
                               x-model="editingActivePeriod.reason"
                               maxlength="500"
                               placeholder="e.g. Outage compensation, manual grace extension"
                               class="w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                        <button type="button" @click="editingActivePeriod = null"
                                class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit"
                                :class="willAutoRestore() ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                                class="rounded-xl px-4 py-2 text-xs font-semibold text-white shadow-sm transition"
                                x-text="willAutoRestore() ? 'Save & Auto-Restore' : 'Save Active Period'">
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
