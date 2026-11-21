@extends('layouts.app', ['title' => 'MikroTik Configuration Backups (.rsc)'])

@section('content')
<div class="space-y-6"
     x-data="{ addOpen: {{ $errors->any() ? 'true' : 'false' }} }"
     @keydown.escape.window="addOpen = false">

    <x-page-header title="MikroTik Configuration Backups (.rsc)" subtitle="Export .rsc configuration scripts directly from MikroTik v6 or v7 routers into secure storage.">
        <x-slot:actions>
            <button type="button" @click="addOpen = true"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Create .rsc Backup
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">
        @if($backups->isEmpty())
            <x-empty-state title="No backup files yet" description="Click the button below to take your first .rsc configuration snapshot from a MikroTik router.">
                <x-slot:action>
                    <button type="button" @click="addOpen = true"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Create .rsc Backup
                    </button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Router &amp; File</th>
                                <th class="px-5 py-3">Size</th>
                                <th class="px-5 py-3">Date</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($backups as $b)
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-3.5">
                                        <div class="font-mono text-xs font-semibold text-slate-900">{{ $b->filename }}</div>
                                        <div class="text-xs text-slate-500">{{ $b->mikrotikRouter->name ?? '-' }} {{ $b->notes ? '· ' . $b->notes : '' }}</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs font-mono">{{ number_format($b->file_size_bytes / 1024, 1) }} KB</td>
                                    <td class="px-5 py-3.5 text-xs">{{ $b->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</td>
                                    <td class="px-5 py-3.5 text-right space-x-2 whitespace-nowrap">
                                        <a href="{{ route('admin.mikrotik.backups.download', $b) }}"
                                           class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">Download</a>
                                        <form method="POST" action="{{ route('admin.mikrotik.backups.destroy', $b) }}" class="inline" onsubmit="return confirm('Delete this backup file?')">
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
            {{ $backups->links() }}
        @endif
    </div>

    {{-- Create .rsc Backup Modal --}}
    <div x-show="addOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4" @click.outside="addOpen = false">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Create New Backup (.rsc)</h3>
                    <p class="text-xs text-slate-500">Export configuration script directly from MikroTik.</p>
                </div>
                <button type="button" @click="addOpen = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.mikrotik.backups.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600">Select MikroTik Router *</label>
                    <select name="mikrotik_router_id" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        <option value="">-- Select Router --</option>
                        @foreach($routers as $r)
                            <option value="{{ $r->id }}" @selected(old('mikrotik_router_id') == $r->id)>{{ $r->name }} ({{ strtoupper($r->ros_version_type) }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">Backup Notes (Optional)</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" placeholder="e.g. Before firewall change" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" @click="addOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Export &amp; Save .rsc</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
