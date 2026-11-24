@extends('layouts.app', ['title' => 'Customers List'])

@php
    $isBulkActivePeriodError = $errors->any() && old('form_context') === 'bulk_active_period';
    $isAddCustomerError = $errors->any() && !$isBulkActivePeriodError;

    $activePageIds = $customers
        ->filter(fn ($cust) => $cust->subscriptions->contains(fn ($sub) => $sub->status === \App\Enums\SubscriptionStatus::Active))
        ->pluck('id')
        ->map(fn ($id) => (string) $id)
        ->values()
        ->all();

    $initialSelectedIds = $isBulkActivePeriodError
        ? array_values(array_map('strval', (array) old('customer_ids', [])))
        : [];
@endphp

@section('content')
<div id="customers-page-root" class="space-y-6"
     x-data="{
        addOpen: {{ $isAddCustomerError ? 'true' : 'false' }},
        importOpen: false,
        editing: null,
        bulkActivePeriodOpen: {{ $isBulkActivePeriodError ? 'true' : 'false' }},
        bulkScope: @js($isBulkActivePeriodError ? old('target_scope', 'selected') : 'selected'),
        bulkOperation: @js($isBulkActivePeriodError ? old('operation', 'add') : 'add'),
        bulkDays: @js($isBulkActivePeriodError ? (string) old('days', '7') : '7'),
        bulkReason: @js($isBulkActivePeriodError ? (string) old('reason', '') : ''),
        activeSubscriptionsTotal: {{ (int) $activeSubscriptionsCount }},
        selectedIds: @js($initialSelectedIds),
        pageIds: @js($customers->pluck('id')->map(fn ($id) => (string) $id)->values()->all()),
        activePageIds: @js($activePageIds),
        customerCode: @js($isAddCustomerError ? old('customer_code', '') : ''),
        portalUsername: @js($isAddCustomerError ? old('portal_username', '') : ''),
        phoneWhatsapp: @js($isAddCustomerError ? \App\Models\Customer::normalizeWhatsappPhone(old('phone_whatsapp', '')) : ''),
        lat: @js($isAddCustomerError ? old('latitude', '') : ''),
        lng: @js($isAddCustomerError ? old('longitude', '') : ''),
        address: @js($isAddCustomerError ? old('installation_address', '') : ''),
        mapStatus: '',
        normalizeWa(raw, finalize = false) {
            let digits = String(raw || '').replace(/\D+/g, '');
            if (!digits) return '';
            if (digits.startsWith('0062')) {
                digits = '62' + digits.slice(4);
            }
            if (digits.startsWith('6208')) {
                digits = '62' + digits.slice(3);
            } else if (digits.startsWith('08')) {
                digits = '62' + digits.slice(1);
            } else if (finalize && digits.startsWith('0') && digits.length > 1) {
                digits = '62' + digits.slice(1);
            } else if (finalize && digits.startsWith('8') && digits.length >= 8) {
                digits = '62' + digits;
            }
            return digits;
        },
        selectedPackageId: @js($isAddCustomerError ? (string) old('package_id', '') : ''),
        selectedRouterId: @js($isAddCustomerError ? (string) old('mikrotik_router_id', '') : ''),
        packageRoutersMap: @js($packages->mapWithKeys(fn ($p) => [(string) $p->id => $p->routers->pluck('id')->map(fn ($id) => (string) $id)->values()->all()])->all()),
        allRouters: @js($routers->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'ros' => strtoupper($r->ros_version_type)])->values()->all()),
        filteredRouters() {
            const allowed = this.packageRoutersMap[String(this.selectedPackageId)] || [];
            if (allowed.length === 0) return this.allRouters;
            return this.allRouters.filter(r => allowed.includes(String(r.id)));
        },
        onPackageChange() {
            const available = this.filteredRouters();
            if (available.length === 1) {
                this.selectedRouterId = available[0].id;
            } else if (!available.some(r => String(r.id) === String(this.selectedRouterId))) {
                this.selectedRouterId = '';
            }
        },
        routerHint() {
            if (!this.selectedPackageId) return '';
            const allowed = this.packageRoutersMap[String(this.selectedPackageId)] || [];
            if (allowed.length === 0) return 'Package supports all active routers.';
            const available = this.filteredRouters();
            return available.length > 0
                ? 'Showing ' + available.length + ' supported router(s) for this package.'
                : 'No active routers are currently assigned to this package.';
        },
        togglePageSelection(checked) {
            if (checked) {
                this.selectedIds = Array.from(new Set([...this.selectedIds, ...this.pageIds]));
            } else {
                this.selectedIds = this.selectedIds.filter(id => !this.pageIds.includes(id));
            }
        },
        selectActiveOnPage() {
            this.selectedIds = [...this.activePageIds];
        },
        selectedActiveCount() {
            return this.selectedIds.filter(id => this.activePageIds.includes(String(id))).length;
        },
        selectedNonActiveCount() {
            return this.selectedIds.filter(id => this.pageIds.includes(String(id)) && !this.activePageIds.includes(String(id))).length;
        },
        openBulkActivePeriodModal(preferredScope = null) {
            if (preferredScope) {
                this.bulkScope = preferredScope;
            } else {
                this.bulkScope = this.selectedIds.length > 0 ? 'selected' : 'all_active';
            }
            this.bulkActivePeriodOpen = true;
        },
        bulkCanSubmit() {
            const d = parseInt(this.bulkDays, 10);
            if (!Number.isInteger(d) || d < 1 || d > 365) return false;
            if (this.bulkScope === 'selected') {
                return this.selectedIds.length > 0;
            }
            return this.activeSubscriptionsTotal > 0;
        },
        broadcastUrl() {
            const base = '{{ route('admin.whatsapp.broadcast') }}';
            if (this.selectedIds.length === 0) return base;
            return base + '?' + this.selectedIds.map(id => 'customer_ids[]=' + encodeURIComponent(id)).join('&');
        }
     }"
     @keydown.escape.window="bulkActivePeriodOpen = false; editing = null">

    <x-page-header title="Customers List" subtitle="Manage customer details, service packages, PPPoE IPs, installation locations, and internet access status.">
        <x-slot:actions>
            <button type="button" @click="openBulkActivePeriodModal()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span x-text="selectedIds.length > 0 ? 'Bulk Active Period (' + selectedIds.length + ')' : 'Bulk Active Period'">Bulk Active Period</span>
            </button>
            <a :href="broadcastUrl()"
               class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                </svg>
                <span x-text="selectedIds.length > 0 ? 'Send WA (' + selectedIds.length + ' Selected)' : 'Broadcast WA'">Broadcast WA</span>
            </a>
            <button type="button" @click="importOpen = !importOpen"
                    class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                Import Excel
            </button>
            <a href="{{ route('admin.customers.export') }}"
               class="rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 transition">
                Export .xlsx
            </a>
            <button type="button" @click="addOpen = true; $nextTick(() => window.initAddCustomerMap && window.initAddCustomerMap())"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add Customer
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Collapsible Excel Import Bar --}}
    <div x-show="importOpen" x-transition class="bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap items-center justify-between gap-3 shadow-sm" style="display: none;">
        <form method="POST" action="{{ route('admin.customers.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3 flex-1">
            @csrf
            <input type="file" name="file" accept=".xlsx,.csv" required class="flex-1 text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-indigo-700">
            <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                Upload &amp; Import
            </button>
        </form>
        <a href="{{ route('admin.customers.template') }}" class="text-xs font-semibold text-indigo-600 hover:underline">
            Download .xlsx Template
        </a>
    </div>

    {{-- Search Bar --}}
    <form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-2 shadow-sm">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search Customer ID (IQ-...), name, WhatsApp, PPPoE username, or PPPoE IP..."
               class="flex-1 min-w-[220px] rounded-xl border-slate-300 text-sm">
        <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Search</button>
        @if($search !== '')
            <a href="{{ route('admin.customers.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600">Reset</a>
        @endif
        @if(count($activePageIds) > 0)
            <button type="button" @click="selectActiveOnPage()"
                    class="rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                Select Active on Page ({{ count($activePageIds) }})
            </button>
        @endif
    </form>

    {{-- Full-width Customers Table --}}
    @if($customers->isEmpty())
        <x-empty-state title="No registered customers yet" description="Click Add Customer in the top-right corner to register a new customer with their package, PPPoE IP, and portal account.">
            <x-slot:action>
                <button type="button" @click="addOpen = true; $nextTick(() => window.initAddCustomerMap && window.initAddCustomerMap())"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                    + Add Customer
                </button>
            </x-slot:action>
        </x-empty-state>
    @else
        <div x-show="selectedIds.length > 0" x-transition
             class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-5 py-3 text-xs text-indigo-950 shadow-sm"
             style="display: none;">
            <div class="space-y-0.5">
                <span class="font-semibold">
                    <span x-text="selectedIds.length"></span> customer(s) selected
                </span>
                <span class="text-indigo-700" x-show="selectedNonActiveCount() > 0" style="display: none;">
                    &middot; <span x-text="selectedActiveCount()"></span> Active,
                    <span x-text="selectedNonActiveCount()"></span> Non-Active (skipped on bulk active period)
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="selectedIds = []" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">
                    Clear Selection
                </button>
                <button type="button" @click="openBulkActivePeriodModal('selected')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3.5 py-1.5 font-semibold text-white hover:bg-indigo-700 transition">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Bulk Adjust Active Period
                </button>
                <a :href="broadcastUrl()" class="rounded-lg bg-emerald-600 px-3.5 py-1.5 font-semibold text-white hover:bg-emerald-700">
                    Open WA Broadcast Form &rarr;
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3 w-10">
                                <input type="checkbox"
                                       :checked="pageIds.length > 0 && pageIds.every(id => selectedIds.includes(id))"
                                       @change="togglePageSelection($event.target.checked)"
                                       aria-label="Select all customers on this page"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            </th>
                            <th class="px-5 py-3">Customer ID &amp; Name</th>
                            <th class="px-5 py-3">Contact &amp; Portal</th>
                            <th class="px-5 py-3">Package &amp; Router</th>
                            <th class="px-5 py-3">PPPoE &amp; Remote IP</th>
                            <th class="px-5 py-3">Status &amp; Active Until</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($customers as $cust)
                            @php $sub = $cust->subscriptions->first(); @endphp
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="px-4 py-3.5">
                                    <input type="checkbox"
                                           value="{{ $cust->id }}"
                                           x-model="selectedIds"
                                           aria-label="Select {{ $cust->full_name }}"
                                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </td>
                                <td class="px-5 py-3.5">
                                    <a href="{{ route('admin.customers.show', $cust) }}" class="font-mono text-xs font-bold text-indigo-600 hover:underline">
                                        {{ $cust->customer_code }}
                                    </a>
                                    <div class="font-semibold text-slate-900">{{ $cust->full_name }}</div>
                                    <div class="text-xs text-slate-500">{{ Str::limit($cust->installation_address, 45) }}</div>
                                </td>
                                <td class="px-5 py-3.5 text-xs">
                                    <div class="font-mono text-slate-800">{{ $cust->phone_whatsapp }}</div>
                                    <div class="text-slate-500">Portal: <span class="font-mono font-semibold">{{ $cust->user?->username ?? '-' }}</span></div>
                                </td>
                                <td class="px-5 py-3.5 text-xs">
                                    @if($sub)
                                        <div class="font-semibold text-slate-900">{{ $sub->package->name ?? '-' }}</div>
                                        <div class="text-slate-500">{{ $sub->mikrotikRouter->name ?? '-' }}</div>
                                    @else
                                        <span class="text-slate-400">No package assigned</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 font-mono text-xs">
                                    @if($sub)
                                        <div class="text-slate-800">{{ $sub->service_username }}</div>
                                        <div class="text-indigo-600">{{ $sub->static_ip_address ?: 'Dynamic Pool' }}</div>
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5">
                                    @if($sub)
                                        <x-status-badge :status="$sub->status" />
                                        <div class="mt-1 text-[11px] text-slate-500">
                                            Until <span class="font-semibold text-slate-700">{{ $sub->next_billing_date->format('d M Y') }}</span>
                                        </div>
                                    @else
                                        <x-status-badge status="pending_installation" />
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                    <a href="{{ route('admin.customers.show', $cust) }}"
                                       class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                                        Details
                                    </a>
                                    <button type="button"
                                            @click="editing = {{ Js::from($cust->only(['id', 'customer_code', 'full_name', 'phone_whatsapp', 'email', 'nik_npwp', 'installation_address', 'latitude', 'longitude'])) }}"
                                            class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                        Edit
                                    </button>
                                    <form method="POST" action="{{ route('admin.customers.destroy', $cust) }}" class="inline" onsubmit="return confirm('Delete this customer?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $customers->links() }}
    @endif

    {{-- MODAL ADD CUSTOMER --}}
    <div x-show="addOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="mx-auto my-6 w-full max-w-4xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden" @click.outside="addOpen = false">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Add New Customer</h3>
                    <p class="text-xs text-slate-500">Fill in customer details, map pin address, package, PPPoE IP, initial payment method, and portal account.</p>
                </div>
                <button type="button" @click="addOpen = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.customers.store') }}" @submit="phoneWhatsapp = normalizeWa(phoneWhatsapp, true)" class="p-6 space-y-6">
                @csrf

                @if($isAddCustomerError)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-700 space-y-1">
                        <div class="font-bold text-rose-800">Failed to save customer. Please review the following errors:</div>
                        <ul class="list-disc list-inside">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- 1. Customer Identity --}}
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600">1. Customer Identity</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Customer ID (Optional)</label>
                            <input type="text" name="customer_code" x-model="customerCode"
                                   placeholder="Blank = auto IQ-{{ now('Asia/Jakarta')->format('ymd') }}xxx"
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono @error('customer_code') border-rose-500 @enderror">
                            @error('customer_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Full Name *</label>
                            <input type="text" name="full_name" value="{{ old('full_name') }}" required
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                        <div>
                            <label for="add-customer-wa" class="block text-xs font-medium text-slate-700">WhatsApp No. * (628xxx)</label>
                            <input id="add-customer-wa"
                                   type="text"
                                   inputmode="numeric"
                                   name="phone_whatsapp"
                                   x-model="phoneWhatsapp"
                                   @input="phoneWhatsapp = normalizeWa($event.target.value, false); $event.target.value = phoneWhatsapp"
                                   @blur="phoneWhatsapp = normalizeWa($event.target.value, true); $event.target.value = phoneWhatsapp"
                                   placeholder="6282281501145"
                                   required
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono @error('phone_whatsapp') border-rose-500 @enderror">
                            <p class="mt-1 text-[11px] text-slate-500">Auto-normalized to <span class="font-mono">628xxxxxxxxxx</span> (e.g. <span class="font-mono">0822...</span> / <span class="font-mono">+628-...</span>).</p>
                            @error('phone_whatsapp') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Email (Optional)</label>
                            <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">National ID / Tax ID (Optional)</label>
                            <input type="text" name="nik_npwp" value="{{ old('nik_npwp') }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                    </div>
                </div>

                {{-- 2. Address & Google Maps Picker --}}
                <div class="space-y-3 border-t border-slate-100 pt-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600">2. Address &amp; Map Pin (Google Maps)</h4>
                        @if(!empty($googleMapsApiKey))
                            <button type="button" onclick="window.geolocateCustomerPin && window.geolocateCustomerPin()"
                                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                                Use Current Location
                            </button>
                        @endif
                    </div>

                    @if(!empty($googleMapsApiKey))
                        <div class="space-y-2">
                            <div class="flex gap-2">
                                <input id="gmaps-search-input" type="text"
                                       @keydown.enter.prevent="window.searchCustomerAddress && window.searchCustomerAddress()"
                                       placeholder="Search location, landmark, or street in Google Maps..."
                                       class="flex-1 rounded-xl border-slate-300 text-sm">
                                <button type="button" onclick="window.searchCustomerAddress && window.searchCustomerAddress()"
                                        class="rounded-xl bg-slate-800 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-900 shrink-0">
                                    Search Location
                                </button>
                            </div>
                            <div id="gmaps-canvas" class="h-56 w-full rounded-xl border border-slate-200 bg-slate-100"></div>
                            <p class="text-[11px] font-medium" :class="mapStatus.includes('Failed') || mapStatus.includes('denied') || mapStatus.includes('HTTPS') ? 'text-rose-600' : 'text-slate-500'"
                               x-text="mapStatus || 'Click on the map or drag the red pin to auto-fill coordinates & address.'"></p>
                        </div>
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800">
                            Google Maps API Key is not configured in <a href="{{ route('admin.settings.payment.edit') }}" class="font-semibold underline">System Settings</a>. You can still enter the address and coordinates manually.
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-3">
                            <label class="block text-xs font-medium text-slate-700">Installation Address *</label>
                            <textarea name="installation_address" x-model="address" rows="2" required
                                      placeholder="Auto-filled from map pin or enter manually..."
                                      class="mt-1 w-full rounded-xl border-slate-300 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Latitude</label>
                            <input type="text" name="latitude" x-model="lat" placeholder="-6.2000000" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Longitude</label>
                            <input type="text" name="longitude" x-model="lng" placeholder="106.8166667" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                        </div>
                    </div>
                </div>

                {{-- 3. Package, Router & PPPoE --}}
                <div class="space-y-3 border-t border-slate-100 pt-5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600">3. Service Package, MikroTik &amp; PPPoE IP</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700">Select Package *</label>
                            <select name="package_id" x-model="selectedPackageId" @change="onPackageChange()" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                                <option value="">-- Select Package --</option>
                                @foreach($packages as $p)
                                    <option value="{{ $p->id }}" @selected(old('package_id') == $p->id)>
                                        {{ $p->name }} — Rp {{ number_format((float) $p->price, 0, ',', '.') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">MikroTik Router *</label>
                            <select name="mikrotik_router_id"
                                    x-model="selectedRouterId"
                                    x-effect="$nextTick(() => { $el.value = String(selectedRouterId || ''); })"
                                    required
                                    class="mt-1 w-full rounded-xl border-slate-300 text-sm @error('mikrotik_router_id') border-rose-500 @enderror">
                                <option value="">-- Select Router --</option>
                                <template x-for="r in filteredRouters()" :key="r.id">
                                    <option :value="r.id" x-text="r.name + ' (' + r.ros + ')'" :selected="String(selectedRouterId) === String(r.id)"></option>
                                </template>
                            </select>
                            <p x-show="routerHint()" x-text="routerHint()" class="mt-1 text-[11px] text-slate-500" style="display: none;"></p>
                            @error('mikrotik_router_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">PPPoE IP (Remote Address)</label>
                            <input type="text" name="static_ip_address" value="{{ old('static_ip_address') }}" placeholder="10.10.10.25"
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono @error('static_ip_address') border-rose-500 @enderror">
                            @error('static_ip_address') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">PPPoE Username (Blank = follow Portal)</label>
                            <input type="text" name="service_username" value="{{ old('service_username') }}" placeholder="Auto if left blank"
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">PPPoE Password (Blank = 12345678)</label>
                            <input type="text" name="service_password" value="{{ old('service_password') }}" placeholder="12345678"
                                   class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-medium text-slate-700">Isolation Day (1-28)</label>
                                <input type="number" name="isolir_day" value="{{ old('isolir_day', now('Asia/Jakarta')->day > 28 ? 28 : now('Asia/Jakarta')->day) }}" min="1" max="28"
                                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700">Isolation Profile</label>
                                <input type="text" name="isolir_profile_name" value="{{ old('isolir_profile_name') }}" placeholder="Router default"
                                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 4. Initial Payment & Portal Account --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 border-t border-slate-100 pt-5">
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600">4. Initial Installation Payment *</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="cursor-pointer rounded-xl border border-slate-200 p-3 hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50/50">
                                <input type="radio" name="payment_method" value="online" @checked(old('payment_method', 'online') === 'online') class="text-indigo-600">
                                <span class="ml-1.5 text-sm font-semibold text-slate-900">Online (QRIS)</span>
                                <p class="mt-1 text-[11px] text-slate-500">Send WhatsApp invoice + payment link to the customer.</p>
                            </label>
                            <label class="cursor-pointer rounded-xl border border-slate-200 p-3 hover:border-emerald-400 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/50">
                                <input type="radio" name="payment_method" value="cash" @checked(old('payment_method') === 'cash') class="text-emerald-600">
                                <span class="ml-1.5 text-sm font-semibold text-slate-900">Cash (Instant Active)</span>
                                <p class="mt-1 text-[11px] text-slate-500">Activate package immediately without sending an installation WA bill.</p>
                            </label>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600">5. Customer Portal Account</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700">Portal Username</label>
                                <input type="text" name="portal_username" x-model="portalUsername"
                                       placeholder="Default: ID without 'IQ'"
                                       class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700">Portal Password</label>
                                <input type="text" name="portal_password" value="{{ old('portal_password') }}"
                                       placeholder="Default: 12345678"
                                       class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                    <button type="button" @click="addOpen = false" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 shadow-sm">
                        Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit Customer Basic Info Modal --}}
    <div x-show="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4" @click.outside="editing = null">
            <h3 class="text-lg font-bold text-slate-900">Edit Customer Details</h3>
            <template x-if="editing">
                <form method="POST" :action="'{{ url('/admin/customers') }}/' + editing.id" @submit="editing.phone_whatsapp = normalizeWa(editing.phone_whatsapp, true)" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <input type="text" name="customer_code" x-model="editing.customer_code" required class="w-full rounded-xl border-slate-300 text-sm font-mono">
                    <input type="text" name="full_name" x-model="editing.full_name" required class="w-full rounded-xl border-slate-300 text-sm">
                    <input type="text"
                           inputmode="numeric"
                           name="phone_whatsapp"
                           x-model="editing.phone_whatsapp"
                           @input="editing.phone_whatsapp = normalizeWa($event.target.value, false); $event.target.value = editing.phone_whatsapp"
                           @blur="editing.phone_whatsapp = normalizeWa($event.target.value, true); $event.target.value = editing.phone_whatsapp"
                           required
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                    <input type="email" name="email" x-model="editing.email" class="w-full rounded-xl border-slate-300 text-sm">
                    <textarea name="installation_address" x-model="editing.installation_address" rows="2" required class="w-full rounded-xl border-slate-300 text-sm"></textarea>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="latitude" x-model="editing.latitude" placeholder="Latitude" class="w-full rounded-xl border-slate-300 text-sm font-mono">
                        <input type="text" name="longitude" x-model="editing.longitude" placeholder="Longitude" class="w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="editing = null" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    {{-- Bulk Adjust Active Period Modal (Active Customers Only) --}}
    <div x-show="bulkActivePeriodOpen"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
         style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
             @click.outside="bulkActivePeriodOpen = false">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Bulk Adjust Active Period</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Add or subtract days from each customer's current active period. Only <strong>Active</strong> subscriptions will be updated.
                    </p>
                </div>
                <button type="button" @click="bulkActivePeriodOpen = false"
                        class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition"
                        aria-label="Close modal">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.customers.bulk-active-period') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="form_context" value="bulk_active_period">

                @if($isBulkActivePeriodError)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 space-y-1">
                        <div class="font-bold text-rose-800">Unable to apply bulk adjustment:</div>
                        <ul class="list-disc list-inside">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <template x-if="bulkScope === 'selected'">
                    <div>
                        <template x-for="id in selectedIds" :key="'bulk-cust-' + id">
                            <input type="hidden" name="customer_ids[]" :value="id">
                        </template>
                    </div>
                </template>

                {{-- 1. Target Customers --}}
                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-700">Target Customers (Active Status Only)</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label :class="bulkScope === 'selected' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:bg-slate-50'"
                               class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer transition">
                            <input type="radio" name="target_scope" value="selected" x-model="bulkScope"
                                   class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <div class="text-xs">
                                <div class="font-semibold text-slate-900">Selected Customers</div>
                                <div class="text-slate-500 mt-0.5">
                                    <span x-text="selectedIds.length"></span> selected
                                    <template x-if="selectedIds.length > 0">
                                        <span>(<span x-text="selectedActiveCount()"></span> active)</span>
                                    </template>
                                </div>
                            </div>
                        </label>
                        <label :class="bulkScope === 'all_active' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:bg-slate-50'"
                               class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer transition">
                            <input type="radio" name="target_scope" value="all_active" x-model="bulkScope"
                                   class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <div class="text-xs">
                                <div class="font-semibold text-slate-900">All Active Customers</div>
                                <div class="text-slate-500 mt-0.5">{{ $activeSubscriptionsCount }} active subscription(s)</div>
                            </div>
                        </label>
                    </div>

                    <div x-show="bulkScope === 'selected' && selectedIds.length === 0"
                         class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                         style="display: none;">
                        No customers are checked yet. Check customers in the table first, or switch to <strong>All Active Customers</strong>.
                    </div>

                    <div x-show="bulkScope === 'selected' && selectedNonActiveCount() > 0"
                         class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                         style="display: none;">
                        <strong x-text="selectedNonActiveCount()"></strong> selected customer(s) are not Active (e.g. isolated/pending) and will be skipped automatically.
                    </div>
                </div>

                {{-- 2. Operation Type --}}
                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-700">Adjustment Direction</label>
                    <input type="hidden" name="operation" :value="bulkOperation">
                    <div class="grid grid-cols-2 gap-2.5">
                        <button type="button" @click="bulkOperation = 'add'"
                                :class="bulkOperation === 'add' ? 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                class="rounded-xl border px-3 py-2.5 text-xs font-semibold transition">
                            + Extend (Add Days)
                        </button>
                        <button type="button" @click="bulkOperation = 'subtract'"
                                :class="bulkOperation === 'subtract' ? 'border-rose-500 bg-rose-50 text-rose-800 ring-1 ring-rose-500' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                class="rounded-xl border px-3 py-2.5 text-xs font-semibold transition">
                            &minus; Reduce (Subtract Days)
                        </button>
                    </div>
                </div>

                {{-- 3. Number of Days --}}
                <div class="space-y-2">
                    <label for="bulk-active-period-days" class="block text-xs font-semibold text-slate-700">
                        Number of Days
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="number"
                               id="bulk-active-period-days"
                               name="days"
                               x-model="bulkDays"
                               min="1"
                               max="365"
                               required
                               class="w-32 rounded-xl border-slate-300 text-sm font-mono font-bold focus:border-indigo-500 focus:ring-indigo-500">
                        <span class="text-xs text-slate-500">day(s)</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-medium text-slate-500 mr-1">Quick preset:</span>
                        @foreach([1, 3, 7, 14, 30] as $preset)
                            <button type="button" @click="bulkDays = '{{ $preset }}'"
                                    :class="String(bulkDays) === '{{ $preset }}' ? 'border-indigo-400 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100'"
                                    class="rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition">
                                {{ $preset }}d
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Live Summary Preview --}}
                <div :class="bulkOperation === 'add' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'"
                     class="rounded-xl border p-3 text-xs space-y-1">
                    <div class="font-bold">
                        <span x-show="bulkOperation === 'add'">Preview: Extend Active Period by +<span x-text="bulkDays || 0"></span> day(s)</span>
                        <span x-show="bulkOperation === 'subtract'" style="display: none;">Preview: Reduce Active Period by &minus;<span x-text="bulkDays || 0"></span> day(s)</span>
                    </div>
                    <p class="opacity-90">
                        Each eligible <strong>Active</strong> subscription's current expiry date will be shifted relatively by
                        <strong x-text="(bulkOperation === 'add' ? '+' : '-') + (bulkDays || 0) + ' day(s)'"></strong>.
                        All changes are recorded in the Audit Log.
                    </p>
                </div>

                {{-- 4. Optional Reason --}}
                <div>
                    <label for="bulk-active-period-reason" class="block text-xs font-semibold text-slate-700 mb-1">
                        Reason / Note <span class="font-normal text-slate-400">(optional, recorded in audit log)</span>
                    </label>
                    <input type="text"
                           id="bulk-active-period-reason"
                           name="reason"
                           x-model="bulkReason"
                           maxlength="500"
                           placeholder="e.g. Network outage compensation (+7 days)"
                           class="w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" @click="bulkActivePeriodOpen = false"
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button type="submit"
                            :disabled="!bulkCanSubmit()"
                            :class="[
                                bulkOperation === 'add' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700',
                                !bulkCanSubmit() ? 'opacity-50 cursor-not-allowed' : ''
                            ]"
                            class="rounded-xl px-4 py-2 text-xs font-semibold text-white shadow-sm transition">
                        <span x-show="bulkOperation === 'add'">Apply +<span x-text="bulkDays || 0"></span> Day(s)</span>
                        <span x-show="bulkOperation === 'subtract'" style="display: none;">Apply &minus;<span x-text="bulkDays || 0"></span> Day(s)</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if(!empty($googleMapsApiKey))
