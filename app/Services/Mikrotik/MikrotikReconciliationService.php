<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Enums\SubscriptionStatus;
use App\Models\MikrotikRouter;
use App\Models\Subscription;
use App\Services\Notification\TelegramNotificationService;
use Throwable;

class MikrotikReconciliationService
{
    public function __construct(
        private readonly MikrotikProvisioningService $provisioning,
        private readonly TelegramNotificationService $telegram
    ) {
    }

    /**
     * Reconcile pending provisioning queues with a single health probe per router.
     *
     * @return array{routers_checked: int, routers_offline: int, synced: int, failed: int}
     */
    public function reconcileAll(?int $onlyRouterId = null, string $triggerSource = 'reconciler'): array
    {
        $routerIds = Subscription::query()
            ->whereIn('mikrotik_sync_status', ['pending', 'failed'])
            ->whereNotNull('mikrotik_pending_action')
            ->when($onlyRouterId !== null, fn ($q) => $q->where('mikrotik_router_id', $onlyRouterId))
            ->distinct()
            ->pluck('mikrotik_router_id');

        $routers = MikrotikRouter::query()
            ->whereIn('id', $routerIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $summary = [
            'routers_checked' => 0,
            'routers_offline' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        foreach ($routers as $router) {
            $summary['routers_checked']++;

            try {
                $this->provisioning->testConnection($router);
            } catch (Throwable) {
                $summary['routers_offline']++;

                continue;
            }

            $pendingSubs = Subscription::query()
                ->with(['mikrotikRouter', 'package', 'customer'])
                ->where('mikrotik_router_id', $router->id)
                ->whereIn('mikrotik_sync_status', ['pending', 'failed'])
                ->whereNotNull('mikrotik_pending_action')
                ->orderBy('id')
                ->get();

            $routerSynced = 0;

            foreach ($pendingSubs as $sub) {
                $action = (string) $sub->mikrotik_pending_action;
                $res = $this->provisioning->executeForSubscription($sub, $action, $triggerSource);

                if ($res['ok']) {
                    $routerSynced++;
                    $summary['synced']++;
                } else {
                    $sub->forceFill(['mikrotik_sync_status' => 'failed'])->save();
                    $summary['failed']++;
                }
            }

            if ($routerSynced > 0) {
                $this->telegram->sendMessage(
                    "✅ <b>Router {$router->name} Back Online</b>\n{$routerSynced} pending customer service task(s) automatically reconciled &amp; synced."
                );
            }
        }

        return $summary;
    }

    /**
     * Synchronize a single subscription directly from the NOC/Admin panel (1-way: App DB -> MikroTik).
     *
     * @return array{ok: bool, action: string, error: ?string, is_connection_error: bool}
     */
    public function reconcileSubscription(Subscription $subscription, string $triggerSource = 'manual_noc'): array
    {
        $action = $subscription->mikrotik_pending_action
            ?: match ($subscription->status) {
                SubscriptionStatus::Isolated => 'isolate',
                SubscriptionStatus::Active => 'sync_secret',
                default => 'sync_secret',
            };

        $res = $this->provisioning->executeForSubscription($subscription, $action, $triggerSource);

        if (!$res['ok']) {
            $subscription->forceFill([
                'mikrotik_sync_status' => 'failed',
                'mikrotik_pending_action' => $action,
            ])->save();
        }

        return [
            'ok' => $res['ok'],
            'action' => $action,
            'error' => $res['error'],
            'is_connection_error' => $res['is_connection_error'],
        ];
    }
}
