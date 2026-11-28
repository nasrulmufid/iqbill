<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\Backup\SystemBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function setupAutoBackupFixture(): array
{
    Storage::fake('local');

    $adminPassword = 'SuperAdmin#AutoBackup2026!';
    $admin = User::factory()->create([
        'name' => 'Auto Backup Admin',
        'email' => 'autobackup-admin@iqbill.test',
        'password' => Hash::make($adminPassword),
        'role' => UserRole::SuperAdmin,
        'is_active' => true,
        'must_change_password' => false,
    ]);

    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true, 'must_change_password' => false]);
    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true, 'must_change_password' => false]);
    $portal = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true, 'must_change_password' => false]);

    $settings = CompanySetting::current();
    $settings->update([
        'company_name' => 'IQ Bill AutoBackup ISP',
        'telegram_enabled' => true,
        'telegram_bot_token_encrypted' => '987654321:AABBCCddeeffGGhhIIjjKKllMMnnOOppQQ',
        'telegram_chat_id' => '-100111222333',
        'sumopod_api_key_encrypted' => 'sk_live_never_leak_to_telegram',
    ]);

    return compact('admin', 'adminPassword', 'noc', 'finance', 'portal', 'settings');
}

test('Super Admin can configure auto-backup schedule, encrypted passphrase, and dedicated Telegram chat ID with re-authentication', function () {
    $ctx = setupAutoBackupFixture();
    $passphrase = 'AutoBackup#Passphrase2026!';

    // 1. Wrong Super Admin password rejected & secrets NOT flashed to session
    $this->actingAs($ctx['admin'])
        ->from(route('admin.system-backups.index'))
        ->put(route('admin.system-backups.schedule.update'), [
            'form_context' => 'auto_backup_schedule',
            'auto_backup_enabled' => '1',
            'auto_backup_scope' => 'full',
            'auto_backup_schedule' => 'daily',
            'auto_backup_time' => '03:30',
            'auto_backup_day_of_week' => 1,
            'auto_backup_day_of_month' => 15,
            'auto_backup_retention_count' => 14,
            'auto_backup_passphrase' => $passphrase,
            'auto_backup_passphrase_confirmation' => $passphrase,
            'auto_backup_send_telegram' => '1',
            'auto_backup_telegram_chat_id' => '555666777',
            'current_password' => 'WrongPassword!',
        ])
        ->assertRedirect(route('admin.system-backups.index'))
        ->assertSessionHasErrors('current_password');

    expect(session('_old_input'))->not->toHaveKey('auto_backup_passphrase')
        ->and(session('_old_input'))->not->toHaveKey('auto_backup_passphrase_confirmation')
        ->and(session('_old_input'))->not->toHaveKey('current_password');

    // 2. Enabling auto-backup without passphrase rejected
    $this->actingAs($ctx['admin'])
        ->put(route('admin.system-backups.schedule.update'), [
            'auto_backup_enabled' => '1',
            'auto_backup_scope' => 'full',
            'auto_backup_schedule' => 'daily',
            'auto_backup_time' => '03:30',
            'auto_backup_day_of_week' => 1,
            'auto_backup_day_of_month' => 15,
            'auto_backup_retention_count' => 14,
            'auto_backup_send_telegram' => '1',
            'current_password' => $ctx['adminPassword'],
        ])
        ->assertSessionHasErrors('auto_backup_passphrase');

    // 3. Valid configuration saves encrypted passphrase and records sanitized AuditLog
    $this->actingAs($ctx['admin'])
        ->put(route('admin.system-backups.schedule.update'), [
            'auto_backup_enabled' => '1',
            'auto_backup_scope' => 'full',
            'auto_backup_schedule' => 'weekly',
            'auto_backup_time' => '03:30',
            'auto_backup_day_of_week' => 3,
            'auto_backup_day_of_month' => 15,
            'auto_backup_retention_count' => 10,
            'auto_backup_passphrase' => $passphrase,
            'auto_backup_passphrase_confirmation' => $passphrase,
            'auto_backup_send_telegram' => '1',
            'auto_backup_telegram_chat_id' => '555666777',
            'current_password' => $ctx['adminPassword'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $fresh = CompanySetting::current()->fresh();
    $rawDbRow = DB::table('company_settings')->where('id', $fresh->id)->first();

    expect($fresh->auto_backup_enabled)->toBeTrue()
        ->and($fresh->auto_backup_scope)->toBe('full')
        ->and($fresh->auto_backup_schedule)->toBe('weekly')
        ->and($fresh->auto_backup_time)->toBe('03:30')
        ->and($fresh->auto_backup_day_of_week)->toBe(3)
        ->and($fresh->auto_backup_retention_count)->toBe(10)
        ->and($fresh->auto_backup_send_telegram)->toBeTrue()
        ->and($fresh->auto_backup_telegram_chat_id)->toBe('555666777')
        ->and($fresh->auto_backup_passphrase_encrypted)->toBe($passphrase)
        // Stored encrypted at rest, hidden from JSON serialization, never logged
        ->and($rawDbRow->auto_backup_passphrase_encrypted)->not->toBe($passphrase)
        ->and($fresh->toArray())->not->toHaveKey('auto_backup_passphrase_encrypted');

    $audit = AuditLog::query()
        ->where('action', SystemBackupService::AUDIT_ACTION_SCHEDULE_UPDATED)
        ->latest('id')
        ->firstOrFail();

    expect($audit->new_values['passphrase_changed'])->toBeTrue()
        ->and(json_encode($audit->new_values))->not->toContain($passphrase);

    // UI never exposes the stored passphrase
    $this->actingAs($ctx['admin'])
        ->get(route('admin.system-backups.index'))
        ->assertOk()
        ->assertSee('Configured (Encrypted at Rest)')
        ->assertDontSee($passphrase);
});

test('Auto-backup schedule evaluator matches daily, weekly, monthly, 6h, and 12h slots idempotently in WIB', function () {
    $ctx = setupAutoBackupFixture();
    $service = app(SystemBackupService::class);

    $settings = $ctx['settings'];
    $settings->update([
        'auto_backup_enabled' => true,
        'auto_backup_passphrase_encrypted' => 'ScheduleEval#2026!',
        'auto_backup_schedule' => 'daily',
        'auto_backup_time' => '02:15',
        'auto_backup_last_run_at' => null,
    ]);

    $dueDaily = CarbonImmutable::create(2026, 11, 28, 2, 15, 0, 'Asia/Jakarta');
    $notDueDaily = CarbonImmutable::create(2026, 11, 28, 2, 16, 0, 'Asia/Jakarta');

    expect($service->shouldRunScheduledBackup($settings->fresh(), $dueDaily))->toBeTrue()
        ->and($service->shouldRunScheduledBackup($settings->fresh(), $notDueDaily))->toBeFalse();

    // Already ran today -> do not run again on same day
    $settings->update(['auto_backup_last_run_at' => $dueDaily]);
    expect($service->shouldRunScheduledBackup($settings->fresh(), $dueDaily))->toBeFalse();

    // Weekly on Sunday (dayOfWeek = 0) at 04:00 WIB
    $settings->update([
        'auto_backup_schedule' => 'weekly',
        'auto_backup_day_of_week' => 0,
        'auto_backup_time' => '04:00',
        'auto_backup_last_run_at' => null,
    ]);
    $sunday = CarbonImmutable::create(2026, 11, 29, 4, 0, 0, 'Asia/Jakarta'); // Sunday
    $monday = CarbonImmutable::create(2026, 11, 30, 4, 0, 0, 'Asia/Jakarta'); // Monday
    expect($service->shouldRunScheduledBackup($settings->fresh(), $sunday))->toBeTrue()
        ->and($service->shouldRunScheduledBackup($settings->fresh(), $monday))->toBeFalse();

    // Monthly on day 1 at 01:00 WIB
    $settings->update([
        'auto_backup_schedule' => 'monthly',
        'auto_backup_day_of_month' => 1,
        'auto_backup_time' => '01:00',
        'auto_backup_last_run_at' => null,
    ]);
    $firstOfMonth = CarbonImmutable::create(2026, 12, 1, 1, 0, 0, 'Asia/Jakarta');
    $secondOfMonth = CarbonImmutable::create(2026, 12, 2, 1, 0, 0, 'Asia/Jakarta');
    expect($service->shouldRunScheduledBackup($settings->fresh(), $firstOfMonth))->toBeTrue()
        ->and($service->shouldRunScheduledBackup($settings->fresh(), $secondOfMonth))->toBeFalse();

    // Every 6 hours (00:00, 06:00, 12:00, 18:00)
    $settings->update([
        'auto_backup_schedule' => 'every_6_hours',
        'auto_backup_last_run_at' => null,
    ]);
    expect($service->shouldRunScheduledBackup($settings->fresh(), CarbonImmutable::create(2026, 11, 28, 6, 0, 0, 'Asia/Jakarta')))->toBeTrue()
        ->and($service->shouldRunScheduledBackup($settings->fresh(), CarbonImmutable::create(2026, 11, 28, 7, 0, 0, 'Asia/Jakarta')))->toBeFalse();
});

test('Auto-backup command encrypts database + config and sends encrypted .iqb archive to dedicated Telegram Chat ID without leaking passphrase', function () {
    $ctx = setupAutoBackupFixture();
    $passphrase = 'TelegramEncrypted#Key2026!';

    $ctx['settings']->update([
        'auto_backup_enabled' => true,
        'auto_backup_scope' => 'full',
        'auto_backup_schedule' => 'daily',
        'auto_backup_time' => '02:00',
        'auto_backup_retention_count' => 5,
        'auto_backup_passphrase_encrypted' => $passphrase,
        'auto_backup_send_telegram' => true,
        'auto_backup_telegram_chat_id' => '998877665',
    ]);

    Http::fake([
        'https://api.telegram.org/bot*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
    ]);

    $this->artisan('system:auto-backup', ['--force' => true])
        ->assertExitCode(0);

    $backup = SystemBackup::query()->latest('id')->firstOrFail();
    expect($backup->trigger_source)->toBe('scheduled')
        ->and($backup->scope)->toBe('full')
        ->and($backup->telegram_sent_at)->not->toBeNull();

    $settings = CompanySetting::current()->fresh();
    expect($settings->auto_backup_last_status)->toBe('success')
        ->and($settings->auto_backup_last_error)->toBeNull();

    // Verify Telegram sendDocument request went to dedicated Chat ID, contains encrypted archive, and NEVER leaks passphrase or raw secrets
    Http::assertSent(function (HttpClientRequest $request) use ($passphrase, $backup): bool {
        $rawBody = $request->body();

        return str_contains($request->url(), '/sendDocument')
            && str_contains($rawBody, '998877665')
            && str_contains($rawBody, $backup->filename)
            && str_contains($rawBody, 'AES-256-GCM')
            && ! str_contains($rawBody, $passphrase)
            && ! str_contains($rawBody, 'sk_live_never_leak_to_telegram');
    });

    // Verify the generated archive can be decrypted with the configured auto-backup passphrase
    $rawArchive = (string) Storage::disk('local')->get($backup->file_path);
    $decrypted = app(SystemBackupService::class)->decryptArchivePayload($rawArchive, $passphrase);
    expect($decrypted['manifest']['scope'])->toBe('full')
        ->and($decrypted['data']['tables'])->toHaveKeys(['company_settings', 'users']);

    expect(AuditLog::query()->where('action', SystemBackupService::AUDIT_ACTION_TELEGRAM_SENT)->exists())->toBeTrue();
});

test('Auto-backup keeps local archive intact when Telegram fails and redacts bot token from error records', function () {
    $ctx = setupAutoBackupFixture();
    $passphrase = 'ResilientBackup#Key2026!';

    $ctx['settings']->update([
        'auto_backup_enabled' => true,
        'auto_backup_scope' => 'config_only',
        'auto_backup_passphrase_encrypted' => $passphrase,
        'auto_backup_send_telegram' => true,
        'auto_backup_telegram_chat_id' => '998877665',
    ]);

    Http::fake([
        'https://api.telegram.org/bot*/sendDocument' => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502),
    ]);

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.run-now'))
        ->assertRedirect()
        ->assertSessionHas('error');

    $backup = SystemBackup::query()->latest('id')->firstOrFail();
    $settings = CompanySetting::current()->fresh();

    expect(Storage::disk('local')->exists($backup->file_path))->toBeTrue()
        ->and($backup->telegram_sent_at)->toBeNull()
        ->and($settings->auto_backup_last_status)->toBe('partial_telegram_failed')
        ->and($settings->auto_backup_last_error)->not->toContain('987654321:AABBCCddeeffGGhhIIjjKKllMMnnOOppQQ');

    expect(AuditLog::query()->where('action', SystemBackupService::AUDIT_ACTION_TELEGRAM_FAILED)->exists())->toBeTrue();
});

test('Scheduled backup retention prunes only old auto-backups and preserves manual backups', function () {
    $ctx = setupAutoBackupFixture();
    $service = app(SystemBackupService::class);
    $passphrase = 'RetentionPolicy#Key2026!';

    // Create 1 manual backup first
    $manualBackup = $service->createBackup('config_only', $passphrase, $ctx['admin'], '127.0.0.1', 'Manual gold snapshot', 'manual');

    // Configure retention = 2, Telegram disabled
    $ctx['settings']->update([
        'auto_backup_enabled' => true,
        'auto_backup_scope' => 'config_only',
        'auto_backup_retention_count' => 2,
        'auto_backup_passphrase_encrypted' => $passphrase,
        'auto_backup_send_telegram' => false,
    ]);

    // Run 3 auto backups
    $r1 = $service->runAutoBackup($ctx['admin'], '127.0.0.1', force: true);
    $r2 = $service->runAutoBackup($ctx['admin'], '127.0.0.1', force: true);
    $r3 = $service->runAutoBackup($ctx['admin'], '127.0.0.1', force: true);

    expect($r3['pruned_count'])->toBe(1)
        // Oldest auto backup pruned from DB & disk
        ->and(SystemBackup::query()->whereKey($r1['backup']->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($r1['backup']->file_path))->toBeFalse()
        // Latest 2 auto backups kept
        ->and(SystemBackup::query()->whereKey($r2['backup']->id)->exists())->toBeTrue()
        ->and(SystemBackup::query()->whereKey($r3['backup']->id)->exists())->toBeTrue()
        // Manual backup is untouched
        ->and(SystemBackup::query()->whereKey($manualBackup->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($manualBackup->file_path))->toBeTrue();
});

test('Super Admin can manually send an existing stored backup to Telegram while non-SuperAdmin staff are forbidden', function () {
    $ctx = setupAutoBackupFixture();
    $service = app(SystemBackupService::class);
    $backup = $service->createBackup('config_only', 'ManualSendKey#2026!', $ctx['admin']);

    Http::fake([
        'https://api.telegram.org/bot*/sendDocument' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.send-telegram', $backup))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($backup->fresh()->telegram_sent_at)->not->toBeNull();

    foreach ([$ctx['noc'], $ctx['finance'], $ctx['portal']] as $unauthorized) {
        $this->flushSession();

        $this->actingAs($unauthorized)
            ->put(route('admin.system-backups.schedule.update'), [])
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->post(route('admin.system-backups.run-now'))
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->post(route('admin.system-backups.send-telegram', $backup))
            ->assertForbidden();
    }
});
