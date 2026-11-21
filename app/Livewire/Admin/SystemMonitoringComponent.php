<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Monitoring\SystemHealthService;
use Illuminate\View\View;
use Livewire\Component;

class SystemMonitoringComponent extends Component
{
    /** @var array<string, mixed> */
    public array $host = [];

    /** @var array{scheduler: array<string, mixed>, queue_worker: array<string, mixed>} */
    public array $automation = [
        'scheduler' => [],
        'queue_worker' => [],
    ];

    /** @var array<string, array<string, mixed>> */
    public array $services = [];

    /** @var array<string, mixed> */
    public array $stats = [];

    public string $lastCheckedAt = '';

    public string $pingNotice = '';

    public function mount(): void
    {
        $this->refreshMetrics();
    }

    public function refreshMetrics(): void
    {
        $service = app(SystemHealthService::class);
        $this->host = $service->collectHostMetrics();
        $this->automation = $service->getAutomationStatus();
        $this->services = $service->checkConnectivity(includeExternal: true, automation: $this->automation);
        $this->stats = $service->collectPanelStats();
        $this->lastCheckedAt = now('Asia/Jakarta')->format('H:i:s') . ' WIB';
    }

    public function pingQueueWorker(): void
    {
        $service = app(SystemHealthService::class);
        $service->dispatchWorkerPing('manual');

        $this->refreshMetrics();

        $worker = $this->automation['queue_worker'] ?? [];
        $this->pingNotice = (($worker['driver'] ?? 'database') === 'sync' || !($worker['ping_pending'] ?? false))
            ? 'Queue worker ping executed immediately and worker status is confirmed active.'
            : 'Test ping dispatched to queue. Status will automatically update once processed by a worker.';
    }

    public function render(): View
    {
        return view('livewire.admin.system-monitoring-component')
            ->layout('layouts.app', ['title' => 'System Health & Monitoring']);
    }
}
