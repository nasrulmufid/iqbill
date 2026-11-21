<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Monitoring\SystemHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Lightweight heartbeat job to verify the queue worker is actively processing queued jobs.
 */
class QueueWorkerHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly ?string $pingToken = null,
        public readonly string $source = 'manual'
    ) {
    }

    /**
     * Record a new ping token and dispatch the heartbeat job to verify execution.
     */
    public static function dispatchPing(string $source = 'manual'): string
    {
        $token = (string) Str::uuid();
        SystemHealthService::recordWorkerPingDispatched($token, $source);
        self::dispatch($token, $source);

        return $token;
    }

    /**
     * Dispatch an automated ping from the scheduler only when driver is not sync and no ping is pending.
     */
    public static function dispatchAutoPingIfIdle(): ?string
    {
        if (config('queue.default') === 'sync' || SystemHealthService::hasPendingWorkerPing()) {
            return null;
        }

        return self::dispatchPing('scheduler');
    }

    public function handle(): void
    {
        SystemHealthService::recordWorkerPingProcessed($this->pingToken);
    }
}
