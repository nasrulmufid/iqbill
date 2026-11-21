<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;

class PppoeManagementService
{
    public function __construct(
        private readonly RouterOsClientFactory $clientFactory
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listProfiles(MikrotikRouter $router): array
    {
        return $this->clientFactory->make($router)->print('/ppp/profile');
    }

    /**
     * @param  array<string, string>  $attributes
     */
    public function createProfile(MikrotikRouter $router, array $attributes): void
    {
        $this->clientFactory->make($router)->add('/ppp/profile', array_filter(
            $attributes,
            static fn ($v) => $v !== null && $v !== ''
        ));
    }

    public function deleteProfile(MikrotikRouter $router, string $id): void
    {
        $this->clientFactory->make($router)->remove('/ppp/profile', $id);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listSecrets(MikrotikRouter $router): array
    {
        return $this->clientFactory->make($router)->print('/ppp/secret');
    }

    /**
     * @param  array<string, string>  $attributes
     */
    public function createSecret(MikrotikRouter $router, array $attributes): void
    {
        $this->clientFactory->make($router)->add('/ppp/secret', array_filter(
            $attributes + ['service' => 'pppoe'],
            static fn ($v) => $v !== null && $v !== ''
        ));
    }

    public function toggleSecret(MikrotikRouter $router, string $id, bool $disable): void
    {
        $this->clientFactory->make($router)->set('/ppp/secret', $id, [
            'disabled' => $disable ? 'yes' : 'no',
        ]);
    }

    public function deleteSecret(MikrotikRouter $router, string $id): void
    {
        $this->clientFactory->make($router)->remove('/ppp/secret', $id);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listActiveSessions(MikrotikRouter $router): array
    {
        return $this->clientFactory->make($router)->print('/ppp/active');
    }

    public function disconnectSession(MikrotikRouter $router, string $id): void
    {
        $this->clientFactory->make($router)->remove('/ppp/active', $id);
    }

    /**
     * Mengambil trafik rx/tx interface PPPoE (mis. <pppoe-user>).
     *
     * @return array{rx_bps: int, tx_bps: int, interface: string}
     */
    public function monitorTraffic(MikrotikRouter $router, string $interfaceName): array
    {
        $client = $this->clientFactory->make($router);
        $result = $client->command('/interface/monitor-traffic', [
            'interface' => $interfaceName,
            'once' => '',
        ]);

        $row = $result[0] ?? [];

        return [
            'interface' => $interfaceName,
            'rx_bps' => (int) ($row['rx-bits-per-second'] ?? 0),
            'tx_bps' => (int) ($row['tx-bits-per-second'] ?? 0),
        ];
    }
}
