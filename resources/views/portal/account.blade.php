@extends('layouts.app', ['title' => 'Profile & Account Security'])

@section('content')
<div class="space-y-6">
    <x-page-header title="Profile & Account Security" subtitle="View your customer account details, connection parameters, and update your portal password." />

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {{-- Left Column: Customer Identity & Connection Summary --}}
        <div class="lg:col-span-6 space-y-5">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-4">
                <h3 class="text-base font-bold text-slate-900 border-b border-slate-100 pb-3">Customer Identity</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Customer ID</dt>
                        <dd class="font-mono font-bold text-indigo-700">{{ $customer?->customer_code ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Portal Login Username</dt>
                        <dd class="font-mono font-semibold text-slate-900">{{ $user->username ?: $user->email }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Full Name</dt>
                        <dd class="font-semibold text-slate-900">{{ $customer?->full_name ?? $user->name }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">WhatsApp No.</dt>
                        <dd class="font-mono text-slate-800">{{ $customer?->phone_whatsapp ?? $user->phone ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Installation Address</dt>
                        <dd class="mt-1 text-slate-800">{{ $customer?->installation_address ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            @if($customer && $customer->subscriptions->isNotEmpty())
                <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-4">
                    <h3 class="text-base font-bold text-slate-900 border-b border-slate-100 pb-3">Registered Connection Parameters</h3>
                    <div class="space-y-3">
                        @foreach($customer->subscriptions as $sub)
                            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-xs space-y-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-slate-900 text-sm">{{ $sub->package->name }}</span>
                                    <x-status-badge :status="$sub->status" />
                                </div>
                                <div class="text-slate-600">
                                    PPPoE Username: <span class="font-mono font-semibold text-slate-900">{{ $sub->service_username }}</span>
                                </div>
                                <div class="text-slate-600">
                                    IP Address: <span class="font-mono text-slate-800">{{ $sub->static_ip_address ?: 'Dynamic Pool' }}</span>
                                    &middot; POP: <span class="font-medium text-slate-800">{{ $sub->mikrotikRouter->name ?? '-' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Right Column: Contact Email & Change Password --}}
        <div class="lg:col-span-6 space-y-5">
            <form method="POST" action="{{ route('portal.account.profile') }}" class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <h3 class="text-base font-bold text-slate-900">Notification Email</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Your email can also be used to identify your account alongside your Customer ID.</p>
                </div>

                <div>
                    <label for="portal-email" class="block text-xs font-medium text-slate-700">Email Address</label>
                    <input id="portal-email" type="email" name="email" value="{{ old('email', $user->email) }}" placeholder="name@email.com"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm @error('email') border-rose-500 @enderror">
                    @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition">
                        Save Email
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('portal.account.password') }}" class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <h3 class="text-base font-bold text-slate-900">Change Portal Password</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Use a strong password (minimum 6 characters) to keep your account secure.</p>
                </div>

                <div>
                    <label for="current-password" class="block text-xs font-medium text-slate-700">Current Password *</label>
                    <input id="current-password" type="password" name="current_password" required
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm @error('current_password') border-rose-500 @enderror">
                    @error('current_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="new-password" class="block text-xs font-medium text-slate-700">New Password *</label>
                        <input id="new-password" type="password" name="password" minlength="6" required
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm @error('password') border-rose-500 @enderror">
                        @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="new-password-confirm" class="block text-xs font-medium text-slate-700">Confirm New Password *</label>
                        <input id="new-password-confirm" type="password" name="password_confirmation" minlength="6" required
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
