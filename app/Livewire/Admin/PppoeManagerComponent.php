<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\MikrotikRouter;
use App\Services\Mikrotik\PppoeManagementService;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

class PppoeManagerComponent extends Component
{
    public ?int $routerId = null;

    public string $tab = 'active';

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    /** @var array<int, array<string, string>> */
    public array $activeSessions = [];

    /** @var array<int, array<string, string>> */
    public array $secrets = [];

    /** @var array<int, array<string, string>> */
    public array $profiles = [];

    public ?string $monitoredInterface = null;

    /** @var array{rx_bps: int, tx_bps: int, interface: string}|null */
    public ?array $traffic = null;

    // Modal states
    public bool $addSecretOpen = false;

    public bool $addProfileOpen = false;

    // Form Profile
    public string $profileName = '';

    public string $profileLocalAddr = '';

    public string $profileRemotePool = '';

    public string $profileRateLimit = '';

    // Form Secret
    public string $secretName = '';

    public string $secretPassword = '';

    public string $secretProfile = '';

    public string $secretRemoteAddress = '';

    public function mount(): void
    {
        $first = MikrotikRouter::query()->where('is_active', true)->orderBy('id')->first();
        $this->routerId = $first?->id;
        $this->loadData();
    }

    public function updatedRouterId(): void
    {
        $this->monitoredInterface = null;
        $this->traffic = null;
        $this->loadData();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->errorMessage = null;
        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            $service = app(PppoeManagementService::class);
            if ($this->tab === 'active') {
                $this->activeSessions = $service->listActiveSessions($router);
            } elseif ($this->tab === 'secrets') {
                $this->secrets = $service->listSecrets($router);
                $this->profiles = $service->listProfiles($router);
            } else {
                $this->profiles = $service->listProfiles($router);
            }
        } catch (Throwable $e) {
            $this->errorMessage = 'Failed to fetch PPPoE data from router: ' . $e->getMessage();
        }
    }

    public function startMonitor(string $username): void
    {
        $this->monitoredInterface = "<pppoe-{$username}>";
        $this->refreshTraffic();
    }

    public function stopMonitor(): void
    {
        $this->monitoredInterface = null;
        $this->traffic = null;
    }

    public function refreshTraffic(): void
    {
        if (empty($this->monitoredInterface) || ($router = $this->currentRouter()) === null) {
            return;
        }

        try {
            $this->traffic = app(PppoeManagementService::class)->monitorTraffic($router, $this->monitoredInterface);
        } catch (Throwable $e) {
            $this->errorMessage = 'Traffic monitor failed: ' . $e->getMessage();
        }
    }

    public function disconnectSession(string $id): void
    {
        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->disconnectSession($router, $id);
            $this->successMessage = 'PPPoE session disconnected.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function createProfile(): void
    {
        $this->validate([
            'profileName' => ['required', 'string', 'max:60'],
        ]);

        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->createProfile($router, [
                'name' => $this->profileName,
                'local-address' => $this->profileLocalAddr,
                'remote-address' => $this->profileRemotePool,
                'rate-limit' => $this->profileRateLimit,
            ]);
            $this->reset(['profileName', 'profileLocalAddr', 'profileRemotePool', 'profileRateLimit']);
            $this->addProfileOpen = false;
            $this->successMessage = 'PPPoE profile added successfully.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function deleteProfile(string $id): void
    {
        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->deleteProfile($router, $id);
            $this->successMessage = 'PPPoE profile deleted.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function createSecret(): void
    {
        $this->validate([
            'secretName' => ['required', 'string', 'max:60'],
            'secretPassword' => ['required', 'string'],
            'secretProfile' => ['required', 'string'],
        ]);

        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->createSecret($router, [
                'name' => $this->secretName,
                'password' => $this->secretPassword,
                'profile' => $this->secretProfile,
                'remote-address' => $this->secretRemoteAddress,
            ]);
            $this->reset(['secretName', 'secretPassword', 'secretProfile', 'secretRemoteAddress']);
            $this->addSecretOpen = false;
            $this->successMessage = 'PPPoE secret added successfully.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function toggleSecret(string $id, bool $currentlyDisabled): void
    {
        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->toggleSecret($router, $id, !$currentlyDisabled);
            $this->successMessage = $currentlyDisabled ? 'Secret enabled.' : 'Secret disabled.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function deleteSecret(string $id): void
    {
        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->deleteSecret($router, $id);
            $this->successMessage = 'PPPoE secret deleted.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function currentRouter(): ?MikrotikRouter
    {
        return $this->routerId ? MikrotikRouter::query()->find($this->routerId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.pppoe-manager-component', [
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'PPPoE & Live Traffic Manager']);
    }
}
