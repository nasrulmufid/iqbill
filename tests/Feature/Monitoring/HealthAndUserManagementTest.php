<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\QueueWorkerHeartbeatJob;
use App\Livewire\Admin\SystemMonitoringComponent;
use App\Models\User;
use App\Services\Monitoring\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('Public /api/health returns 200 OK and /api/admin/metrics requires staff authentication', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonStructure(['status', 'app', 'timestamp', 'checks' => ['database', 'cache']]);

    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $this->actingAs($customer)->getJson('/api/admin/metrics')->assertForbidden();

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $this->actingAs($admin)->getJson('/api/admin/metrics')
        ->assertOk()
        ->assertJsonStructure([
            'timestamp',
            'host',
            'services' => ['database', 'cache', 'queue', 'scheduler'],
            'automation' => ['scheduler', 'queue_worker'],
            'stats',
        ]);

    $this->actingAs($admin)->get(route('admin.monitoring.index'))
        ->assertOk()
        ->assertSee('System Monitoring & Health')
        ->assertSee('Background Automation');
});

test('Scheduler heartbeat detection identifies missing, active, and stale states', function () {
    Cache::forget(SystemHealthService::CACHE_SCHEDULER_HEARTBEAT);
    $service = app(SystemHealthService::class);

    $missing = $service->getSchedulerStatus();
    expect($missing['ok'])->toBeFalse()
        ->and($missing['state'])->toBe('pending')
        ->and($missing['status_label'])->toBe('Not Detected');

    SystemHealthService::recordSchedulerHeartbeat(now()->timestamp - 45);
    $active = $service->getSchedulerStatus();
    expect($active['ok'])->toBeTrue()
        ->and($active['state'])->toBe('ok')
        ->and($active['status_label'])->toBe('Active');

    SystemHealthService::recordSchedulerHeartbeat(now()->timestamp - (SystemHealthService::SCHEDULER_STALE_SECONDS + 60));
    $stale = $service->getSchedulerStatus();
    expect($stale['ok'])->toBeFalse()
        ->and($stale['state'])->toBe('failed')
        ->and($stale['status_label'])->toBe('Stopped / Stale');
});

test('Queue worker detection spots stuck jobs, timed-out ping, failed jobs, and healthy ping completion', function () {
    config()->set('queue.default', 'database');
    Cache::forget(SystemHealthService::CACHE_WORKER_LAST_PING_AT);
    Cache::forget(SystemHealthService::CACHE_WORKER_PENDING_PING);
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    $service = app(SystemHealthService::class);

    // 1. Untested with empty queue -> pending state (ok remains true to avoid false alarms while idle)
    $untested = $service->getQueueWorkerStatus();
    expect($untested['ok'])->toBeTrue()
        ->and($untested['state'])->toBe('pending')
        ->and($untested['status_label'])->toBe('Not Tested');

    // 2. Ready job stuck > 90s -> worker detected as stalled/down
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp - 120,
        'created_at' => now()->timestamp - 120,
    ]);

    $stuck = $service->getQueueWorkerStatus();
    expect($stuck['ok'])->toBeFalse()
        ->and($stuck['state'])->toBe('failed')
        ->and($stuck['status_label'])->toBe('Worker Stalled / Down')
        ->and($stuck['ready_jobs'])->toBe(1)
        ->and($stuck['oldest_wait_seconds'])->toBeGreaterThanOrEqual(120);

    DB::table('jobs')->delete();

    // 3. Manual ping sent > 30s ago with no worker response
    SystemHealthService::recordWorkerPingDispatched('ping-timeout-token', 'manual', now()->timestamp - 45);
    $timeout = $service->getQueueWorkerStatus();
    expect($timeout['ok'])->toBeFalse()
        ->and($timeout['state'])->toBe('failed')
        ->and($timeout['status_label'])->toBe('Worker Unresponsive');

    // 4. Worker executes heartbeat job -> pending ping clears and worker status becomes active
    (new QueueWorkerHeartbeatJob('ping-timeout-token', 'manual'))->handle();
    $healthy = $service->getQueueWorkerStatus();
    expect($healthy['ok'])->toBeTrue()
        ->and($healthy['state'])->toBe('ok')
        ->and($healthy['status_label'])->toBe('Active')
        ->and($healthy['ping_pending'])->toBeFalse();

    // 5. Ada failed job tercatat
    DB::table('failed_jobs')->insert([
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: simulated failure',
        'failed_at' => now(),
    ]);

    $withFailed = $service->getQueueWorkerStatus();
    expect($withFailed['ok'])->toBeFalse()
        ->and($withFailed['state'])->toBe('failed')
        ->and($withFailed['failed_jobs'])->toBe(1);
});

test('SystemMonitoringComponent can ping queue worker and Dashboard displays automation alert when unhealthy', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    Cache::forget(SystemHealthService::CACHE_SCHEDULER_HEARTBEAT);

    Livewire::actingAs($admin)
        ->test(SystemMonitoringComponent::class)
        ->assertSee('Laravel Scheduler')
        ->assertSee('Queue Worker')
        ->call('pingQueueWorker')
        ->assertSet('automation.queue_worker.state', 'ok')
        ->assertSee('Queue worker ping executed immediately and worker status is confirmed active.');

    // Scheduler inactive -> alert shown on admin dashboard
    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('System Automation Attention Required');

    // After scheduler active & queue worker ok (sync) -> alert hidden
    SystemHealthService::recordSchedulerHeartbeat();
    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('System Automation Attention Required');
});

test('Super Admin can manage users while non-super_admin staff are forbidden', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);

    $this->actingAs($noc)->get(route('admin.users.index'))->assertForbidden();

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('is-sidebar-active')
        ->assertSee('lockSidebarActiveMenu');

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Teknisi Lapangan',
        'email' => 'teknisi@isp.local',
        'phone' => '62812999888',
        'role' => 'noc',
        'password' => 'secret1234',
    ])->assertRedirect();

    $created = User::query()->where('email', 'teknisi@isp.local')->first();
    expect($created)->not->toBeNull()->and($created->role)->toBe(UserRole::Noc);

    $this->actingAs($admin)->patch(route('admin.users.toggle', $created))->assertRedirect();
    expect($created->fresh()->is_active)->toBeFalse();

    // Tidak boleh menghapus akun sendiri
    $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertRedirect();
    expect(User::query()->where('id', $admin->id)->exists())->toBeTrue();
});
