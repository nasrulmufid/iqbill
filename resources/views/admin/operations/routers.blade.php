@extends('layouts.app', ['title' => 'MikroTik Routers (v6 & v7)'])

@section('content')
<div class="space-y-6"
     x-data="{ addOpen: {{ ($errors->any() && old('_method') !== 'PUT') ? 'true' : 'false' }}, editing: null }"
     @keydown.escape.window="addOpen = false; editing = null">

    <x-page-header title="Multi-Router MikroTik" subtitle="1-way sync: Application database is the source of truth; MikroTik profiles & secrets automatically follow the application.">
        <x-slot:actions>
            @if(($pendingSubscriptions ?? collect())->isNotEmpty())
                <form method="POST" action="{{ route('admin.mikrotik.reconcile') }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 transition">
                        Sync {{ $pendingSubscriptions->count() }} Pending
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.mikrotik.backups') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                Backup .rsc
            </a>
            <button type="button" @click="addOpen = true"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add Router
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">
        @if($routers->isEmpty())
            <x-empty-state title="No MikroTik routers yet" description="Add a RouterOS v6 or v7 router to start automatic PPPoE provisioning and isolation.">
                <x-slot:action>
                    <button type="button" @click="addOpen = true"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Add Router
                    </button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Router &amp; Version</th>
                                <th class="px-5 py-3">Address &amp; Ports</th>
                                <th class="px-5 py-3">Firewall / List</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($routers as $router)
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-slate-900">{{ $router->name }}</span>
                                            @if(!$router->is_reachable)
                                                <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-700 ring-1 ring-rose-200">
                                                    Offline
                                                </span>
                                            @elseif($router->last_health_check_at)
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700 ring-1 ring-emerald-200">
                                                    Online
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span class="rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-[10px] font-bold text-indigo-700">
                                                ROS {{ strtoupper($router->ros_version_type) }} ({{ $router->ros_version_type === 'v7' ? 'REST' : 'API' }})
                                            </span>
                                            @if($router->ros_version)
                                                <span class="text-xs text-slate-400">v{{ $router->ros_version }}</span>
                                            @endif
                                            @if(($router->pending_sync_count ?? 0) > 0)
                                                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 ring-1 ring-amber-200">
                                                    {{ $router->pending_sync_count }} Pending Sync
                                                </span>
                                            @endif
                                        </div>
                                        @if(!$router->is_reachable && $router->last_health_error)
                                            <div class="mt-1 text-[11px] text-rose-600 line-clamp-1" title="{{ $router->last_health_error }}">
                                                {{ Str::limit($router->last_health_error, 65) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 font-mono text-xs text-slate-700">
                                        <div>{{ $router->host }}:{{ $router->ros_version_type === 'v7' ? $router->rest_port : $router->api_port }}</div>
                                        <div class="text-slate-400">{{ $router->subscriptions_count }} subscriptions</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        <div class="font-mono font-semibold">{{ $router->isolir_address_list }}</div>
                                        <div class="text-slate-500">
                                            {{ $router->default_firewall_installed_at ? 'FW installed ' . $router->default_firewall_installed_at->format('d/m') : 'FW not installed' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div class="inline-flex flex-wrap justify-end gap-1.5">
                                            <form method="POST" action="{{ route('admin.mikrotik.test-connection', $router) }}">
                                                @csrf
                                                <button type="submit" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50">Test</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.mikrotik.sync-from-db', $router) }}"
                                                  onsubmit="return confirm('Synchronize package profiles and all PPPoE secrets on {{ $router->name }} to match the application database (1-way sync)?')">
                                                @csrf
                                                <button type="submit" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                                                    Sync DB &rarr; MikroTik
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.mikrotik.setup-firewall', $router) }}">
                                                @csrf
                                                <button type="submit" class="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                                                    Setup Firewall
                                                </button>
                                            </form>
                                            <button type="button"
                                                    @click="editing = {{ Js::from($router->only(['id', 'name', 'ros_version_type', 'host', 'api_port', 'rest_port', 'ssh_port', 'username', 'isolir_address_list', 'isolir_profile_name', 'use_ssl', 'is_active'])) }}"
                                                    class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                                Edit
                                            </button>
                                            <form method="POST" action="{{ route('admin.routers.destroy', $router) }}" onsubmit="return confirm('Delete this router?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if(($pendingSubscriptions ?? collect())->isNotEmpty())
            <div class="bg-white rounded-2xl border border-amber-200 overflow-hidden shadow-sm">
                <div class="px-5 py-4 border-b border-amber-100 bg-amber-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-amber-900 text-sm">Pending Router Sync Queue ({{ $pendingSubscriptions->count() }})</h3>
                        <p class="text-xs text-amber-800">The subscriptions below are updated in billing, while MikroTik execution is queued because the router was offline. The scheduler automatically reconciles every 2 minutes.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.mikrotik.reconcile') }}">
                        @csrf
                        <button type="submit" class="rounded-xl bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-amber-700">
                            Reconcile All Now
                        </button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-50 uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-2.5">Customer &amp; PPPoE</th>
                                <th class="px-5 py-2.5">Router</th>
                                <th class="px-5 py-2.5">Pending Action</th>
                                <th class="px-5 py-2.5">Last Error</th>
                                <th class="px-5 py-2.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($pendingSubscriptions as $pSub)
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.customers.show', $pSub->customer_id) }}" class="font-semibold text-indigo-600 hover:underline">
                                            {{ $pSub->customer->full_name ?? '-' }}
                                        </a>
                                        <div class="font-mono text-slate-500">{{ $pSub->service_username }}</div>
                                    </td>
                                    <td class="px-5 py-3 font-medium text-slate-700">{{ $pSub->mikrotikRouter->name ?? '-' }}</td>
                                    <td class="px-5 py-3">
                                        <span class="font-mono font-bold uppercase text-amber-800">{{ $pSub->mikrotik_pending_action }}</span>
                                        <span class="ml-1 text-slate-400">({{ $pSub->mikrotik_sync_attempts }}x attempts)</span>
                                    </td>
                                    <td class="px-5 py-3 text-rose-600 max-w-xs truncate">{{ $pSub->mikrotik_last_sync_error ?: 'Queued' }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <form method="POST" action="{{ route('admin.subscriptions.reconcile', $pSub) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-slate-800">
                                                Sync Now
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- Add MikroTik Router Modal --}}
    <div x-show="addOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="addOpen = false">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Add MikroTik Router</h3>
                    <p class="text-xs text-slate-500">Select RouterOS v6 (Binary API) or v7 (REST API).</p>
                </div>
                <button type="button" @click="addOpen = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($errors->any() && old('_method') !== 'PUT')
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.routers.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs font-medium text-slate-600">Router Name *</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="NAS-CORE-01" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600">RouterOS Version &amp; Protocol *</label>
                    <select name="ros_version_type" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        <option value="v7" @selected(old('ros_version_type', 'v7') === 'v7')>RouterOS v7 (REST API — recommended)</option>
                        <option value="v6" @selected(old('ros_version_type') === 'v6')>RouterOS v6 (Native Binary API)</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600">Host / IP / DDNS *</label>
                    <input type="text" name="host" value="{{ old('host') }}" placeholder="192.168.88.1" required class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="text-xs text-slate-600">API v6</label>
                        <input type="number" name="api_port" value="{{ old('api_port', 8728) }}" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-600">REST v7</label>
                        <input type="number" name="rest_port" value="{{ old('rest_port', 443) }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-600">SSH</label>
                        <input type="number" name="ssh_port" value="{{ old('ssh_port', 22) }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs font-medium text-slate-600">API Username *</label>
                        <input type="text" name="username" value="{{ old('username') }}" placeholder="api-billing" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">API Password *</label>
                        <input type="password" name="password" placeholder="Password" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs font-medium text-slate-600">Isolation Address-List *</label>
                        <input type="text" name="isolir_address_list" value="{{ old('isolir_address_list', 'ISOLIR') }}" placeholder="ISOLIR" required class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Isolation Profile (Optional)</label>
                        <input type="text" name="isolir_profile_name" value="{{ old('isolir_profile_name') }}" placeholder="prof-isolir" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 pt-1">
                    <input type="checkbox" name="use_ssl" value="1" @checked(old('use_ssl', true)) class="rounded border-slate-300 text-indigo-600">
                    Use HTTPS / API-SSL
                </label>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" @click="addOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">Save Router</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit Modal --}}
    <div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="editing = null">
            <h3 class="text-lg font-bold text-slate-900">Edit MikroTik Router</h3>
            <template x-if="editing">
                <form method="POST" :action="'{{ url('/admin/routers') }}/' + editing.id" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <input type="text" name="name" x-model="editing.name" required class="w-full rounded-xl border-slate-300 text-sm">
                    <select name="ros_version_type" x-model="editing.ros_version_type" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="v7">RouterOS v7 (REST API)</option>
                        <option value="v6">RouterOS v6 (Native Binary API)</option>
                    </select>
                    <input type="text" name="host" x-model="editing.host" required class="w-full rounded-xl border-slate-300 text-sm">
                    <div class="grid grid-cols-3 gap-2">
                        <input type="number" name="api_port" x-model="editing.api_port" required class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="number" name="rest_port" x-model="editing.rest_port" class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="number" name="ssh_port" x-model="editing.ssh_port" class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="username" x-model="editing.username" required class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="password" name="password" placeholder="Leave blank to keep unchanged" class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="isolir_address_list" x-model="editing.isolir_address_list" required class="w-full rounded-xl border-slate-300 text-sm">
                        <input type="text" name="isolir_profile_name" x-model="editing.isolir_profile_name" placeholder="Isolation Profile" class="w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="flex gap-4">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="use_ssl" value="1" x-model="editing.use_ssl" class="rounded border-slate-300"> SSL
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_active" value="1" x-model="editing.is_active" class="rounded border-slate-300"> Active
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="editing = null" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save Changes</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
