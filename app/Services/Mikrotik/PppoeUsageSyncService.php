<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Models\CompanySetting;
use App\Models\MikrotikRouter;
use App\Models\PppoeUsageLog;
use App\Models\Subscription;
use Throwable;

class PppoeUsageSyncService
{
    public function __construct(
        private readonly RouterOsClientFactory $clientFactory
    ) {
    }

    /**
     * @return array{synced: int, skipped: bool}
     */
    public function syncAll(): array
    {
        if (!CompanySetting::current()->usage_tracking_enabled) {
            return ['synced' => 0, 'skipped' => true];
        }

        $routers = MikrotikRouter::query()->where('is_active', true)->get();
        $synced = 0;

        foreach ($routers as $router) {
            try {
                $synced += $this->syncRouter($router);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['synced' => $synced, 'skipped' => false];
    }

    public function syncRouter(MikrotikRouter $router): int
    {
        $client = $this->clientFactory->make($router);
        $sessions = $client->print('/ppp/active');
        if ($sessions === []) {
            return 0;
        }

        // Read byte counters from /interface (dynamic PPPoE interface is typically named <pppoe-username>)
        $interfaces = collect($client->print('/interface'))
            ->keyBy(fn (array $row) => (string) ($row['name'] ?? ''));

        $subscriptions = Subscription::query()
            ->where('mikrotik_router_id', $router->id)
            ->get()
            ->keyBy('service_username');

        $count = 0;
        $now = now('Asia/Jakarta');

        foreach ($sessions as $session) {
            $username = (string) ($session['name'] ?? '');
            /** @var Subscription|null $subscription */
            $subscription = $subscriptions->get($username);
            if ($subscription === null) {
                continue;
            }

            $iface = $interfaces->get("<pppoe-{$username}>") ?? [];
            $counterRx = (int) ($iface['rx-byte'] ?? $session['bytes-in'] ?? 0);
            $counterTx = (int) ($iface['tx-byte'] ?? $session['bytes-out'] ?? 0);
            $sessionId = (string) ($session['.id'] ?? $session['session-id'] ?? '');

            $lastLog = PppoeUsageLog::query()
                ->where('subscription_id', $subscription->id)
                ->latest('id')
                ->first();

            $rxDelta = $this->computeDelta($counterRx, $lastLog?->last_counter_rx, $lastLog?->session_id, $sessionId);
            $txDelta = $this->computeDelta($counterTx, $lastLog?->last_counter_tx, $lastLog?->session_id, $sessionId);

            PppoeUsageLog::query()->create([
                'subscription_id' => $subscription->id,
                'mikrotik_router_id' => $router->id,
                'session_id' => $sessionId ?: null,
                'address' => $session['address'] ?? null,
                'rx_bytes_delta' => $rxDelta,
                'tx_bytes_delta' => $txDelta,
                'last_counter_rx' => $counterRx,
                'last_counter_tx' => $counterTx,
                'uptime_seconds' => $this->parseUptimeToSeconds((string) ($session['uptime'] ?? '')),
                'recorded_date' => $now->toDateString(),
                'recorded_at' => $now,
            ]);

            $count++;
        }

        return $count;
    }

    private function computeDelta(int $current, ?int $previous, ?string $prevSession, string $currSession): int
    {
        if ($previous === null) {
            return $current;
        }

        // If session ID changed or router counters reset, calculate new delta from current value
        if ($prevSession !== null && $currSession !== '' && $prevSession !== $currSession) {
            return $current;
        }

        return $current >= $previous ? ($current - $previous) : $current;
    }

    private function parseUptimeToSeconds(string $uptime): int
    {
        if ($uptime === '') {
            return 0;
        }

        $seconds = 0;
        if (preg_match('/(\d+)w/', $uptime, $m)) {
            $seconds += (int) $m[1] * 604800;
        }
        if (preg_match('/(\d+)d/', $uptime, $m)) {
            $seconds += (int) $m[1] * 86400;
        }
        if (preg_match('/(\d+)h/', $uptime, $m)) {
            $seconds += (int) $m[1] * 3600;
        }
        if (preg_match('/(\d+)m/', $uptime, $m)) {
            $seconds += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)s/', $uptime, $m)) {
            $seconds += (int) $m[1];
        }

        return $seconds;
    }
}
