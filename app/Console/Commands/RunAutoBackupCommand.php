<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\SystemBackupService;
use Illuminate\Console\Command;
use Throwable;

class RunAutoBackupCommand extends Command
{
    protected $signature = 'system:auto-backup {--force : Run auto backup immediately regardless of schedule}';

    protected $description = 'Create an encrypted system backup according to schedule and optionally deliver it to Telegram Bot.';

    public function handle(SystemBackupService $backupService): int
    {
        $force = (bool) $this->option('force');

        try {
            $result = $backupService->runAutoBackup(force: $force);
        } catch (Throwable $e) {
            $this->error('Auto backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['ran']) {
            if ($result['reason'] === 'locked') {
                $this->warn('Skipped: another auto-backup run is already in progress.');
            }

            return self::SUCCESS;
        }

        $backup = $result['backup'];
        $msg = "Auto backup created: {$backup->filename} ({$backup->tables_count} tables, {$backup->records_count} records).";

        if ($result['telegram_attempted']) {
            $msg .= $result['telegram_ok']
                ? ' Delivered to Telegram.'
                : ' Telegram delivery failed: '.$result['telegram_message'];
        }

        if ($result['pruned_count'] > 0) {
            $msg .= " Pruned {$result['pruned_count']} expired scheduled backup(s).";
        }

        $this->info($msg);

        return self::SUCCESS;
    }
}
