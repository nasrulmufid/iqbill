@php($syncMarker = $includeSyncMarker ?? false)
<div class="space-y-2">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-1.5">
            <label class="text-xs font-medium text-slate-600">MikroTik Routers (Multi-select supported)</label>
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                  :class="({{ $modelExpr }} || []).length > 0 ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-100 text-slate-600'"
                  x-text="({{ $modelExpr }} || []).length > 0 ? ({{ $modelExpr }} || []).length + ' selected' : 'All Routers'">
            </span>
        </div>
        @if($routers->isNotEmpty())
            <div class="flex items-center gap-2 text-[11px] font-semibold">
                <button type="button"
                        @click="{{ $modelExpr }} = @js($routers->pluck('id')->map(fn ($id) => (string) $id)->values()->all())"
                        class="text-indigo-600 hover:text-indigo-800 hover:underline">
                    Select All
                </button>
                <span class="text-slate-300">&middot;</span>
                <button type="button"
                        @click="{{ $modelExpr }} = []"
                        class="text-slate-500 hover:text-slate-700 hover:underline">
                    Reset (All)
                </button>
            </div>
        @endif
    </div>

    @if($syncMarker)
        <input type="hidden" name="sync_routers" value="1">
    @endif

    @if($routers->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800">
            No MikroTik routers registered yet. Leave empty to make this package available across all routers, or add a router first.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-36 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50/70 p-2.5">
            @foreach($routers as $router)
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs cursor-pointer hover:border-indigo-300 transition">
                    <input type="checkbox"
                           name="router_ids[]"
                           value="{{ $router->id }}"
                           x-model="{{ $modelExpr }}"
                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
                    <span class="min-w-0 flex-1 truncate font-medium text-slate-800">{{ $router->name }}</span>
                    <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-slate-600">
                        {{ strtoupper($router->ros_version_type) }}
                    </span>
                    @if(!$router->is_active)
                        <span class="shrink-0 rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">Off</span>
                    @endif
                </label>
            @endforeach
        </div>
        <p class="text-[11px] text-slate-500">Check one or more routers to restrict this package to those routers only. Leave empty to apply to all routers.</p>
    @endif
</div>
