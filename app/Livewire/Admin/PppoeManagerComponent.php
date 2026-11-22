<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\UserRole;
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

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless(
            $user !== null && $user->is_active && $user->hasRole(UserRole::SuperAdmin, UserRole::Noc),
            403
        );
    }

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
        if (!in_array($tab, ['active', 'secrets', 'profiles'], true)) {
            return;
        }

        $this->tab = $tab;
        if ($tab !== 'secrets') {
            $this->secrets = [];
        }
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
                $this->secrets = [];
                $this->activeSessions = $service->listActiveSessions($router);
            } elseif ($this->tab === 'secrets') {
                $this->secrets = array_map(
                    static fn (array $sec): array => [
                        '.id' => (string) ($sec['.id'] ?? ''),
                        'name' => (string) ($sec['name'] ?? ''),
                        'profile' => (string) ($sec['profile'] ?? ''),
                        'remote-address' => (string) ($sec['remote-address'] ?? ''),
                        'disabled' => (string) ($sec['disabled'] ?? 'false'),
                    ],
                    $service->listSecrets($router)
                );
                $this->profiles = $service->listProfiles($router);
            } else {
                $this->secrets = [];
                $this->profiles = $service->listProfiles($router);
            }
        } catch (Throwable $e) {
            $this->errorMessage = 'Failed to fetch PPPoE data from router: ' . $e->getMessage();
        }
    }

    public function startMonitor(string $username): void
    {
        $cleanUsername = trim($username);
        if ($cleanUsername === '' || strlen($cleanUsername) > 80 || preg_match('/[<>\\r\\n]/', $cleanUsername) === 1) {
            return;
        }

        $this->monitoredInterface = "<pppoe-{$cleanUsername}>";
        $this->refreshTraffic();
    }

    public function stopMonitor(): void
    {
        $this->monitoredInterface = null;
        $this->traffic = null;
    }

    public function refreshTraffic(): void
    {
        if (
            empty($this->monitoredInterface)
            || preg_match('/^<pppoe-[^<>\\r\\n]{1,80}>$/', $this->monitoredInterface) !== 1
            || ($router = $this->currentRouter()) === null
        ) {
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
            'profileLocalAddr' => ['nullable', 'ip'],
            'profileRemotePool' => ['nullable', 'string', 'max:80'],
            'profileRateLimit' => ['nullable', 'string', 'max:60'],
        ]);

        $router = $this->currentRouter();
        if ($router === null) {
            return;
        }

        try {
            app(PppoeManagementService::class)->createProfile($router, [
                'name' => trim($this->profileName),
                'local-address' => trim($this->profileLocalAddr),
                'remote-address' => trim($this->profileRemotePool),
                'rate-limit' => trim($this->profileRateLimit),
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
            'secretPassword' => ['required', 'string', 'max:128'],
            'secretProfile' => ['required', 'string', 'max:80'],
            'secretRemoteAddress' => ['nullable', 'ip'],
        ]);

        $router = $this->currentRouter();
        if ($router === null) {
            $this->secretPassword = '';

            return;
        }

        try {
            app(PppoeManagementService::class)->createSecret($router, [
                'name' => trim($this->secretName),
                'password' => $this->secretPassword,
                'profile' => trim($this->secretProfile),
                'remote-address' => trim($this->secretRemoteAddress),
            ]);
            $this->reset(['secretName', 'secretPassword', 'secretProfile', 'secretRemoteAddress']);
            $this->addSecretOpen = false;
            $this->successMessage = 'PPPoE secret added successfully.';
            $this->loadData();
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        } finally {
            $this->secretPassword = '';
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
        return $this->routerId
            ? MikrotikRouter::query()->where('is_active', true)->find($this->routerId)
            : null;
    }

    public function render(): View
    {
        return view('livewire.admin.pppoe-manager-component', [
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'PPPoE & Live Traffic Manager']);
    }
}