<style>
    .pac-container { z-index: 9999 !important; }
</style>
<script>
    window._gmapsLoaded = false;
    window._gmapsInstance = null;
    window._gmapsMarker = null;
    window._gmapsGeocoder = null;

    window.getCustomerAlpine = function () {
        const root = document.getElementById('customers-page-root');
        return root && window.Alpine ? Alpine.$data(root) : null;
    };

    window.setCustomerPinAndReverseGeocode = function (latLng) {
        const alpineData = window.getCustomerAlpine();
        const latNum = typeof latLng.lat === 'function' ? latLng.lat() : parseFloat(latLng.lat);
        const lngNum = typeof latLng.lng === 'function' ? latLng.lng() : parseFloat(latLng.lng);
        const lat = latNum.toFixed(7);
        const lng = lngNum.toFixed(7);

        if (alpineData) {
            alpineData.lat = lat;
            alpineData.lng = lng;
            alpineData.mapStatus = `Coordinates: ${lat}, ${lng} — fetching address...`;
        }

        if (window._gmapsMarker && window._gmapsInstance) {
            window._gmapsMarker.setPosition({ lat: latNum, lng: lngNum });
            window._gmapsInstance.panTo({ lat: latNum, lng: lngNum });
            window._gmapsInstance.setZoom(16);
        }

        if (window._gmapsGeocoder) {
            window._gmapsGeocoder.geocode({ location: { lat: latNum, lng: lngNum } }, (results, status) => {
                const state = window.getCustomerAlpine();
                if (!state) return;
                if (status === 'OK' && results && results[0]) {
                    state.address = results[0].formatted_address;
                    state.mapStatus = `Pin set: ${lat}, ${lng}`;
                } else {
                    // Fallback when Google Geocoding API is not enabled on the configured API key
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latNum}&lon=${lngNum}`)
                        .then((r) => r.json())
                        .then((data) => {
                            const s = window.getCustomerAlpine();
                            if (!s) return;
                            if (data && data.display_name) {
                                s.address = data.display_name;
                                s.mapStatus = `Pin set: ${lat}, ${lng}`;
                            } else {
                                s.mapStatus = `Pin set (${lat}, ${lng}), address not found (${status}).`;
                            }
                        })
                        .catch(() => {
                            const s = window.getCustomerAlpine();
                            if (s) s.mapStatus = `Pin set (${lat}, ${lng}), enter address manually (${status}).`;
                        });
                }
            });
        }
    };

    window.searchCustomerAddress = function () {
        const input = document.getElementById('gmaps-search-input');
        const alpineData = window.getCustomerAlpine();
        const query = input ? input.value.trim() : '';
        if (!query) {
            if (alpineData) alpineData.mapStatus = 'Please enter a street or place name first.';
            return;
        }
        if (!window._gmapsGeocoder && typeof google !== 'undefined' && google.maps) {
            window._gmapsGeocoder = new google.maps.Geocoder();
        }
        if (alpineData) alpineData.mapStatus = `Searching "${query}"...`;

        const fallbackNominatim = () => {
            fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`)
                .then((r) => r.json())
                .then((items) => {
                    const state = window.getCustomerAlpine();
                    if (items && items[0]) {
                        const lat = parseFloat(items[0].lat);
                        const lng = parseFloat(items[0].lon);
                        window.setCustomerPinAndReverseGeocode({ lat, lng });
                        if (state && items[0].display_name) {
                            state.address = items[0].display_name;
                        }
                    } else if (state) {
                        state.mapStatus = `Failed to find location "${query}".`;
                    }
                })
                .catch(() => {
                    const state = window.getCustomerAlpine();
                    if (state) state.mapStatus = `Failed to contact location search service.`;
                });
        };

        if (!window._gmapsGeocoder) {
            fallbackNominatim();
            return;
        }

        window._gmapsGeocoder.geocode({ address: query }, (results, status) => {
            const state = window.getCustomerAlpine();
            if (status === 'OK' && results && results[0]) {
                const loc = results[0].geometry.location;
                window.setCustomerPinAndReverseGeocode(loc);
                if (state && results[0].formatted_address) {
                    state.address = results[0].formatted_address;
                }
            } else {
                fallbackNominatim();
            }
        });
    };

    window.geolocateCustomerPin = function () {
        const alpineData = window.getCustomerAlpine();
        if (!navigator.geolocation) {
            if (alpineData) alpineData.mapStatus = 'Failed: Browser does not support Geolocation.';
            return;
        }
        if (window.isSecureContext === false) {
            if (alpineData) alpineData.mapStatus = 'Failed: Browser requires HTTPS or http://localhost / http://127.0.0.1 for location access.';
            return;
        }
        if (alpineData) alpineData.mapStatus = 'Detecting device location...';

        const handleSuccess = (pos) => {
            window.setCustomerPinAndReverseGeocode({
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
            });
        };

        const handleFinalError = (err) => {
            const state = window.getCustomerAlpine();
            if (!state) return;
            const detail = err?.message ? ` (${err.message})` : '';
            if (err.code === 1) {
                state.mapStatus = `Location access denied by browser${detail}. Ensure Location permission is enabled in your browser & OS.`;
            } else if (err.code === 2) {
                state.mapStatus = `Failed: Device location information is unavailable${detail}.`;
            } else {
                state.mapStatus = `Failed: Location detection timed out${detail}.`;
            }
        };

        navigator.geolocation.getCurrentPosition(
            handleSuccess,
            (err) => {
                // On desktops/laptops without GPS hardware, fall back to network accuracy (Wi-Fi/IP) when high accuracy fails/times out
                if (err && (err.code === 2 || err.code === 3)) {
                    navigator.geolocation.getCurrentPosition(
                        handleSuccess,
                        handleFinalError,
                        { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
                    );
                    return;
                }
                handleFinalError(err);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    };

    window.initAddCustomerMap = function () {
        if (typeof google === 'undefined' || !google.maps) {
            return;
        }
        const canvas = document.getElementById('gmaps-canvas');
        if (!canvas) return;

        const alpineData = window.getCustomerAlpine();
        const startPos = {
            lat: parseFloat(alpineData?.lat) || -6.200000,
            lng: parseFloat(alpineData?.lng) || 106.816666
        };

        if (window._gmapsLoaded && window._gmapsInstance) {
            google.maps.event.trigger(window._gmapsInstance, 'resize');
            window._gmapsInstance.setCenter(startPos);
            return;
        }

        const map = new google.maps.Map(canvas, { center: startPos, zoom: 14 });
        const marker = new google.maps.Marker({ position: startPos, map: map, draggable: true });
        const geocoder = new google.maps.Geocoder();

        window._gmapsInstance = map;
        window._gmapsMarker = marker;
        window._gmapsGeocoder = geocoder;
        window._gmapsLoaded = true;

        map.addListener('click', (e) => window.setCustomerPinAndReverseGeocode(e.latLng));
        marker.addListener('dragend', (e) => window.setCustomerPinAndReverseGeocode(e.latLng));

        const searchInput = document.getElementById('gmaps-search-input');
        if (searchInput && google.maps.places && google.maps.places.Autocomplete) {
            const autocomplete = new google.maps.places.Autocomplete(searchInput);
            autocomplete.addListener('place_changed', () => {
                const place = autocomplete.getPlace();
                const state = window.getCustomerAlpine();
                if (place && place.geometry && place.geometry.location) {
                    window.setCustomerPinAndReverseGeocode(place.geometry.location);
                    if (state && place.formatted_address) {
                        state.address = place.formatted_address;
                    }
                } else {
                    window.searchCustomerAddress();
                }
            });
        }
    };
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key={{ urlencode($googleMapsApiKey) }}&libraries=places&callback=initAddCustomerMap"></script>
@endif
@endsection
