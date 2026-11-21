<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Enums\SubscriptionStatus;
use App\Exceptions\MikrotikException;
use App\Jobs\MikrotikProvisioningJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use App\Models\MikrotikProvisioningLog;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Str;
use Throwable;

class MikrotikProvisioningService
{
    private ?string $lastError = null;

    private bool $lastErrorIsConnection = true;

    public function __construct(
        private readonly RouterOsClientFactory $clientFactory
    ) {
    }

    public function queueAction(
        Subscription $subscription,
        string $action,
        string $triggerSource,
        ?int $invoiceId = null,
        bool $notifyRestored = false,
        bool $afterCommit = true
    ): void {
        $updates = [
            'mikrotik_sync_status' => 'pending',
            'mikrotik_pending_action' => $action,
            'mikrotik_last_sync_error' => null,
        ];

        if ($action === 'isolate') {
            $updates['mikrotik_pending_notify'] = false;
            $updates['mikrotik_pending_invoice_id'] = null;
        } elseif ($notifyRestored) {
            $updates['mikrotik_pending_notify'] = true;
            $updates['mikrotik_pending_invoice_id'] = $invoiceId;
        }

        $subscription->forceFill($updates)->save();

        $pending = MikrotikProvisioningJob::dispatch($subscription, $action, $triggerSource);
        if ($afterCommit) {
            $pending->afterCommit();
        }
    }

    /**
     * @return array{ok: bool, error: ?string, is_connection_error: bool}
     */
    public function executeForSubscription(
        Subscription $subscription,
        string $action,
        string $triggerSource = 'webhook_paid',
        ?RouterOsClientInterface $client = null,
        bool $skipProfileSync = false
    ): array {
        $subscription->loadMissing(['mikrotikRouter', 'package', 'customer']);
        $router = $subscription->mikrotikRouter;
        $this->lastError = null;
        $this->lastErrorIsConnection = true;

        $ok = match ($action) {
            'sync_secret' => $this->syncSecret($subscription, $triggerSource, $client, $skipProfileSync),
            'isolate' => $this->isolate($subscription, $triggerSource, $client, $skipProfileSync),
            'restore' => $this->restore($subscription, $triggerSource, $client, $skipProfileSync),
            default => true,
        };

        if ($ok) {
            $shouldNotify = ($action === 'restore') && (bool) $subscription->mikrotik_pending_notify;
            $invoiceId = $subscription->mikrotik_pending_invoice_id;

            $subscription->forceFill([
                'mikrotik_sync_status' => 'synced',
                'mikrotik_pending_action' => null,
                'mikrotik_pending_notify' => false,
                'mikrotik_pending_invoice_id' => null,
                'mikrotik_sync_attempts' => 0,
                'mikrotik_last_sync_error' => null,
                'mikrotik_last_synced_at' => now(),
            ])->save();

            $router->forceFill([
                'is_reachable' => true,
                'last_health_check_at' => now(),
                'last_health_error' => null,
            ])->save();

            if ($shouldNotify && $subscription->customer !== null) {
                $invoice = $invoiceId ? Invoice::query()->find($invoiceId) : null;
                SendWhatsAppNotificationJob::dispatch($subscription->customer, 'service_restored', $invoice);
            }

            return ['ok' => true, 'error' => null, 'is_connection_error' => false];
        }

        $err = Str::limit((string) ($this->lastError ?: 'MikroTik router is unreachable.'), 1000);

        $subscription->forceFill([
            'mikrotik_sync_attempts' => $subscription->mikrotik_sync_attempts + 1,
            'mikrotik_last_sync_error' => $err,
        ])->save();

        $router->forceFill([
            'is_reachable' => !$this->lastErrorIsConnection,
            'last_health_check_at' => now(),
            'last_health_error' => $err,
        ])->save();

        return [
            'ok' => false,
            'error' => $err,
            'is_connection_error' => $this->lastErrorIsConnection,
        ];
    }

