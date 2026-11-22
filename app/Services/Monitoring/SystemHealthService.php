<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\QueueWorkerHeartbeatJob;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Notification\GowaClient;
use App\Services\Notification\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    public const CACHE_SCHEDULER_HEARTBEAT = 'system:scheduler_last_tick_at';

    public const CACHE_WORKER_LAST_PING_AT = 'system:queue_worker_last_ping_at';

    public const CACHE_WORKER_PENDING_PING = 'system:queue_worker_pending_ping';

    public const SCHEDULER_STALE_SECONDS = 180;

    public const QUEUE_STUCK_SECONDS = 90;

    public const WORKER_PING_TIMEOUT_SECONDS = 30;

    public const WORKER_PING_STALE_SECONDS = 900;

    public function __construct(
        private readonly GowaClient $gowaClient,
        private readonly TelegramNotificationService $telegram
    ) {
    }

    /**
     * Record the latest Laravel Scheduler heartbeat timestamp.
     */
    public static function recordSchedulerHeartbeat(?int $timestamp = null): int
    {
        $ts = $timestamp ?? now()->timestamp;
        Cache::put(self::CACHE_SCHEDULER_HEARTBEAT, $ts, now()->addDays(7));

        return $ts;
    }

    /**
     * Record a queue worker test ping dispatch so stalled workers can be detected.
     */
    public static function recordWorkerPingDispatched(string $token, string $source = 'manual', ?int $timestamp = null): void
    {
        $ts = $timestamp ?? now()->timestamp;
        Cache::put(self::CACHE_WORKER_PENDING_PING, [
            'token' => $token,
            'source' => $source,
            'dispatched_at' => $ts,
        ], now()->addHours(6));
    }

    public static function hasPendingWorkerPing(): bool
    {
        $pending = Cache::get(self::CACHE_WORKER_PENDING_PING);

        return is_array($pending) && isset($pending['dispatched_at']);
    }

    /**
     * Record the timestamp when the queue worker processed a heartbeat ping.
     */
    public static function recordWorkerPingProcessed(?string $token = null, ?int $timestamp = null): int
    {
        $ts = $timestamp ?? now()->timestamp;
        Cache::put(self::CACHE_WORKER_LAST_PING_AT, $ts, now()->addDays(7));

        $pending = Cache::get(self::CACHE_WORKER_PENDING_PING);
        if ($token === null || (is_array($pending) && ($pending['token'] ?? null) === $token)) {
            Cache::forget(self::CACHE_WORKER_PENDING_PING);
        }

        return $ts;
    }

    public function dispatchWorkerPing(string $source = 'manual'): string
    {
        return QueueWorkerHeartbeatJob::dispatchPing($source);
    }

    /**
     * @return array<string, mixed>
     */
    public function collectHostMetrics(): array
    {
        $basePath = base_path();
        $diskTotal = @disk_total_space($basePath) ?: 0;
        $diskFree = @disk_free_space($basePath) ?: 0;
        $diskUsed = max(0, $diskTotal - $diskFree);
        $diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0.0;

        $ram = $this->readMemoryStats();
        $cpu = $this->readCpuLoad();

        return [
            'os' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'cpu_percent' => $cpu,
            'ram_total_mb' => $ram['total_mb'],
            'ram_used_mb' => $ram['used_mb'],
            'ram_percent' => $ram['percent'],
            'disk_total_gb' => round($diskTotal / 1073741824, 2),
            'disk_used_gb' => round($diskUsed / 1073741824, 2),
            'disk_percent' => $diskPercent,
        ];
    }

    /**
     * Lightweight, read-only readiness check for public /api/health probes.
     *
     * @return array{database: bool, cache: bool}
     */
    public function checkMinimalReadiness(): array
    {
        $dbOk = false;
        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Health readiness DB check failed', ['error' => $e->getMessage()]);
        }

        $cacheOk = false;
        try {
            Cache::get(self::CACHE_SCHEDULER_HEARTBEAT);
            $cacheOk = true;
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Health readiness cache check failed', ['error' => $e->getMessage()]);
        }

        return [
            'database' => $dbOk,
            'cache' => $cacheOk,
        ];
    }

    /**
     * @param  array{scheduler: array<string, mixed>, queue_worker: array<string, mixed>}|null  $automation
     * @return array<string, array{ok: bool, label: string, detail: string}>
     */
    public function checkConnectivity(bool $includeExternal = true, ?array $automation = null): array
    {
        $checks = [];

        // 1. Database
        $dbStart = microtime(true);
        try {
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $dbStart) * 1000, 1);
            $checks['database'] = [
                'ok' => true,
                'label' => 'Database (' . config('database.default') . ')',
                'detail' => "Connected ({$ms} ms)",
            ];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SystemHealthService DB check failed', ['error' => $e->getMessage()]);
            $checks['database'] = [
                'ok' => false,
                'label' => 'Database',
                'detail' => 'Database connection failed. Check server logs.',
            ];
        }

        // 2. Cache / Redis
        try {
            $key = 'health_ping_' . uniqid();
            Cache::put($key, 'ok', 10);
            $val = Cache::get($key);
            Cache::forget($key);
            $checks['cache'] = [
                'ok' => $val === 'ok',
                'label' => 'Cache / Lock (' . config('cache.default') . ')',
                'detail' => $val === 'ok' ? 'Read/Write OK' : 'Failed to read cache',
            ];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SystemHealthService cache check failed', ['error' => $e->getMessage()]);
            $checks['cache'] = [
                'ok' => false,
                'label' => 'Cache / Lock',
                'detail' => 'Cache store unreachable. Check server logs.',
            ];
        }

        $automation ??= $this->getAutomationStatus();

        // 3. Queue Worker & Backlog
        $checks['queue'] = [
            'ok' => (bool) ($automation['queue_worker']['ok'] ?? false),
            'state' => (string) ($automation['queue_worker']['state'] ?? 'pending'),
            'label' => (string) ($automation['queue_worker']['label'] ?? 'Queue'),
            'detail' => (string) ($automation['queue_worker']['detail'] ?? '-'),
        ];

        // 4. Laravel Scheduler Heartbeat
        $checks['scheduler'] = [
            'ok' => (bool) ($automation['scheduler']['ok'] ?? false),
            'state' => (string) ($automation['scheduler']['state'] ?? 'pending'),
            'label' => (string) ($automation['scheduler']['label'] ?? 'Laravel Scheduler'),
            'detail' => (string) ($automation['scheduler']['detail'] ?? '-'),
        ];

        $settings = CompanySetting::current();

        // 4. Sumopod config
        $checks['sumopod'] = [
            'ok' => !empty($settings->sumopod_api_key_encrypted),
            'label' => 'Sumopod (' . strtoupper($settings->sumopod_environment) . ')',
            'detail' => !empty($settings->sumopod_api_key_encrypted)
                ? 'API Key configured'
                : 'API Key not set',
        ];

        if ($includeExternal) {
            // 5. Go-WhatsApp
            if (empty($settings->wa_gateway_url)) {
                $checks['gowa'] = ['ok' => false, 'label' => 'Go-WhatsApp (GOWA)', 'detail' => 'Base URL not configured'];
            } else {
                $gowa = $this->gowaClient->getDevices();
                $connectedCount = collect($gowa['devices'])->count();
                $checks['gowa'] = [
                    'ok' => $gowa['ok'] && $connectedCount > 0,
                    'label' => 'Go-WhatsApp (GOWA)',
                    'detail' => $gowa['ok']
                        ? "{$connectedCount} device(s) registered"
                        : ($gowa['error'] ?? 'Unreachable'),
                ];
            }

            // 6. Telegram Bot
            $checks['telegram'] = [
                'ok' => $this->telegram->isConfigured(),
                'label' => 'Telegram Admin Bot',
                'detail' => $this->telegram->isConfigured()
                    ? 'Active'
                    : 'Disabled or token not set',
            ];
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function collectPanelStats(): array
    {
        return [
            'subscriptions_active' => Subscription::query()->where('status', SubscriptionStatus::Active->value)->count(),
            'subscriptions_isolated' => Subscription::query()->where('status', SubscriptionStatus::Isolated->value)->count(),
            'invoices_unpaid' => Invoice::query()->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])->count(),
            'routers_active' => MikrotikRouter::query()->where('is_active', true)->count(),
            'revenue_paid_net' => (float) Payment::query()->where('status', PaymentStatus::Paid->value)->sum('net_amount'),
        ];
    }

    /**
     * Detailed status summary for Laravel Scheduler and Queue Worker.
     *
     * @return array{scheduler: array<string, mixed>, queue_worker: array<string, mixed>}
     */
    public function getAutomationStatus(): array
    {
        return [
            'scheduler' => $this->getSchedulerStatus(),
            'queue_worker' => $this->getQueueWorkerStatus(),
        ];
    }

    /**
     * Detect Laravel Scheduler health using the latest heartbeat timestamp stored in Cache.
     *
     * @return array<string, mixed>
     */
    public function getSchedulerStatus(): array
    {
        $nowTs = now()->timestamp;

        try {
            $raw = Cache::get(self::CACHE_SCHEDULER_HEARTBEAT);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SystemHealthService scheduler status read failed', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'state' => 'failed',
                'status_label' => 'Cache Error',
                'label' => 'Laravel Scheduler',
                'detail' => 'Unable to read scheduler heartbeat from cache.',
                'last_run_at' => null,
                'last_run_iso' => null,
                'last_run_human' => null,
                'age_seconds' => null,
                'stale_after_seconds' => self::SCHEDULER_STALE_SECONDS,
                'recommended_command' => 'php artisan schedule:work',
            ];
        }

        $lastTickAt = is_numeric($raw) ? (int) $raw : null;

        if ($lastTickAt === null) {
            return [
                'ok' => false,
                'state' => 'pending',
                'status_label' => 'Not Detected',
                'label' => 'Laravel Scheduler',
                'detail' => 'No scheduler heartbeat recorded yet. Ensure cron or php artisan schedule:work is running.',
                'last_run_at' => null,
                'last_run_iso' => null,
                'last_run_human' => null,
                'age_seconds' => null,
                'stale_after_seconds' => self::SCHEDULER_STALE_SECONDS,
                'recommended_command' => 'php artisan schedule:work',
            ];
        }

        $ageSeconds = max(0, $nowTs - $lastTickAt);
        $isFresh = $ageSeconds <= self::SCHEDULER_STALE_SECONDS;
        $humanAge = $this->formatDuration($ageSeconds);
        $formattedAt = $this->formatTimestampWib($lastTickAt);

        return [
            'ok' => $isFresh,
            'state' => $isFresh ? 'ok' : 'failed',
            'status_label' => $isFresh ? 'Active' : 'Stopped / Stale',
            'label' => 'Laravel Scheduler',
            'detail' => $isFresh
                ? "Last heartbeat {$humanAge} ago ({$formattedAt})."
                : "No heartbeat for {$humanAge} ({$formattedAt}). Check schedule:work / cron process.",
            'last_run_at' => $formattedAt,
            'last_run_iso' => Carbon::createFromTimestamp($lastTickAt, 'Asia/Jakarta')->toIso8601String(),
            'last_run_human' => "{$humanAge} ago",
            'age_seconds' => $ageSeconds,
            'stale_after_seconds' => self::SCHEDULER_STALE_SECONDS,
            'recommended_command' => 'php artisan schedule:work',
        ];
    }

    /**
     * Detect Queue Worker health based on job backlog, failed jobs, and heartbeat ping.
     *
     * @return array<string, mixed>
     */
    public function getQueueWorkerStatus(): array
    {
        $connection = (string) config('queue.default', 'database');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $nowTs = now()->timestamp;

        $base = [
            'connection' => $connection,
            'driver' => $driver,
            'label' => "Queue Worker ({$connection})",
            'pending_jobs' => 0,
            'ready_jobs' => 0,
            'reserved_jobs' => 0,
            'failed_jobs' => 0,
            'oldest_wait_seconds' => 0,
            'oldest_wait_human' => null,
            'last_ping_at' => null,
            'last_ping_iso' => null,
            'last_ping_human' => null,
            'ping_pending' => false,
            'ping_pending_seconds' => null,
            'stuck_after_seconds' => self::QUEUE_STUCK_SECONDS,
            'recommended_command' => 'php artisan queue:work --tries=3',
        ];

        try {
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            $failedJobs = (int) DB::table($failedTable)->count();

            $pendingJobs = 0;
            $readyJobs = 0;
            $reservedJobs = 0;
            $oldestWaitSeconds = 0;

            if ($driver === 'database') {
                $jobsTable = (string) config("queue.connections.{$connection}.table", 'jobs');
                $pendingJobs = (int) DB::table($jobsTable)->count();
                $reservedJobs = (int) DB::table($jobsTable)->whereNotNull('reserved_at')->count();

                $readyQuery = DB::table($jobsTable)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $nowTs);

                $readyJobs = (int) (clone $readyQuery)->count();
                $oldestAvailableAt = (clone $readyQuery)->min('available_at');
                if ($oldestAvailableAt !== null) {
                    $oldestWaitSeconds = max(0, $nowTs - (int) $oldestAvailableAt);
                }
            }

            $rawPing = Cache::get(self::CACHE_WORKER_LAST_PING_AT);
            $lastPingAt = is_numeric($rawPing) ? (int) $rawPing : null;
            $pendingPing = Cache::get(self::CACHE_WORKER_PENDING_PING);
            $pendingPingAge = (is_array($pendingPing) && is_numeric($pendingPing['dispatched_at'] ?? null))
                ? max(0, $nowTs - (int) $pendingPing['dispatched_at'])
                : null;

            $lastPingFormatted = $this->formatTimestampWib($lastPingAt);
            $lastPingAge = $lastPingAt !== null ? max(0, $nowTs - $lastPingAt) : null;

            [$ok, $state, $statusLabel, $detail] = $this->evaluateQueueWorkerHealth(
                driver: $driver,
                failedJobs: $failedJobs,
                readyJobs: $readyJobs,
                oldestWaitSeconds: $oldestWaitSeconds,
                lastPingAgeSeconds: $lastPingAge,
                lastPingFormatted: $lastPingFormatted,
                pendingPingAgeSeconds: $pendingPingAge
            );

            return array_merge($base, [
                'ok' => $ok,
                'state' => $state,
                'status_label' => $statusLabel,
                'detail' => $detail,
                'pending_jobs' => $pendingJobs,
                'ready_jobs' => $readyJobs,
                'reserved_jobs' => $reservedJobs,
                'failed_jobs' => $failedJobs,
                'oldest_wait_seconds' => $oldestWaitSeconds,
                'oldest_wait_human' => $oldestWaitSeconds > 0 ? $this->formatDuration($oldestWaitSeconds) : null,
                'last_ping_at' => $lastPingFormatted,
                'last_ping_iso' => $lastPingAt !== null ? Carbon::createFromTimestamp($lastPingAt, 'Asia/Jakarta')->toIso8601String() : null,
                'last_ping_human' => $lastPingAge !== null ? $this->formatDuration($lastPingAge) . ' ago' : null,
                'ping_pending' => $pendingPingAge !== null,
                'ping_pending_seconds' => $pendingPingAge,
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SystemHealthService queue worker status read failed', ['error' => $e->getMessage()]);

            return array_merge($base, [
                'ok' => false,
                'state' => 'failed',
                'status_label' => 'Queue Error',
                'detail' => 'Unable to inspect queue worker status. Check server logs.',
            ]);
        }
    }

    /**
     * Evaluate final queue worker status into a [ok, state, statusLabel, detail] tuple.
     *
     * @return array{0: bool, 1: string, 2: string, 3: string}
     */
    private function evaluateQueueWorkerHealth(
        string $driver,
        int $failedJobs,
        int $readyJobs,
        int $oldestWaitSeconds,
        ?int $lastPingAgeSeconds,
        ?string $lastPingFormatted,
        ?int $pendingPingAgeSeconds
    ): array {
        if ($driver === 'sync') {
            if ($failedJobs > 0) {
                return [false, 'failed', 'Failed Jobs Detected', "{$failedJobs} failed job(s) recorded (sync driver)."];
            }

            return [true, 'ok', 'Active (Sync)', 'Sync driver executes jobs inline without a separate queue:work process.'];
        }

        if ($failedJobs > 0) {
            $suffix = $readyJobs > 0 ? " · {$readyJobs} job(s) waiting" : '';

            return [false, 'failed', 'Failed Jobs Detected', "{$failedJobs} failed job(s) failed to process{$suffix}."];
        }

        if ($readyJobs > 0 && $oldestWaitSeconds >= self::QUEUE_STUCK_SECONDS) {
            $wait = $this->formatDuration($oldestWaitSeconds);

            return [false, 'failed', 'Worker Stalled / Down', "{$readyJobs} ready job(s) stuck waiting {$wait} with no active worker."];
        }

        if ($pendingPingAgeSeconds !== null && $pendingPingAgeSeconds >= self::WORKER_PING_TIMEOUT_SECONDS) {
            $age = $this->formatDuration($pendingPingAgeSeconds);

            return [false, 'failed', 'Worker Unresponsive', "Test ping sent {$age} ago has not been processed by a queue worker."];
        }

        if ($pendingPingAgeSeconds !== null) {
            return [true, 'pending', 'Waiting for Worker', "Test ping dispatched {$pendingPingAgeSeconds}s ago, awaiting worker response..."];
        }

        if ($lastPingAgeSeconds !== null) {
            $pingAge = $this->formatDuration($lastPingAgeSeconds);
            if ($lastPingAgeSeconds > self::WORKER_PING_STALE_SECONDS) {
                return [true, 'pending', 'Verification Needed', "Last worker ping was {$pingAge} ago ({$lastPingFormatted}). Click Test Queue Worker or enable scheduler."];
            }

            $queueNote = $readyJobs > 0 ? " · {$readyJobs} queued" : ' · 0 jobs waiting';

            return [true, 'ok', 'Active', "Worker responded {$pingAge} ago ({$lastPingFormatted}){$queueNote}."];
        }

        if ($readyJobs > 0) {
            $wait = $this->formatDuration($oldestWaitSeconds);

            return [true, 'pending', 'Waiting in Queue', "{$readyJobs} job(s) queued (oldest {$wait}). No worker ping recorded yet."];
        }

        return [true, 'pending', 'Not Tested', '0 jobs waiting · Click "Test Queue Worker" to verify the worker process.'];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            $rem = $seconds % 60;

            return ($rem > 0 && $minutes < 5) ? "{$minutes}m {$rem}s" : "{$minutes}m";
        }

        if ($seconds < 86400) {
            $hours = (int) floor($seconds / 3600);
            $minutes = (int) floor(($seconds % 3600) / 60);

            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        $days = (int) floor($seconds / 86400);

        return "{$days}d";
    }

    private function formatTimestampWib(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp, 'Asia/Jakarta')->format('d M Y H:i:s') . ' WIB';
    }

    private function readCpuLoad(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                return round(min(100.0, (float) $load[0] * 25.0), 1);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('wmic cpu get loadpercentage /value 2>NUL');
            if (is_string($out) && preg_match('/LoadPercentage=(\d+)/', $out, $m)) {
                return (float) $m[1];
            }
        }

        return 0.0;
    }

    /**
     * @return array{total_mb: int, used_mb: int, percent: float}
     */
    private function readMemoryStats(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $content = (string) @file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $content, $t);
            preg_match('/MemAvailable:\s+(\d+)/', $content, $a);
            $totalKb = (int) ($t[1] ?? 0);
            $availKb = (int) ($a[1] ?? 0);
            $usedKb = max(0, $totalKb - $availKb);

            return [
                'total_mb' => (int) round($totalKb / 1024),
                'used_mb' => (int) round($usedKb / 1024),
                'percent' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : 0.0,
            ];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>NUL');
            if (
                is_string($out)
                && preg_match('/TotalVisibleMemorySize=(\d+)/', $out, $t)
                && preg_match('/FreePhysicalMemory=(\d+)/', $out, $f)
            ) {
                $totalKb = (int) $t[1];
                $freeKb = (int) $f[1];
                $usedKb = max(0, $totalKb - $freeKb);

                return [
                    'total_mb' => (int) round($totalKb / 1024),
                    'used_mb' => (int) round($usedKb / 1024),
                    'percent' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : 0.0,
                ];
            }
        }

        return ['total_mb' => 0, 'used_mb' => 0, 'percent' => 0.0];
    }
}
