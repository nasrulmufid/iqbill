@extends('layouts.app', ['title' => 'Customer Details — ' . $customer->full_name])

@section('content')
<div class="space-y-6">
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
</div>
@endsection
