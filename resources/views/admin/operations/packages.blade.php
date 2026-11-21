@extends('layouts.app', ['title' => 'Internet Service Packages'])

@php
    $isEditValidationError = $errors->any() && filled(old('editing_package_id'));
    $isAddValidationError = $errors->any() && !$isEditValidationError;
    $addOld = static fn (string $field, mixed $default = ''): mixed => $isEditValidationError ? $default : old($field, $default);

    $formatNumericInput = static function ($value): int|float {
        $rounded = round((float) $value, 2);

        return fmod($rounded, 1.0) === 0.0 ? (int) $rounded : $rounded;
    };

    $initialEditing = $isEditValidationError ? [
        'id' => (int) old('editing_package_id'),
        'code' => (string) old('code', ''),
        'name' => (string) old('name', ''),
        'description' => (string) old('description', ''),
        'service_type' => (string) old('service_type', 'pppoe'),
        'mikrotik_profile_name' => (string) old('mikrotik_profile_name', ''),
        'speed_download_mbps' => old('speed_download_mbps', ''),
        'speed_upload_mbps' => old('speed_upload_mbps', ''),
        'price' => old('price', ''),
        'tax_rate' => old('tax_rate', '0'),
        'is_active' => (bool) old('is_active', false),
        'router_ids' => array_values(array_map('strval', (array) old('router_ids', []))),
    ] : null;
@endphp

