<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mikrotik\MikrotikReconciliationService;
use Illuminate\Console\Command;

class ReconcileMikrotikCommand extends Command
{
    protected $signature = 'mikrotik:reconcile-pending {--router= : Target router ID}';

    protected $description = 'Reconcile pending MikroTik provisioning/restore actions once routers come back online.';

    public function handle(MikrotikReconciliationService $reconciliation): int
    {
        $routerOpt = $this->option('router');
        $routerId = $routerOpt !== null && $routerOpt !== '' ? (int) $routerOpt : null;

        $summary = $reconciliation->reconcileAll($routerId, 'reconciler');

        $this->info(sprintf(
            'Checked %d router(s) (%d offline), synced %d subscription(s), failed %d.',
            $summary['routers_checked'],
            $summary['routers_offline'],
            $summary['synced'],
            $summary['failed']
        ));

        return self::SUCCESS;
    }
}
