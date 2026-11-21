@extends('layouts.app', ['title' => 'Billing Cycles & Isolation Schedule'])

@section('content')
<div class="space-y-6"
     x-data="{
        editing: null,
        packageRoutersMap: @js($packages->mapWithKeys(fn ($p) => [(string) $p->id => $p->routers->pluck('id')->map(fn ($id) => (string) $id)->values()->all()])->all()),
        allRouters: @js($routers->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'ros' => strtoupper($r->ros_version_type)])->values()->all()),
        filteredEditRouters() {
            if (!this.editing) return this.allRouters;
            const allowed = this.packageRoutersMap[String(this.editing.package_id)] || [];
            if (allowed.length === 0) return this.allRouters;
            return this.allRouters.filter(r => allowed.includes(String(r.id)));
        },
        onEditPackageChange() {
            if (!this.editing) return;
            const available = this.filteredEditRouters();
            if (available.length === 1) {
                this.editing.mikrotik_router_id = available[0].id;
            } else if (!available.some(r => String(r.id) === String(this.editing.mikrotik_router_id))) {
                this.editing.mikrotik_router_id = '';
            }
        },
        editRouterMismatchNotice() {
            if (!this.editing || !this.editing.mikrotik_router_id) return false;
            const allowed = this.packageRoutersMap[String(this.editing.package_id)] || [];
            return allowed.length > 0 && !allowed.includes(String(this.editing.mikrotik_router_id));
        }
     }">
    <x-page-header title="Cycle Monitoring & Isolation Schedule" subtitle="Monitor active service dates, isolation schedule (23:59 WIB), and manual cash renewals (Receive Cash) across all connections. New customers are onboarded via the Customers menu.">
        <x-slot:actions>
            <a href="{{ route('admin.customers.index') }}" class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Open Customers List
            </a>
            <form method="GET" class="flex items-center gap-2">
                <select name="status" class="rounded-xl border-slate-300 text-sm">
                    <option value="">All Statuses</option>
                    @foreach(['pending_installation', 'active', 'isolated', 'suspended', 'terminated'] as $opt)
                        <option value="{{ $opt }}" @selected($status === $opt)>{{ strtoupper($opt) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-xl bg-slate-800 px-3.5 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">
        @if($subscriptions->isEmpty())
            <x-empty-state title="No subscriptions yet"
                           description="New customer onboarding and PPPoE service provisioning are handled via the Customers menu."
                           actionLabel="Open Customers List"
                           :actionHref="route('admin.customers.index')" />
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Customer &amp; PPPoE</th>
                                <th class="px-5 py-3">Router &amp; Package</th>
                                <th class="px-5 py-3">Isolation &amp; Cycle</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($subscriptions as $sub)
                                <tr>
                                    <td class="px-5 py-3.5">
                                        <div class="font-semibold text-slate-900">{{ $sub->customer->full_name }}</div>
                                        <div class="font-mono text-xs text-slate-500">{{ $sub->service_username }}</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        <div class="font-medium text-slate-800">{{ $sub->package->name }}</div>
                                        <div class="text-slate-500">{{ $sub->mikrotikRouter->name }}</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        <div>Active until: <span class="font-medium">{{ $sub->next_billing_date->format('d M Y') }}</span></div>
                                        <div class="text-slate-500">
                                            Isolation: {{ $sub->isolir_day ? "Day {$sub->isolir_day} 23:59" : 'Follow Grace Period' }}
                                            &middot; {{ $sub->auto_isolate ? 'Auto ON' : 'Auto OFF' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 space-y-1">
                                        <x-status-badge :status="$sub->status" />
                                        @if($sub->hasPendingMikrotikSync())
                                            <div class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 ring-1 ring-amber-200"
                                                 title="{{ $sub->mikrotik_last_sync_error }}">
                                                Sync Router: {{ $sub->mikrotik_sync_status }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div class="inline-flex flex-wrap justify-end gap-1.5">
                                            @if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Finance))
                                                <form method="POST" action="{{ route('admin.subscriptions.receive-cash', $sub) }}"
                                                      onsubmit="return confirm('Record cash payment and extend active period by 1 month?')">
                                                    @csrf
                                                    <button type="submit" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
                                                        Receive Cash
                                                    </button>
                                                </form>
                                            @endif

                                            @if($sub->status->value === 'pending_installation')
                                                <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white">Activate</button>
                                                </form>
                                            @endif

                                            @if($sub->hasPendingMikrotikSync())
                                                <form method="POST" action="{{ route('admin.subscriptions.reconcile', $sub) }}">
                                                    @csrf
                                                    <button type="submit" class="rounded-lg bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-700">
                                                        Sync Router
                                                    </button>
                                                </form>
                                            @endif

                                            @if($sub->status->value === 'isolated')
                                                <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="restore">
                                                    <button type="submit" class="rounded-lg border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Restore</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('admin.subscriptions.provision', $sub) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="isolate">
                                                    <button type="submit" class="rounded-lg border border-rose-300 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">Isolate</button>
                                                </form>
                                            @endif

                                            <button type="button"
                                                    @click="editing = {{ Js::from([
                                                        'id' => $sub->id,
                                                        'package_id' => (string) $sub->package_id,
                                                        'mikrotik_router_id' => (string) $sub->mikrotik_router_id,
                                                        'service_username' => $sub->service_username,
                                                        'static_ip_address' => $sub->static_ip_address,
                                                        'isolir_profile_name' => $sub->isolir_profile_name,
                                                        'billing_day' => $sub->billing_day,
                                                        'isolir_day' => $sub->isolir_day,
                                                        'auto_isolate' => $sub->auto_isolate,
                                                        'next_billing_date' => $sub->next_billing_date->toDateString(),
                                                    ]) }}"
                                                    class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                                Edit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $subscriptions->links() }}
        @endif
    </div>

    {{-- Edit Modal --}}
    <div x-show="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="editing = null">
            <h3 class="text-lg font-bold text-slate-900">Edit Subscription</h3>
            <template x-if="editing">
                <form method="POST" :action="'{{ url('/admin/subscriptions') }}/' + editing.id" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="text-xs font-medium text-slate-600">Service Package</label>
                        <select name="package_id" x-model="editing.package_id" @change="onEditPackageChange()" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            @foreach($packages as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">MikroTik Router</label>
                        <select name="mikrotik_router_id"
                                x-model="editing.mikrotik_router_id"
                                x-effect="$nextTick(() => { if (editing) $el.value = String(editing.mikrotik_router_id || ''); })"
                                required
                                class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            <option value="">-- Select Router --</option>
                            <template x-for="r in filteredEditRouters()" :key="r.id">
                                <option :value="r.id" x-text="r.name + ' (' + r.ros + ')'" :selected="String(editing.mikrotik_router_id) === String(r.id)"></option>
                            </template>
                        </select>
                        <p x-show="editRouterMismatchNotice()" class="mt-1 text-[11px] text-amber-700" style="display: none;">
                            The previously assigned router is not enabled for this package — please choose a supported router.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="service_username" x-model="editing.service_username" required class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="password" name="service_password" placeholder="New password (optional)" class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="static_ip_address" x-model="editing.static_ip_address" placeholder="Static IP" class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="text" name="isolir_profile_name" x-model="editing.isolir_profile_name" placeholder="Isolation Profile" class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <input type="number" name="billing_day" x-model="editing.billing_day" min="1" max="28" required class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="number" name="isolir_day" x-model="editing.isolir_day" min="1" max="28" placeholder="Isolation Day" class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="date" name="next_billing_date" x-model="editing.next_billing_date" required class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="auto_isolate" value="1" x-model="editing.auto_isolate" class="rounded border-slate-300"> Auto Isolate
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="editing = null" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
