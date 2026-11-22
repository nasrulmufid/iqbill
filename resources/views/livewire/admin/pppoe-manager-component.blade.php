<div class="space-y-6">
    <x-page-header title="PPPoE Management & Traffic Monitor" subtitle="Manage active sessions, secrets, bandwidth profiles, and real-time traffic per MikroTik router.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="routerId" class="rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach($routers as $r)
                        <option value="{{ $r->id }}">{{ $r->name }} ({{ strtoupper($r->ros_version_type) }})</option>
                    @endforeach
                </select>
                <button type="button" wire:click="loadData" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <span wire:loading.remove wire:target="loadData">Reload</span>
                    <span wire:loading wire:target="loadData">Loading...</span>
                </button>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if($errorMessage)
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errorMessage }}</div>
    @endif
    @if($successMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $successMessage }}</div>
    @endif

    @if($routers->isEmpty())
        <x-empty-state title="No active MikroTik routers"
                       description="Add at least one MikroTik router (v6 or v7) to manage PPPoE services."
                       actionLabel="Add Router"
                       :actionHref="route('admin.routers.index')" />
    @else
        {{-- Live Traffic Monitor Card --}}
        @if($monitoredInterface)
            <div wire:poll.3s="refreshTraffic" class="rounded-2xl bg-slate-900 text-white p-6 shadow-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-indigo-300">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        Live Traffic Monitor (3s Polling)
                    </div>
                    <div class="mt-1 font-mono text-lg font-bold">{{ $monitoredInterface }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-6">
                    <div>
                        <div class="text-xs text-slate-400">Download (Rx)</div>
                        <div class="text-2xl font-bold text-emerald-400 font-mono">
                            {{ number_format(($traffic['rx_bps'] ?? 0) / 1000000, 2) }} <span class="text-xs">Mbps</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400">Upload (Tx)</div>
                        <div class="text-2xl font-bold text-sky-400 font-mono">
                            {{ number_format(($traffic['tx_bps'] ?? 0) / 1000000, 2) }} <span class="text-xs">Mbps</span>
                        </div>
                    </div>
                    <button type="button" wire:click="stopMonitor" class="rounded-xl bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-700">
                        Close Monitor
                    </button>
                </div>
            </div>
        @endif

        {{-- Tabs & Add Trigger --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
            <div class="flex flex-wrap gap-2">
                @foreach(['active' => 'Active Sessions', 'secrets' => 'PPPoE Secrets', 'profiles' => 'PPPoE Profiles'] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')"
                            class="rounded-xl px-4 py-2 text-sm font-semibold transition {{ $tab === $key ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($tab === 'secrets')
                <button type="button" wire:click="$set('addSecretOpen', true)"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                    + Add PPPoE Secret
                </button>
            @elseif($tab === 'profiles')
                <button type="button" wire:click="$set('addProfileOpen', true)"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                    + Add PPPoE Profile
                </button>
            @endif
        </div>

        @if($tab === 'active')
            @if($activeSessions === [])
                <x-empty-state title="No active PPPoE sessions" description="No customers are currently connected over PPPoE on this router." />
            @else
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Username</th>
                                    <th class="px-5 py-3">IP Address</th>
                                    <th class="px-5 py-3">Caller ID (MAC)</th>
                                    <th class="px-5 py-3">Uptime</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($activeSessions as $s)
                                    <tr>
                                        <td class="px-5 py-3 font-mono font-semibold text-slate-900">{{ $s['name'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono">{{ $s['address'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $s['caller-id'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $s['uptime'] ?? '-' }}</td>
                                        <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
                                            @if(!empty($s['name']))
                                                <button type="button" wire:click="startMonitor({{ \Illuminate\Support\Js::from($s['name']) }})"
                                                        class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                                                    Traffic
                                                </button>
                                            @endif
                                            @if(!empty($s['.id']))
                                                <button type="button" wire:click="disconnectSession({{ \Illuminate\Support\Js::from($s['.id']) }})"
                                                        class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                    Disconnect
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @elseif($tab === 'secrets')
            @if($secrets === [])
                <x-empty-state title="No PPPoE secrets" description="No PPPoE accounts exist on this router yet.">
                    <x-slot:action>
                        <button type="button" wire:click="$set('addSecretOpen', true)"
                                class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                            + Add PPPoE Secret
                        </button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Username</th>
                                    <th class="px-5 py-3">Profile</th>
                                    <th class="px-5 py-3">Remote IP</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($secrets as $sec)
                                    @php $disabled = ($sec['disabled'] ?? 'false') === 'true' || ($sec['disabled'] ?? 'no') === 'yes'; @endphp
                                    <tr>
                                        <td class="px-5 py-3 font-mono font-semibold">{{ $sec['name'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $sec['profile'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $sec['remote-address'] ?? '-' }}</td>
                                        <td class="px-5 py-3">
                                            <x-status-badge :status="$disabled ? 'suspended' : 'active'" />
                                        </td>
                                        <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
                                            @if(!empty($sec['.id']))
                                                <button type="button" wire:click="toggleSecret({{ \Illuminate\Support\Js::from($sec['.id']) }}, {{ $disabled ? 'true' : 'false' }})"
                                                        class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium">
                                                    {{ $disabled ? 'Enable' : 'Disable' }}
                                                </button>
                                                <button type="button" wire:click="deleteSecret({{ \Illuminate\Support\Js::from($sec['.id']) }})"
                                                        class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">
                                                    Delete
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @else
            @if($profiles === [])
                <x-empty-state title="No PPPoE profiles" description="No PPPoE bandwidth profiles configured yet.">
                    <x-slot:action>
                        <button type="button" wire:click="$set('addProfileOpen', true)"
                                class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                            + Add PPPoE Profile
                        </button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Profile Name</th>
                                    <th class="px-5 py-3">Local Address</th>
                                    <th class="px-5 py-3">Remote Address</th>
                                    <th class="px-5 py-3">Rate Limit</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($profiles as $prof)
                                    <tr>
                                        <td class="px-5 py-3 font-mono font-semibold">{{ $prof['name'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $prof['local-address'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $prof['remote-address'] ?? '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs">{{ $prof['rate-limit'] ?? '-' }}</td>
                                        <td class="px-5 py-3 text-right">
                                            @if(!empty($prof['.id']))
                                                <button type="button" wire:click="deleteProfile({{ \Illuminate\Support\Js::from($prof['.id']) }})"
                                                        class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">
                                                    Delete
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    @endif

    {{-- Add PPPoE Secret Modal --}}
    @if($addSecretOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
             wire:click.self="$set('addSecretOpen', false)">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-bold text-slate-900">Add PPPoE Secret</h3>
                    <button type="button" wire:click="$set('addSecretOpen', false)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form wire:submit="createSecret" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600">PPPoE Username *</label>
                        <input type="text" wire:model="secretName" placeholder="PPPoE Username" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        @error('secretName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Password *</label>
                        <input type="password" autocomplete="new-password" wire:model="secretPassword" placeholder="Password" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        @error('secretPassword') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">PPPoE Profile *</label>
                        <select wire:model="secretProfile" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            <option value="">-- Select Profile --</option>
                            @foreach($profiles as $p)
                                <option value="{{ $p['name'] ?? '' }}">{{ $p['name'] ?? '' }}</option>
                            @endforeach
                        </select>
                        @error('secretProfile') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Remote IP (Optional)</label>
                        <input type="text" wire:model="secretRemoteAddress" placeholder="10.20.30.10" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                        <button type="button" wire:click="$set('addSecretOpen', false)" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save Secret</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Add PPPoE Profile Modal --}}
    @if($addProfileOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
             wire:click.self="$set('addProfileOpen', false)">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-bold text-slate-900">Add PPPoE Profile</h3>
                    <button type="button" wire:click="$set('addProfileOpen', false)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form wire:submit="createProfile" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Profile Name *</label>
                        <input type="text" wire:model="profileName" placeholder="e.g. prof-30m" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        @error('profileName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Local Address</label>
                        <input type="text" wire:model="profileLocalAddr" placeholder="e.g. 10.10.0.1" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Remote Pool / IP</label>
                        <input type="text" wire:model="profileRemotePool" placeholder="e.g. pool-pppoe" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Rate Limit</label>
                        <input type="text" wire:model="profileRateLimit" placeholder="e.g. 15M/30M" class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                        <button type="button" wire:click="$set('addProfileOpen', false)" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
