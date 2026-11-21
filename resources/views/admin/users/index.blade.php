@extends('layouts.app', ['title' => 'User Management'])

@section('content')
<div class="space-y-6"
     x-data="{ addOpen: {{ ($errors->any() && old('_method') !== 'PUT') ? 'true' : 'false' }}, editing: null }"
     @keydown.escape.window="addOpen = false; editing = null">

    <x-page-header title="Application User Management" subtitle="Manage Super Admin, Finance, NOC/Technician, and Customer portal accounts and access states.">
        <x-slot:actions>
            <button type="button" @click="addOpen = true"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add User
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">
        <form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-2 shadow-sm">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name or email..." class="flex-1 min-w-[200px] rounded-xl border-slate-300 text-sm">
            <select name="role" class="rounded-xl border-slate-300 text-sm">
                <option value="">All Roles</option>
                @foreach($roles as $r)
                    <option value="{{ $r->value }}" @selected($role === $r->value)>{{ strtoupper($r->value) }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Filter</button>
            @if($search !== '' || $role !== '')
                <a href="{{ route('admin.users.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Reset</a>
            @endif
        </form>

        @if($users->isEmpty())
            <x-empty-state title="No users found" description="No users registered yet or no matches for your current filter.">
                <x-slot:action>
                    <button type="button" @click="addOpen = true"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Add User
                    </button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">User</th>
                                <th class="px-5 py-3">Role</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($users as $u)
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-3.5">
                                        <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $u->email }} {{ $u->phone ? '· ' . $u->phone : '' }}</div>
                                    </td>
                                    <td class="px-5 py-3.5 font-mono text-xs font-semibold uppercase text-indigo-700">{{ $u->role->value }}</td>
                                    <td class="px-5 py-3.5">
                                        <x-status-badge :status="$u->is_active ? 'active' : 'suspended'" />
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                        <button type="button"
                                                @click="editing = {{ Js::from(['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->role->value]) }}"
                                                class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">Edit</button>
                                        <form method="POST" action="{{ route('admin.users.toggle', $u) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium hover:bg-slate-50">
                                                {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                        @if(auth()->id() !== $u->id)
                                            <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline" onsubmit="return confirm('Delete this user account?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $users->links() }}
        @endif
    </div>

    {{-- Add User Modal --}}
    <div x-show="addOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="addOpen = false">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Add New User</h3>
                    <p class="text-xs text-slate-500">Create an access account for staff or portal users.</p>
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

            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600">Full Name *</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="John Doe" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">Login Email *</label>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="name@iqbill.local" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">WhatsApp Number (Optional)</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" placeholder="6281234567890" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">Role *</label>
                    <select name="role" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        @foreach($roles as $r)
                            <option value="{{ $r->value }}" @selected(old('role') === $r->value)>{{ strtoupper($r->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">Password *</label>
                    <input type="password" name="password" placeholder="Minimum 8 characters" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" @click="addOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save User</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit User Modal --}}
    <div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="editing = null">
            <h3 class="text-lg font-bold text-slate-900">Edit User</h3>
            <template x-if="editing">
                <form method="POST" :action="'{{ url('/admin/users') }}/' + editing.id" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Full Name</label>
                        <input type="text" name="name" x-model="editing.name" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Login Email</label>
                        <input type="email" name="email" x-model="editing.email" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">WhatsApp Number</label>
                        <input type="text" name="phone" x-model="editing.phone" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Role</label>
                        <select name="role" x-model="editing.role" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            @foreach($roles as $r)
                                <option value="{{ $r->value }}">{{ strtoupper($r->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">New Password (Optional)</label>
                        <input type="password" name="password" placeholder="Leave blank to keep current password" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                        <button type="button" @click="editing = null" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