@section('content')
<div class="space-y-6"
     x-data="{
        addOpen: {{ $isAddValidationError ? 'true' : 'false' }},
        addRouterIds: @js(array_values(array_map('strval', (array) $addOld('router_ids', [])))),
        editing: {{ Js::from($initialEditing) }}
     }"
     @keydown.escape.window="addOpen = false; editing = null">
    <x-page-header title="Internet Service Packages" subtitle="Manage pricing, download/upload speeds, VAT, descriptions, and MikroTik profile mappings.">
        <x-slot:actions>
            <button type="button" @click="addOpen = true"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add Package
            </button>
        </x-slot:actions>
    </x-page-header>

    <div>
        @if($packages->isEmpty())
            <x-empty-state title="No service packages yet" description="Create your first PPPoE package catalog.">
                <x-slot:action>
                    <button type="button" @click="addOpen = true"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Add Package
                    </button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Package</th>
                                <th class="px-5 py-3">Profile &amp; Speed</th>
                                <th class="px-5 py-3">MikroTik Routers</th>
                                <th class="px-5 py-3">Price</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($packages as $pkg)
                                <tr>
                                    <td class="px-5 py-3.5">
                                        <div class="font-semibold text-slate-900">{{ $pkg->name }}</div>
                                        <div class="font-mono text-xs text-slate-400">{{ $pkg->code }} &middot; {{ strtoupper($pkg->service_type) }}</div>
                                        @if($pkg->description)
                                            <div class="text-xs text-slate-500 mt-0.5">{{ Str::limit($pkg->description, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        <div class="font-mono">{{ $pkg->mikrotik_profile_name }}</div>
                                        <div class="text-slate-500">&darr; {{ $pkg->speed_download_mbps }}M / &uarr; {{ $pkg->speed_upload_mbps }}M</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        @if($pkg->routers->isEmpty())
                                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                All Routers
                                            </span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($pkg->routers as $router)
                                                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold {{ $router->is_active ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' }}"
                                                          title="ROS {{ strtoupper($router->ros_version_type) }}">
                                                        {{ $router->name }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <div class="font-semibold text-slate-900">Rp {{ number_format((float) $pkg->price, 0, ',', '.') }}</div>
                                        <div class="text-xs text-slate-400">VAT {{ $pkg->tax_rate }}%</div>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <x-status-badge :status="$pkg->is_active ? 'active' : 'suspended'" />
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                        <button type="button"
                                                @click="editing = {{ Js::from([
                                                    'id' => $pkg->id,
                                                    'code' => $pkg->code,
                                                    'name' => $pkg->name,
                                                    'description' => $pkg->description ?? '',
                                                    'mikrotik_profile_name' => $pkg->mikrotik_profile_name,
                                                    'speed_download_mbps' => $pkg->speed_download_mbps,
                                                    'speed_upload_mbps' => $pkg->speed_upload_mbps,
                                                    'price' => $formatNumericInput($pkg->price),
                                                    'tax_rate' => $formatNumericInput($pkg->tax_rate),
                                                    'is_active' => (bool) $pkg->is_active,
                                                    'router_ids' => $pkg->routers->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                                                ]) }}"
                                                class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition">
                                            Edit
                                        </button>
                                        <form method="POST" action="{{ route('admin.packages.destroy', $pkg) }}" class="inline" onsubmit="return confirm('Delete this package?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">Delete</button>
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

    {{-- Add Package Modal --}}
    <div x-show="addOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="addOpen = false">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Add Service Package</h3>
                    <p class="text-xs text-slate-500">Create a PPPoE package along with its MikroTik profile mapping.</p>
                </div>
                <button type="button" @click="addOpen = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($isAddValidationError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.packages.store') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="service_type" value="pppoe">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600">Package Code</label>
                        <input type="text" name="code" value="{{ $addOld('code') }}" placeholder="HOME-30M" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Package Name</label>
                        <input type="text" name="name" value="{{ $addOld('name') }}" placeholder="Home Fiber 30 Mbps" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600">MikroTik Profile Name</label>
                    <input type="text" name="mikrotik_profile_name" value="{{ $addOld('mikrotik_profile_name') }}" placeholder="prof-home-30m" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                @include('admin.operations.partials.package-router-selector', ['routers' => $routers, 'modelExpr' => 'addRouterIds'])
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600">Download (Mbps)</label>
                        <input type="number" name="speed_download_mbps" value="{{ $addOld('speed_download_mbps') }}" placeholder="30" min="1" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Upload (Mbps)</label>
                        <input type="number" name="speed_upload_mbps" value="{{ $addOld('speed_upload_mbps') }}" placeholder="30" min="1" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600">Price (IDR)</label>
                        <input type="number" step="0.01" name="price" value="{{ $addOld('price') }}" placeholder="150000" min="0" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">VAT (%)</label>
                        <input type="number" step="0.01" name="tax_rate" value="{{ $addOld('tax_rate', 0) }}" placeholder="0" min="0" max="100" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600">Package Description (Optional)</label>
                    <textarea name="description" rows="2" placeholder="Package notes or SLA details..." class="mt-1 w-full rounded-xl border-slate-300 text-sm">{{ $addOld('description') }}</textarea>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" @click="addOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save Package</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit Package Modal --}}
    <div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="editing = null">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Edit Service Package</h3>
                    <p class="text-xs text-slate-500">Update rate, speed, VAT, and MikroTik profile mapping.</p>
                </div>
                <button type="button" @click="editing = null" aria-label="Close edit package modal" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($isEditValidationError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <template x-if="editing">
                <form method="POST" :action="'{{ url('/admin/packages') }}/' + editing.id" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="editing_package_id" :value="editing.id">

                    <input type="hidden" name="service_type" value="pppoe">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="edit-pkg-code" class="text-xs font-medium text-slate-600">Package Code</label>
                            <input id="edit-pkg-code" type="text" name="code" x-model="editing.code" placeholder="BIZ-50M" maxlength="30" required class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                        </div>
                        <div>
                            <label for="edit-pkg-name" class="text-xs font-medium text-slate-600">Package Name</label>
                            <input id="edit-pkg-name" type="text" name="name" x-model="editing.name" placeholder="Business Fiber 50 Mbps" maxlength="100" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="edit-pkg-profile" class="text-xs font-medium text-slate-600">MikroTik Profile Name</label>
                        <input id="edit-pkg-profile" type="text" name="mikrotik_profile_name" x-model="editing.mikrotik_profile_name" placeholder="prof-50m" maxlength="80" required class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>

                    @include('admin.operations.partials.package-router-selector', ['routers' => $routers, 'modelExpr' => 'editing.router_ids', 'includeSyncMarker' => true])

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="edit-pkg-dl" class="text-xs font-medium text-slate-600">Download (Mbps)</label>
                            <input id="edit-pkg-dl" type="number" name="speed_download_mbps" x-model="editing.speed_download_mbps" min="1" placeholder="50" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                        <div>
                            <label for="edit-pkg-ul" class="text-xs font-medium text-slate-600">Upload (Mbps)</label>
                            <input id="edit-pkg-ul" type="number" name="speed_upload_mbps" x-model="editing.speed_upload_mbps" min="1" placeholder="50" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="edit-pkg-price" class="text-xs font-medium text-slate-600">Price (IDR)</label>
                            <input id="edit-pkg-price" type="number" step="0.01" name="price" x-model="editing.price" min="0" placeholder="450000" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                        <div>
                            <label for="edit-pkg-tax" class="text-xs font-medium text-slate-600">VAT (%)</label>
                            <input id="edit-pkg-tax" type="number" step="0.01" name="tax_rate" x-model="editing.tax_rate" min="0" max="100" placeholder="11" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="edit-pkg-desc" class="text-xs font-medium text-slate-600">Package Description (Optional)</label>
                        <textarea id="edit-pkg-desc" name="description" x-model="editing.description" rows="2" maxlength="1000" placeholder="Package notes or SLA details..." class="mt-1 w-full rounded-xl border-slate-300 text-sm"></textarea>
                    </div>

                    <div class="pt-1">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" x-model="editing.is_active" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>Active Package</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                        <button type="button" @click="editing = null" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">Save Changes</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
