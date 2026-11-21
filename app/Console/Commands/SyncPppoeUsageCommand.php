<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mikrotik\PppoeUsageSyncService;
use Illuminate\Console\Command;

class SyncPppoeUsageCommand extends Command
{
    protected $signature = 'billing:sync-pppoe-usage';

    protected $description = 'Periodically sync active PPPoE session bandwidth usage (Rx/Tx bytes) to the database.';

    public function handle(PppoeUsageSyncService $syncService): int
    {
        $result = $syncService->syncAll();

        if ($result['skipped']) {
            $this->info('Usage tracking is disabled in settings.');

            return self::SUCCESS;
        }

        $this->info("Synced {$result['synced']} PPPoE session usage record(s).");

        return self::SUCCESS;
    }
}
