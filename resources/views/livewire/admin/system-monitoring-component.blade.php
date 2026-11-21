<div wire:poll.15s="refreshMetrics" class="space-y-6">
    @if($automation['queue_worker']['ping_pending'] ?? false)
        <div wire:poll.3s="refreshMetrics" class="hidden" aria-hidden="true"></div>
    @endif

    <x-page-header title="System Monitoring & Health" subtitle="Monitor CPU, RAM, Disk, Queue Worker & Scheduler status, and integrated service connectivity.">
        <x-slot:actions>
            <span class="text-xs text-slate-500">Last updated: {{ $lastCheckedAt }}</span>
            <button type="button" wire:click="refreshMetrics" wire:loading.attr="disabled"
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                <span wire:loading.remove wire:target="refreshMetrics">Refresh Now</span>
                <span wire:loading wire:target="refreshMetrics">Checking...</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Resource Gauges --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">CPU Load</span>
                <span class="text-lg font-bold text-slate-900 font-mono">{{ $host['cpu_percent'] ?? 0 }}%</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ min(100, $host['cpu_percent'] ?? 0) }}%"></div>
            </div>
            <div class="text-xs text-slate-500">OS: {{ $host['os'] ?? '-' }} &middot; PHP {{ $host['php_version'] ?? '-' }}</div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Memory (RAM)</span>
                <span class="text-lg font-bold text-slate-900 font-mono">{{ $host['ram_percent'] ?? 0 }}%</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full bg-emerald-600 transition-all" style="width: {{ min(100, $host['ram_percent'] ?? 0) }}%"></div>
            </div>
            <div class="text-xs text-slate-500">{{ number_format($host['ram_used_mb'] ?? 0) }} MB / {{ number_format($host['ram_total_mb'] ?? 0) }} MB</div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Storage (Disk)</span>
                <span class="text-lg font-bold text-slate-900 font-mono">{{ $host['disk_percent'] ?? 0 }}%</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full bg-amber-500 transition-all" style="width: {{ min(100, $host['disk_percent'] ?? 0) }}%"></div>
            </div>
            <div class="text-xs text-slate-500">{{ $host['disk_used_gb'] ?? 0 }} GB / {{ $host['disk_total_gb'] ?? 0 }} GB</div>
        </div>
    </div>

    {{-- Background Automation: Scheduler & Queue Worker --}}
    @php
        $scheduler = $automation['scheduler'] ?? [];
        $worker = $automation['queue_worker'] ?? [];
        $schedulerState = $scheduler['state'] ?? 'pending';
        $workerState = $worker['state'] ?? 'pending';

        $cardStyle = static fn (string $state): string => match ($state) {
            'ok' => 'border-emerald-200 bg-emerald-50/30',
            'pending' => 'border-amber-200 bg-amber-50/30',
            default => 'border-rose-200 bg-rose-50/30',
        };
    @endphp

    <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h3 class="font-bold text-slate-900">Background Automation (Scheduler &amp; Queue Worker)</h3>
                <p class="text-xs text-slate-500">Ensures automated billing/isolation schedules and background job queues run smoothly.</p>
            </div>
            <button type="button" wire:click="pingQueueWorker" wire:loading.attr="disabled" wire:target="pingQueueWorker"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span wire:loading.remove wire:target="pingQueueWorker">Test Queue Worker</span>
                <span wire:loading wire:target="pingQueueWorker">Sending Ping...</span>
            </button>
        </div>

        @if($pingNotice !== '')
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-xs text-indigo-800">
                {{ $pingNotice }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Scheduler Card --}}
            <div class="rounded-xl border {{ $cardStyle($schedulerState) }} p-5 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold text-slate-900">{{ $scheduler['label'] ?? 'Laravel Scheduler' }}</div>
                        <div class="text-xs text-slate-500">Automated billing, WA reminders, isolation, &amp; MikroTik reconciliation</div>
                    </div>
                    <x-status-badge :status="$schedulerState" />
                </div>

                <div class="grid grid-cols-2 gap-3 pt-1">
                    <div class="rounded-lg bg-white/80 border border-slate-200/80 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Status</div>
                        <div class="text-sm font-semibold text-slate-900 mt-0.5">{{ $scheduler['status_label'] ?? '-' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/80 border border-slate-200/80 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Last Heartbeat</div>
                        <div class="text-xs font-semibold text-slate-900 mt-0.5">{{ $scheduler['last_run_at'] ?? 'Not recorded yet' }}</div>
                        @if(!empty($scheduler['last_run_human']))
                            <div class="text-[11px] text-slate-500">{{ $scheduler['last_run_human'] }}</div>
                        @endif
                    </div>
                </div>

                <p class="text-xs text-slate-600">{{ $scheduler['detail'] ?? '-' }}</p>

                @if(!($scheduler['ok'] ?? false))
                    <div class="rounded-lg bg-slate-900 text-slate-100 px-3 py-2 text-[11px] font-mono overflow-x-auto">
                        <div class="text-slate-400 mb-0.5">Run scheduler on server:</div>
                        <div>{{ $scheduler['recommended_command'] ?? 'php artisan schedule:work' }}</div>
                    </div>
                @endif
            </div>

            {{-- Queue Worker Card --}}
            <div class="rounded-xl border {{ $cardStyle($workerState) }} p-5 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold text-slate-900">{{ $worker['label'] ?? 'Queue Worker' }}</div>
                        <div class="text-xs text-slate-500">Status: <span class="font-semibold text-slate-700">{{ $worker['status_label'] ?? '-' }}</span> &middot; Driver: <span class="font-mono">{{ $worker['driver'] ?? '-' }}</span></div>
                    </div>
                    <x-status-badge :status="$workerState" />
                </div>

                <div class="grid grid-cols-3 gap-2.5 pt-1">
                    <div class="rounded-lg bg-white/80 border border-slate-200/80 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Ready / Total</div>
                        <div class="text-sm font-bold text-slate-900 mt-0.5 font-mono">{{ $worker['ready_jobs'] ?? 0 }} / {{ $worker['pending_jobs'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-lg bg-white/80 border border-slate-200/80 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Oldest Queue</div>
                        <div class="text-sm font-bold {{ ($worker['oldest_wait_seconds'] ?? 0) >= 90 ? 'text-rose-600' : 'text-slate-900' }} mt-0.5 font-mono">
                            {{ $worker['oldest_wait_human'] ?? '0s' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white/80 border border-slate-200/80 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Failed Jobs</div>
                        <div class="text-sm font-bold {{ ($worker['failed_jobs'] ?? 0) > 0 ? 'text-rose-600' : 'text-slate-900' }} mt-0.5 font-mono">
                            {{ $worker['failed_jobs'] ?? 0 }}
                        </div>
                    </div>
                </div>

                <div class="text-xs text-slate-600 space-y-1">
                    <p>{{ $worker['detail'] ?? '-' }}</p>
                    <div class="text-[11px] text-slate-500">
                        Last worker response:
                        <span class="font-semibold text-slate-700">{{ $worker['last_ping_at'] ?? 'Never tested' }}</span>
                        @if(!empty($worker['last_ping_human']))
                            ({{ $worker['last_ping_human'] }})
                        @endif
                    </div>
                </div>

                @if(($worker['driver'] ?? 'database') !== 'sync' && $workerState !== 'ok')
                    <div class="rounded-lg bg-slate-900 text-slate-100 px-3 py-2 text-[11px] font-mono overflow-x-auto">
                        <div class="text-slate-400 mb-0.5">Run queue worker on server / Supervisor:</div>
                        <div>{{ $worker['recommended_command'] ?? 'php artisan queue:work --tries=3' }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Service Connectivity --}}
    <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-bold text-slate-900">Integrated Service Connectivity</h3>
            <a href="{{ route('api.health') }}" target="_blank" class="text-xs font-mono text-indigo-600 hover:underline">GET /api/health &rarr;</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($services as $svc)
                @php
                    $svcState = $svc['state'] ?? ($svc['ok'] ? 'ok' : 'failed');
                    $svcBoxClass = match ($svcState) {
                        'ok' => 'border-emerald-200 bg-emerald-50/40',
                        'pending' => 'border-amber-200 bg-amber-50/40',
                        default => 'border-rose-200 bg-rose-50/40',
                    };
                @endphp
                <div class="rounded-xl border {{ $svcBoxClass }} p-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-900">{{ $svc['label'] }}</div>
                        <div class="mt-0.5 text-xs text-slate-600 break-words">{{ $svc['detail'] }}</div>
                    </div>
                    <x-status-badge :status="$svcState" />
                </div>
            @endforeach
        </div>
    </div>

    {{-- Panel Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Active Subscriptions</div>
            <div class="mt-1 text-xl font-bold text-slate-900">{{ $stats['subscriptions_active'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Isolated Subscriptions</div>
            <div class="mt-1 text-xl font-bold text-rose-600">{{ $stats['subscriptions_isolated'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Unpaid Invoices</div>
            <div class="mt-1 text-xl font-bold text-amber-600">{{ $stats['invoices_unpaid'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Active Routers</div>
            <div class="mt-1 text-xl font-bold text-indigo-600">{{ $stats['routers_active'] ?? 0 }}</div>
        </div>
    </div>
</div>