    /**
     * 1-way authoritative sync (Application Database = Source of Truth -> MikroTik follows).
     */
    public function syncSecret(
        Subscription $subscription,
        string $triggerSource = 'manual_noc',
        ?RouterOsClientInterface $client = null,
        bool $skipProfileSync = false
    ): bool {
        $subscription->loadMissing(['mikrotikRouter', 'package', 'customer']);

        if ($subscription->status === SubscriptionStatus::Isolated) {
            return $this->isolate($subscription, $triggerSource, $client, $skipProfileSync);
        }

        $router = $subscription->mikrotikRouter;
        $this->lastError = null;
        $this->lastErrorIsConnection = true;

        try {
            $activeClient = $client ?? $this->clientFactory->make($router);
            $removedIsolir = false;

            if ($subscription->status === SubscriptionStatus::Active && !empty($subscription->static_ip_address)) {
                $removedIsolir = $this->removeIsolirAddressList($activeClient, $router, $subscription->static_ip_address);
            }

            $syncResult = $this->ensureSecretSynced($activeClient, $subscription, !$skipProfileSync);

            if (
                $removedIsolir
                || $syncResult['reconnect_needed']
                || in_array($subscription->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Terminated], true)
            ) {
                $this->disconnectActiveSession($activeClient, $subscription);
            }

            $this->log($subscription, $router, $syncResult['action'], $triggerSource, true, 'Secret synced from app database.');

            return true;
        } catch (Throwable $e) {
            $this->recordException($e);
            $this->log($subscription, $router, 'create_secret', $triggerSource, false, $e->getMessage());

            return false;
        }
    }

    public function isolate(
        Subscription $subscription,
        string $triggerSource = 'scheduler_isolir',
        ?RouterOsClientInterface $client = null,
        bool $skipProfileSync = false
    ): bool {
        $subscription->loadMissing(['mikrotikRouter', 'package', 'customer']);
        $router = $subscription->mikrotikRouter;
        $this->lastError = null;
        $this->lastErrorIsConnection = true;

        try {
            $activeClient = $client ?? $this->clientFactory->make($router);

            if (!empty($subscription->static_ip_address)) {
                $existing = $activeClient->print('/ip/firewall/address-list', [
                    'list' => $router->isolir_address_list,
                    'address' => $subscription->static_ip_address,
                ]);

                if (empty($existing)) {
                    $activeClient->add('/ip/firewall/address-list', [
                        'list' => $router->isolir_address_list,
                        'address' => $subscription->static_ip_address,
                        'comment' => "ISOLIR:{$subscription->service_username}",
                    ]);
                }
            }

            $isolirProfile = $subscription->isolir_profile_name ?: $router->isolir_profile_name;
            if (!$skipProfileSync && !empty($isolirProfile)) {
                $this->ensureIsolirProfileExists($activeClient, $isolirProfile);
            }

            $this->ensureSecretSynced(
                $activeClient,
                $subscription,
                !$skipProfileSync,
                !empty($isolirProfile) ? $isolirProfile : null
            );

            $this->disconnectActiveSession($activeClient, $subscription);
            $this->log($subscription, $router, 'isolate', $triggerSource, true, 'Subscriber isolated.');

            return true;
        } catch (Throwable $e) {
            $this->recordException($e);
            $this->log($subscription, $router, 'isolate', $triggerSource, false, $e->getMessage());

            return false;
        }
    }

    public function restore(
        Subscription $subscription,
        string $triggerSource = 'webhook_paid',
        ?RouterOsClientInterface $client = null,
        bool $skipProfileSync = false
    ): bool {
        $subscription->loadMissing(['mikrotikRouter', 'package', 'customer']);
        $router = $subscription->mikrotikRouter;
        $this->lastError = null;
        $this->lastErrorIsConnection = true;

        try {
            $activeClient = $client ?? $this->clientFactory->make($router);

            if (!empty($subscription->static_ip_address)) {
                $this->removeIsolirAddressList($activeClient, $router, $subscription->static_ip_address);
            }

            // Ensure profile & secret match application DB before disconnecting old active session
            $this->ensureSecretSynced($activeClient, $subscription, !$skipProfileSync);

            $this->disconnectActiveSession($activeClient, $subscription);
            $this->log($subscription, $router, 'restore', $triggerSource, true, 'Subscriber restored.');

            return true;
        } catch (Throwable $e) {
            $this->recordException($e);
            $this->log($subscription, $router, 'restore', $triggerSource, false, $e->getMessage());

            return false;
        }
    }

    /**
     * Full 1-way sync (Application Database = Source of Truth -> MikroTik):
     * synchronizes all package profiles, isolir profile, and all subscriptions assigned to this router.
     *
     * @return array{
     *     identity: string,
     *     version: string,
     *     profiles_synced: int,
     *     profiles_created: int,
     *     profiles_updated: int,
     *     subscriptions_total: int,
     *     subscriptions_synced: int,
     *     subscriptions_failed: int,
     *     errors: array<int, string>
     * }
     */
    public function syncRouterFromDatabase(MikrotikRouter $router, string $triggerSource = 'manual_sync'): array
    {
        $info = $this->testConnection($router);
        $client = $this->clientFactory->make($router);

        $packages = Package::query()
            ->with('routers:id')
            ->where(function ($q) use ($router): void {
                $q->where('is_active', true)
                    ->orWhereHas('subscriptions', fn ($s) => $s->where('mikrotik_router_id', $router->id));
            })
            ->get()
            ->filter(fn (Package $pkg): bool => $pkg->supportsRouter($router->id)
                || $pkg->subscriptions()->where('mikrotik_router_id', $router->id)->exists());

        $profilesCreated = 0;
        $profilesUpdated = 0;
        $profilesSynced = 0;

        foreach ($packages as $package) {
            $status = $this->ensureProfileSynced($client, $package);
            $profilesSynced++;

            if ($status === 'created') {
                $profilesCreated++;
            } elseif ($status === 'updated') {
                $profilesUpdated++;
            }
        }

        if (!empty($router->isolir_profile_name)) {
            $this->ensureIsolirProfileExists($client, $router->isolir_profile_name);
        }

        $subscriptions = Subscription::query()
            ->with(['mikrotikRouter', 'package', 'customer'])
            ->where('mikrotik_router_id', $router->id)
            ->orderBy('id')
            ->get();

        $syncedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($subscriptions as $sub) {
            $action = match (true) {
                $sub->status === SubscriptionStatus::Isolated => 'isolate',
                $sub->mikrotik_pending_action === 'restore' => 'restore',
                default => 'sync_secret',
            };

            $res = $this->executeForSubscription($sub, $action, $triggerSource, $client, skipProfileSync: true);
            if ($res['ok']) {
                $syncedCount++;
            } else {
                $sub->forceFill([
                    'mikrotik_sync_status' => 'failed',
                    'mikrotik_pending_action' => $sub->mikrotik_pending_action ?: $action,
                ])->save();
                $failedCount++;
                $errors[] = "{$sub->service_username}: {$res['error']}";
            }
        }

        return [
            'identity' => $info['identity'],
            'version' => $info['version'],
            'profiles_synced' => $profilesSynced,
            'profiles_created' => $profilesCreated,
            'profiles_updated' => $profilesUpdated,
            'subscriptions_total' => $subscriptions->count(),
            'subscriptions_synced' => $syncedCount,
            'subscriptions_failed' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{identity: string, version: string, uptime: string, ros_type: string}
     */
    public function testConnection(MikrotikRouter $router): array
    {
        try {
            $client = $this->clientFactory->make($router);
            $identity = $client->print('/system/identity');
            $resource = $client->print('/system/resource');

            $version = (string) ($resource[0]['version'] ?? 'unknown');

            $router->forceFill([
                'is_reachable' => true,
                'last_health_check_at' => now(),
                'last_health_error' => null,
                'ros_version' => $version !== 'unknown' ? $version : $router->ros_version,
            ])->save();

            return [
                'identity' => (string) ($identity[0]['name'] ?? $router->name),
                'version' => $version,
                'uptime' => (string) ($resource[0]['uptime'] ?? 'unknown'),
                'ros_type' => $router->ros_version_type,
            ];
        } catch (Throwable $e) {
            $isConnection = !($e instanceof MikrotikException) || $e->isConnectionError;

            $router->forceFill([
                'is_reachable' => !$isConnection,
                'last_health_check_at' => now(),
                'last_health_error' => Str::limit($e->getMessage(), 1000),
            ])->save();

            throw $e;
        }
    }

    /**
     * Install default filter drop & isolation DNS/HTTP NAT rules idempotently.
     *
     * @return array{rules_added: int, address_list: string}
     */
    public function setupDefaultFirewall(MikrotikRouter $router, ?string $redirectPortalIp = null): array
    {
        $client = $this->clientFactory->make($router);
        $list = $router->isolir_address_list ?: 'ISOLIR';
        $added = 0;

        $filterDropComment = 'billing-isolir-default-drop';
        $existingFilter = $client->print('/ip/firewall/filter', ['comment' => $filterDropComment]);
        if ($existingFilter === []) {
            $client->add('/ip/firewall/filter', [
                'chain' => 'forward',
                'src-address-list' => $list,
                'protocol' => 'tcp',
                'dst-port' => '!80,443,53',
                'action' => 'drop',
                'comment' => $filterDropComment,
            ]);
            $added++;
        }

        $filterUdpComment = 'billing-isolir-default-drop-udp';
        $existingUdp = $client->print('/ip/firewall/filter', ['comment' => $filterUdpComment]);
        if ($existingUdp === []) {
            $client->add('/ip/firewall/filter', [
                'chain' => 'forward',
                'src-address-list' => $list,
                'protocol' => 'udp',
                'dst-port' => '!53',
                'action' => 'drop',
                'comment' => $filterUdpComment,
            ]);
            $added++;
        }

        $natComment = 'billing-isolir-default-nat';
        $existingNat = $client->print('/ip/firewall/nat', ['comment' => $natComment]);
        if ($existingNat === [] && !empty($redirectPortalIp)) {
            $client->add('/ip/firewall/nat', [
                'chain' => 'dstnat',
                'src-address-list' => $list,
                'protocol' => 'tcp',
                'dst-port' => '80',
                'action' => 'dst-nat',
                'to-addresses' => $redirectPortalIp,
                'to-ports' => '80',
                'comment' => $natComment,
            ]);
            $added++;
        }

        $router->forceFill([
            'is_reachable' => true,
            'last_health_check_at' => now(),
            'last_health_error' => null,
            'default_firewall_installed_at' => now(),
        ])->save();

        return [
            'rules_added' => $added,
            'address_list' => $list,
        ];
    }

    /**
     * Ensure the package profile exists on MikroTik and its rate-limit matches the application Package record.
     * RouterOS rate-limit format: rx/tx = upload/download (e.g. 10M/20M).
     */
    private function ensureProfileSynced(RouterOsClientInterface $client, Package $package): string
    {
        $profilePath = '/ppp/profile';
        $profileName = $package->mikrotik_profile_name;
        $rateLimit = "{$package->speed_upload_mbps}M/{$package->speed_download_mbps}M";
        $comment = "BILLING:{$package->code}";

        $existing = $client->print($profilePath, ['name' => $profileName]);
        if (empty($existing[0]['.id'])) {
            $client->add($profilePath, [
                'name' => $profileName,
                'rate-limit' => $rateLimit,
                'comment' => $comment,
            ]);

            return 'created';
        }

        $current = $existing[0];
        $updates = [];
        if (($current['rate-limit'] ?? '') !== $rateLimit) {
            $updates['rate-limit'] = $rateLimit;
        }
        if (($current['comment'] ?? '') !== $comment) {
            $updates['comment'] = $comment;
        }

        if ($updates !== []) {
            $client->set($profilePath, $current['.id'], $updates);

            return 'updated';
        }

        return 'unchanged';
    }

    private function ensureIsolirProfileExists(RouterOsClientInterface $client, string $isolirProfile): void
    {
        $existing = $client->print('/ppp/profile', ['name' => $isolirProfile]);

        if (empty($existing[0]['.id'])) {
            $client->add('/ppp/profile', ['name' => $isolirProfile]);
        }
    }

    private function removeIsolirAddressList(RouterOsClientInterface $client, MikrotikRouter $router, string $ipAddress): bool
    {
        $entries = $client->print('/ip/firewall/address-list', [
            'list' => $router->isolir_address_list,
            'address' => $ipAddress,
        ]);

        $removed = false;
        foreach ($entries as $entry) {
            if (!empty($entry['.id'])) {
                $client->remove('/ip/firewall/address-list', $entry['.id']);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * @return array{action: string, reconnect_needed: bool}
     */
    private function ensureSecretSynced(
        RouterOsClientInterface $client,
        Subscription $subscription,
        bool $ensureProfile = true,
        ?string $overrideProfile = null
    ): array {
        if ($ensureProfile) {
            $this->ensureProfileSynced($client, $subscription->package);
        }

        $basePath = '/ppp/secret';
        $existing = $client->print($basePath, ['name' => $subscription->service_username]);
        $password = (string) $subscription->service_password_encrypted;
        $targetProfile = $overrideProfile ?: $subscription->package->mikrotik_profile_name;
        $disabled = in_array($subscription->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Terminated], true)
            ? 'yes'
            : 'no';
        $comment = 'BILLING:' . ($subscription->customer?->customer_code ?? "SUB-{$subscription->id}");
        $desiredIp = trim((string) ($subscription->static_ip_address ?? ''));

        if (!empty($existing[0]['.id'])) {
            $row = $existing[0];
            $setAttrs = [
                'password' => $password,
                'profile' => $targetProfile,
                'disabled' => $disabled,
                'comment' => $comment,
            ];
            if ($desiredIp !== '') {
                $setAttrs['remote-address'] = $desiredIp;
            }

            $client->set($basePath, $row['.id'], $setAttrs);

            // Remove stale remote-address on MikroTik if cleared in app DB (dynamic IP)
            $clearedIp = false;
            if ($desiredIp === '' && !empty($row['remote-address'])) {
                try {
                    $client->command("{$basePath}/unset", [
                        '.id' => $row['.id'],
                        'value-name' => 'remote-address',
                    ]);
                    $clearedIp = true;
                } catch (Throwable) {
                    // Ignore if router/client version does not support unset via REST
                }
            }

            $reconnectNeeded = ($row['profile'] ?? '') !== $targetProfile
                || ($row['disabled'] ?? 'no') !== $disabled
                || ($desiredIp !== '' && ($row['remote-address'] ?? '') !== $desiredIp)
                || $clearedIp;

            return [
                'action' => 'update_profile',
                'reconnect_needed' => $reconnectNeeded,
            ];
        }

        $attrs = [
            'name' => $subscription->service_username,
            'password' => $password,
            'profile' => $targetProfile,
            'disabled' => $disabled,
            'comment' => $comment,
            'service' => 'pppoe',
        ];
        if ($desiredIp !== '') {
            $attrs['remote-address'] = $desiredIp;
        }
        $client->add($basePath, $attrs);

        return [
            'action' => 'create_secret',
            'reconnect_needed' => false,
        ];
    }

    private function disconnectActiveSession(RouterOsClientInterface $client, Subscription $subscription): void
    {
        $activePath = '/ppp/active';
        $sessions = $client->print($activePath, ['name' => $subscription->service_username]);

        foreach ($sessions as $session) {
            if (!empty($session['.id'])) {
                $client->remove($activePath, $session['.id']);
            }
        }
    }

    private function recordException(Throwable $e): void
    {
        $this->lastError = $e->getMessage();
        $this->lastErrorIsConnection = $e instanceof MikrotikException ? $e->isConnectionError : true;
    }

    private function log(
        Subscription $subscription,
        MikrotikRouter $router,
        string $action,
        string $triggerSource,
        bool $isSuccess,
        string $message
    ): void {
        MikrotikProvisioningLog::query()->create([
            'subscription_id' => $subscription->id,
            'mikrotik_router_id' => $router->id,
            'action' => $action,
            'trigger_source' => $triggerSource,
            'is_success' => $isSuccess,
            'response_message' => $message,
            'created_at' => now(),
        ]);
    }
}
