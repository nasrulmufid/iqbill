<?php

declare(strict_types=1);

use App\Jobs\QueueWorkerHeartbeatJob;
use App\Services\Monitoring\SystemHealthService;
use Illuminate\Support\Facades\Schedule;

// Run automated isolation at 23:59 WIB on the subscriber's final active day
Schedule::command('billing:isolate-overdue')
    ->dailyAt('23:59')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('billing:generate-invoices')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('billing:send-reminders')
    ->dailyAt('08:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('billing:sync-pppoe-usage')
    ->everyFiveMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('mikrotik:reconcile-pending')
    ->everyTwoMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::call(static function (): void {
    SystemHealthService::recordSchedulerHeartbeat();
})
    ->name('system:scheduler-heartbeat')
    ->everyMinute()
    ->timezone('Asia/Jakarta');

Schedule::call(static function (): void {
    QueueWorkerHeartbeatJob::dispatchAutoPingIfIdle();
})
    ->name('system:queue-heartbeat')
    ->everyFiveMinutes()
    ->timezone('Asia/Jakarta');
