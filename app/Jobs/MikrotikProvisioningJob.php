<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikProvisioningService;
use App\Services\Notification\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class MikrotikProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $action,
        public readonly string $triggerSource = 'webhook_paid'
    ) {
    }

    public function handle(MikrotikProvisioningService $service, TelegramNotificationService $telegram): void
    {
        $sub = $this->subscription->fresh(['mikrotikRouter', 'package', 'customer']);
        if ($sub === null) {
            return;
        }

        // Last-Write-Wins guard: skip older jobs superseded by a newer pending action
        if ($sub->mikrotik_pending_action !== null && $sub->mikrotik_pending_action !== $this->action) {
            return;
        }

        if ($this->action === 'isolate' && $sub->status !== SubscriptionStatus::Isolated && $sub->mikrotik_pending_action === null) {
            return;
        }

        if ($this->action === 'restore' && $sub->status === SubscriptionStatus::Isolated && $sub->mikrotik_pending_action === null) {
            return;
        }

        $res = $service->executeForSubscription($sub, $this->action, $this->triggerSource);
        if ($res['ok']) {
            return;
        }

        $isSyncDriver = $this->job === null || $this->job instanceof SyncJob;
        $attempt = method_exists($this, 'attempts') ? $this->attempts() : 1;

        if (!$isSyncDriver && $attempt < $this->tries) {
            $delay = $this->backoff[$attempt - 1] ?? 180;
            $sub->forceFill(['mikrotik_sync_status' => 'pending'])->save();
            $this->release($delay);

            return;
        }

        $sub->forceFill([
            'mikrotik_sync_status' => 'failed',
            'mikrotik_pending_action' => $sub->mikrotik_pending_action ?: $this->action,
        ])->save();

        $routerName = $sub->mikrotikRouter?->name ?? 'Unknown Router';
        $alertTitle = ($res['is_connection_error'] ?? true)
            ? "MikroTik Offline ({$routerName})"
            : "MikroTik Sync Failed ({$routerName})";
        $telegram->notifyError(
            $alertTitle,
            "Failed to {$this->action} {$sub->service_username} (trigger: {$this->triggerSource}): {$res['error']}. Queued for automatic reconciliation."
        );
    }

    public function failed(Throwable $exception): void
    {
        $sub = $this->subscription->fresh(['mikrotikRouter']);
        if ($sub === null) {
            return;
        }

        if ($sub->mikrotik_pending_action !== null && $sub->mikrotik_pending_action !== $this->action) {
            return;
        }

        $sub->forceFill([
            'mikrotik_sync_status' => 'failed',
            'mikrotik_pending_action' => $sub->mikrotik_pending_action ?: $this->action,
            'mikrotik_last_sync_error' => Str::limit($exception->getMessage(), 1000),
        ])->save();

        $routerName = $sub->mikrotikRouter?->name ?? 'Unknown Router';
        app(TelegramNotificationService::class)->notifyError(
            "MikroTik Offline ({$routerName})",
            "Job permanently failed ({$this->action}) for {$sub->service_username}: {$exception->getMessage()}"
        );
    }
}
