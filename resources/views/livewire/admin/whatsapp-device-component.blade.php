<div class="space-y-6">
    <x-page-header title="Go-WhatsApp Web Multidevice (GOWA)" subtitle="Configure GOWA Base URL & credentials, link your device via QR Scan / Pairing Code, and test message delivery.">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp.broadcast') }}" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                Bulk WA Broadcast
            </a>
            <a href="{{ route('admin.whatsapp.templates') }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Manage Message Templates
            </a>
        </x-slot:actions>
    </x-page-header>

    @if($errorMessage)
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errorMessage }}</div>
    @endif
    @if($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $statusMessage }}</div>
    @endif

    {{-- Go-WhatsApp Server Connection Configuration --}}
    <form wire:submit="saveGatewaySettings" autocomplete="off" class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900">Go-WhatsApp Server Settings (GOWA)</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Runs externally or bundled via Docker Compose (<code class="font-mono">http://gowa:3000</code>, auto-synced via <code class="font-mono">GOWA_API_URL</code> / <code class="font-mono">php artisan gowa:sync-config</code>). Enter the Base URL and Basic Auth credentials (<code class="font-mono">APP_BASIC_AUTH</code>).
                </p>
            </div>
            <button type="submit" wire:loading.attr="disabled"
                    class="inline-flex shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                <span wire:loading.remove wire:target="saveGatewaySettings">Save Configuration</span>
                <span wire:loading wire:target="saveGatewaySettings">Saving...</span>
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-700">GOWA Base URL</label>
                <input type="url"
                       wire:model="waGatewayUrl"
                       autocomplete="off"
                       placeholder="http://gowa:3000"
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                @error('waGatewayUrl') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-700">Device ID (Optional for GOWA v8+)</label>
                <input type="text"
                       wire:model="waDeviceId"
                       autocomplete="off"
                       placeholder="default"
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                @error('waDeviceId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-700">Basic Auth Username</label>
                <input type="text"
                       wire:model="waBasicAuthUser"
                       autocomplete="off"
                       name="gowa_basic_auth_user"
                       placeholder="GOWA basic auth username"
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                @error('waBasicAuthUser') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-700">Basic Auth Password</label>
                <input type="password"
                       wire:model="waBasicAuthPassword"
                       autocomplete="new-password"
                       name="gowa_basic_auth_password"
                       placeholder="{{ $hasBasicAuthPassword ? '•••••••• (leave blank to keep unchanged)' : 'Enter GOWA basic auth password' }}"
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                @error('waBasicAuthPassword') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Connected Device Status & QR Scan --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-slate-900">Connected Device Status</h3>
                    <p class="text-xs text-slate-500 font-mono mt-0.5">{{ $gatewayUrl ?: 'Base URL not configured yet' }}</p>
                </div>
                <button type="button" wire:click="refreshDevices" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    Refresh Status
                </button>
            </div>

            @if($devices === [])
                <x-empty-state title="No device linked yet"
                               description="Verify the Base URL above, then click 'Show Login QR' and scan using Linked Devices in WhatsApp." />
            @else
                <div class="space-y-2">
                    @foreach($devices as $dev)
                        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-sm">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $dev['push_name'] ?? $dev['name'] ?? 'WhatsApp Device' }}</div>
                                <div class="font-mono text-xs text-slate-500">{{ $dev['device_id'] ?? $dev['device'] ?? $dev['phone'] ?? '-' }}</div>
                            </div>
                            <x-status-badge :status="$dev['status'] ?? 'connected'" />
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap gap-2.5 pt-2">
                <button type="button" wire:click="requestQr" wire:loading.attr="disabled"
                        class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                    <span wire:loading.remove wire:target="requestQr">Show Login QR</span>
                    <span wire:loading wire:target="requestQr">Fetching QR...</span>
                </button>
                <button type="button" wire:click="reconnect" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reconnect
                </button>
                <button type="button" wire:click="logoutDevice" onclick="return confirm('Unlink this WhatsApp device?')"
                        class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-100">
                    Logout Device
                </button>
            </div>

            @if($qrDataUri)
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-6 text-center space-y-3">
                    <div class="text-sm font-semibold text-slate-900">Scan WhatsApp QR Code (Valid ~{{ $qrDuration }} seconds)</div>
                    <img src="{{ $qrDataUri }}" alt="Go-WhatsApp Login QR" class="mx-auto h-56 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                    <button type="button" wire:click="requestQr" class="text-xs font-semibold text-indigo-600 hover:underline">
                        Generate New QR
                    </button>
                </div>
            @endif

            <form wire:submit="requestPairCode" class="border-t border-slate-100 pt-4 space-y-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Alternative Login via Pairing Code</label>
                <div class="flex gap-2">
                    <input type="text" wire:model="pairPhone" placeholder="6281234567890" class="flex-1 rounded-xl border-slate-300 text-sm">
                    <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Request Code</button>
                </div>
                @if($pairCode)
                    <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-center font-mono text-xl font-bold tracking-widest text-emerald-700">
                        {{ $pairCode }}
                    </div>
                @endif
            </form>
        </div>

        {{-- Send Test Message --}}
        <form wire:submit="sendTest" class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4 h-fit">
            <h3 class="font-bold text-slate-900">Send GOWA Test Message</h3>
            <p class="text-xs text-slate-500">Make sure a device is connected before testing message delivery to a WhatsApp number.</p>
            <div>
                <label class="block text-sm font-medium text-slate-700">Recipient WhatsApp Number</label>
                <input type="text" wire:model="testPhone" placeholder="6281234567890" required class="mt-1 w-full rounded-xl border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Message Body</label>
                <textarea wire:model="testMessage" rows="4" required class="mt-1 w-full rounded-xl border-slate-300 text-sm"></textarea>
            </div>
            <button type="submit" wire:loading.attr="disabled"
                    class="w-full rounded-xl bg-emerald-600 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                <span wire:loading.remove wire:target="sendTest">Send Test Message</span>
                <span wire:loading wire:target="sendTest">Sending...</span>
            </button>
        </form>
    </div>
</div>
