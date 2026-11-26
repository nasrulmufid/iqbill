<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MikrotikRouter;
use App\Models\NotificationTemplate;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\Backup\SystemBackupService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function setupBackupFixture(): array
{
    Storage::fake('local');

    $adminPassword = 'SuperAdmin#Strong2026!';
    $admin = User::factory()->create([
        'name' => 'Primary Super Admin',
        'email' => 'root-backup@iqbill.test',
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
        'company_name' => 'IQ Bill Fiber Production',
        'company_phone' => '6281122334455',
        'sumopod_environment' => 'live',
        'sumopod_api_key_encrypted' => 'sk_live_sumopod_secret_999',
        'sumopod_signing_secret_encrypted' => 'whsec_sumopod_signing_888',
        'wa_basic_auth_password_encrypted' => 'gowa_secret_pass_777',
        'telegram_bot_token_encrypted' => '123456:telegram_secret_token',
        'google_maps_api_key_encrypted' => 'AIzaSySecretMapsKey123',
    ]);

    NotificationTemplate::query()->updateOrCreate(
        ['key' => 'invoice_created'],
        [
            'label' => 'Invoice Created',
            'body_template' => 'Halo {customer_name}, tagihan {invoice_number} sudah terbit.',
            'is_active' => true,
        ]
    );

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-CORE-BACKUP',
        'ros_version_type' => 'v7',
        'host' => '10.50.0.1',
        'username' => 'apiadmin',
        'password_encrypted' => 'mikrotik_super_pass_456',
    ]);

    $package = Package::query()->create([
        'code' => 'FIBER-50M',
        'name' => 'Fiber 50 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 25,
        'price' => 275000,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $portal->id,
        'customer_code' => 'IQ-BKP-001',
        'full_name' => 'Siti Rahmawati',
        'phone_whatsapp' => '6281299887766',
        'installation_address' => 'Jl. Diponegoro No. 12',
    ]);

    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'siti.fiber',
        'service_password_encrypted' => 'pppoe_pass_123',
        'billing_day' => 5,
        'auto_isolate' => true,
        'next_billing_date' => '2026-12-05',
        'status' => SubscriptionStatus::Active,
        'activated_at' => now()->subMonth(),
    ]);

    return compact('admin', 'adminPassword', 'noc', 'finance', 'portal', 'settings', 'router', 'package', 'customer', 'subscription');
}

test('Only Super Admin can access System Backup & Restore endpoints and sidebar link', function () {
    $ctx = setupBackupFixture();

    $this->actingAs($ctx['admin'])
        ->get(route('admin.system-backups.index'))
        ->assertOk()
        ->assertSee('System Backup &amp; Restore', false)
        ->assertSee('AES-256-GCM');

    // Guest redirected to login
    $this->post('/logout');
    $this->get(route('admin.system-backups.index'))->assertRedirect(route('login'));

    // NOC, Finance, and Customer forbidden on all routes
    foreach ([$ctx['noc'], $ctx['finance'], $ctx['portal']] as $unauthorized) {
        $this->actingAs($unauthorized)
            ->get(route('admin.system-backups.index'))
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->post(route('admin.system-backups.store'), [
                'scope' => 'full',
                'passphrase' => 'StrongPassphrase#123',
                'passphrase_confirmation' => 'StrongPassphrase#123',
            ])
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->post(route('admin.system-backups.restore-upload'), [])
            ->assertForbidden();
    }

    // Sidebar hides Backup & Restore for NOC
    $this->actingAs($ctx['noc'])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.system-backups.index'));
});

test('Super Admin can create AES-256-GCM full backup, verify encryption at rest, and restore modified database cleanly', function () {
    $ctx = setupBackupFixture();
    $passphrase = 'BackupSecret#Key2026!';

    // 1. Create encrypted full backup
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.store'), [
            'form_context' => 'create_backup',
            'scope' => 'full',
            'passphrase' => $passphrase,
            'passphrase_confirmation' => $passphrase,
            'notes' => 'Pre-maintenance snapshot',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $backup = SystemBackup::query()->firstOrFail();
    expect($backup->scope)->toBe('full')
        ->and($backup->encryption_cipher)->toBe('aes-256-gcm')
        ->and($backup->hasValidStoragePath())->toBeTrue()
        ->and(Storage::disk('local')->exists($backup->file_path))->toBeTrue();

    // 2. Verify raw file on disk is strictly encrypted and leaks no plaintext secrets
    $rawFile = (string) Storage::disk('local')->get($backup->file_path);
    expect($rawFile)->not->toContain('sk_live_sumopod_secret_999')
        ->and($rawFile)->not->toContain('mikrotik_super_pass_456')
        ->and($rawFile)->not->toContain('pppoe_pass_123')
        ->and($rawFile)->not->toContain('Siti Rahmawati')
        ->and($rawFile)->not->toContain($passphrase)
        ->and(hash('sha256', $rawFile))->toBe($backup->checksum_sha256);

    $envelope = json_decode($rawFile, true, 16, JSON_THROW_ON_ERROR);
    expect($envelope['format'])->toBe(SystemBackupService::ARCHIVE_FORMAT)
        ->and($envelope['cipher'])->toBe('aes-256-gcm')
        ->and($envelope['kdf'])->toBe('pbkdf2-sha256')
        ->and($envelope['iterations'])->toBe(SystemBackupService::PBKDF2_ITERATIONS);

    // 3. Mutate database dramatically after backup
    CompanySetting::current()->update([
        'company_name' => 'Corrupted Temp Name',
        'sumopod_api_key_encrypted' => 'sk_wrong_key',
    ]);
    $ctx['subscription']->delete();
    $ctx['customer']->delete();

    expect(Customer::query()->count())->toBe(0)
        ->and(CompanySetting::current()->company_name)->toBe('Corrupted Temp Name');

    // 4. Restore from stored backup with re-auth password + RESTORE confirmation
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'form_context' => 'restore_stored',
            'restore_backup_id' => $backup->id,
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect(route('admin.system-backups.index'))
        ->assertSessionHas('success');

    // 5. Verify all data and encrypted credentials were restored accurately
    $restoredSettings = CompanySetting::current()->fresh();
    expect($restoredSettings->company_name)->toBe('IQ Bill Fiber Production')
        ->and($restoredSettings->sumopod_api_key_encrypted)->toBe('sk_live_sumopod_secret_999')
        ->and($restoredSettings->telegram_bot_token_encrypted)->toBe('123456:telegram_secret_token')
        ->and(Customer::query()->where('customer_code', 'IQ-BKP-001')->first()?->full_name)->toBe('Siti Rahmawati')
        ->and(Subscription::query()->where('service_username', 'siti.fiber')->first()?->service_password_encrypted)->toBe('pppoe_pass_123')
        ->and(MikrotikRouter::query()->where('name', 'NAS-CORE-BACKUP')->first()?->password_encrypted)->toBe('mikrotik_super_pass_456');

    expect(AuditLog::query()->where('action', SystemBackupService::AUDIT_ACTION_RESTORED)->exists())->toBeTrue();
});

test('Downloaded backup archive can be uploaded and restored on a server with a different APP_KEY', function () {
    $ctx = setupBackupFixture();
    $passphrase = 'CrossServerKey#2026!';

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.store'), [
            'scope' => 'full',
            'passphrase' => $passphrase,
            'passphrase_confirmation' => $passphrase,
        ])
        ->assertRedirect();

    $backup = SystemBackup::query()->firstOrFail();

    // Download archive and check strict security headers
    $downloadResponse = $this->actingAs($ctx['admin'])
        ->get(route('admin.system-backups.download', $backup))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'application/octet-stream');

    expect((string) $downloadResponse->headers->get('Cache-Control'))->toContain('no-store');
    expect(AuditLog::query()->where('action', SystemBackupService::AUDIT_ACTION_DOWNLOADED)->exists())->toBeTrue();

    $downloadedBytes = $downloadResponse->getContent();

    // Simulate a brand new target server with a completely different APP_KEY
    $newRawKey = random_bytes(32);
    $newAppKey = 'base64:'.base64_encode($newRawKey);
    config(['app.key' => $newAppKey]);
    $newEncrypter = new Encrypter($newRawKey, config('app.cipher', 'AES-256-CBC'));
    app()->instance('encrypter', $newEncrypter);
    Crypt::swap($newEncrypter);

    // Wipe business data before upload restore
    $ctx['subscription']->delete();
    $ctx['customer']->delete();
    CompanySetting::current()->update(['company_name' => 'Fresh Target Server', 'sumopod_api_key_encrypted' => null]);

    $tmpFile = sys_get_temp_dir().'/iqbill_upload_'.uniqid().'.iqb';
    file_put_contents($tmpFile, $downloadedBytes);
    $upload = new UploadedFile($tmpFile, $backup->filename, 'application/octet-stream', null, true);

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore-upload'), [
            'form_context' => 'restore_upload',
            'backup_file' => $upload,
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect(route('admin.system-backups.index'))
        ->assertSessionHas('success');

    @unlink($tmpFile);

    // All secrets must decrypt cleanly under the NEW server APP_KEY!
    expect(CompanySetting::current()->fresh()->company_name)->toBe('IQ Bill Fiber Production')
        ->and(CompanySetting::current()->fresh()->sumopod_api_key_encrypted)->toBe('sk_live_sumopod_secret_999')
        ->and(CompanySetting::current()->fresh()->google_maps_api_key_encrypted)->toBe('AIzaSySecretMapsKey123')
        ->and(MikrotikRouter::query()->where('name', 'NAS-CORE-BACKUP')->firstOrFail()->password_encrypted)->toBe('mikrotik_super_pass_456')
        ->and(Subscription::query()->where('service_username', 'siti.fiber')->firstOrFail()->service_password_encrypted)->toBe('pppoe_pass_123');
});

test('Configuration-only backup restores settings and templates without overwriting newer customer data', function () {
    $ctx = setupBackupFixture();
    $passphrase = 'ConfigOnlyPass#2026';

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.store'), [
            'scope' => 'config_only',
            'passphrase' => $passphrase,
            'passphrase_confirmation' => $passphrase,
        ])
        ->assertRedirect();

    $backup = SystemBackup::query()->firstOrFail();
    expect($backup->scope)->toBe('config_only');

    // Add a new customer and modify settings after config backup
    $newCustomer = Customer::query()->create([
        'customer_code' => 'IQ-NEW-999',
        'full_name' => 'New Customer After Config Backup',
        'phone_whatsapp' => '6285511223344',
        'installation_address' => 'Jl. Baru No. 9',
    ]);
    CompanySetting::current()->update(['company_name' => 'Modified Config Name']);
    NotificationTemplate::query()->where('key', 'invoice_created')->update(['body_template' => 'Modified body']);

    // Attempting full restore from config_only archive must be rejected
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    // Restoring in config_only mode restores config while preserving newer customers
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'restore_mode' => 'config_only',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect(route('admin.system-backups.index'))
        ->assertSessionHas('success');

    expect(CompanySetting::current()->fresh()->company_name)->toBe('IQ Bill Fiber Production')
        ->and(NotificationTemplate::query()->where('key', 'invoice_created')->firstOrFail()->body_template)
        ->toBe('Halo {customer_name}, tagihan {invoice_number} sudah terbit.')
        ->and(Customer::query()->whereKey($newCustomer->id)->exists())->toBeTrue();
});

test('Restore blocks wrong passphrase, tampered archives, wrong Super Admin password, missing RESTORE phrase, and never flashes secrets', function () {
    $ctx = setupBackupFixture();
    $passphrase = 'ValidSecretPass#2026';

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.store'), [
            'scope' => 'full',
            'passphrase' => $passphrase,
            'passphrase_confirmation' => $passphrase,
        ])
        ->assertRedirect();

    $backup = SystemBackup::query()->firstOrFail();

    // Mutate company name so we can prove failed restores leave DB untouched
    CompanySetting::current()->update(['company_name' => 'Keep Me Intact']);

    // 1. Wrong Super Admin password -> validation error and secrets are NOT flashed in old input
    $this->actingAs($ctx['admin'])
        ->from(route('admin.system-backups.index'))
        ->post(route('admin.system-backups.restore', $backup), [
            'form_context' => 'restore_stored',
            'restore_backup_id' => $backup->id,
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => 'WrongAdminPassword!',
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect(route('admin.system-backups.index'))
        ->assertSessionHasErrors('current_password');

    expect(session('_old_input'))->not->toHaveKey('passphrase')
        ->and(session('_old_input'))->not->toHaveKey('current_password');

    // 2. Wrong confirm phrase
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'restore',
        ])
        ->assertSessionHasErrors('confirm_phrase');

    // 3. Wrong backup encryption passphrase -> rejected by AES-256-GCM auth tag
    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'restore_mode' => 'full',
            'passphrase' => 'WrongEncryptionPassphrase!',
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(CompanySetting::current()->fresh()->company_name)->toBe('Keep Me Intact');

    // 4. Tampered archive bytes on disk -> rejected by SHA-256 verification before decryption
    $originalRaw = (string) Storage::disk('local')->get($backup->file_path);
    Storage::disk('local')->put($backup->file_path, $originalRaw.' ');

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.restore', $backup), [
            'restore_mode' => 'full',
            'passphrase' => $passphrase,
            'current_password' => $ctx['adminPassword'],
            'confirm_phrase' => 'RESTORE',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(CompanySetting::current()->fresh()->company_name)->toBe('Keep Me Intact');
});

test('SystemBackupService rejects archives without an active Super Admin, excessive KDF iterations, and path traversal', function () {
    $ctx = setupBackupFixture();
    $service = app(SystemBackupService::class);
    $passphrase = 'ServiceSecurity#2026';

    // 1. Archive with zero active Super Admins must be rejected (anti-lockout)
    $dataBlock = [
        'app_config' => [],
        'tables' => [
            'users' => [
                ['id' => 1, 'name' => 'No Admin', 'email' => 'noc@iqbill.test', 'role' => 'noc', 'is_active' => 1],
            ],
        ],
    ];
    $payload = [
        'manifest' => ['format' => SystemBackupService::ARCHIVE_FORMAT, 'scope' => 'full'],
        'payload_sha256' => hash('sha256', json_encode($dataBlock, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        'data' => $dataBlock,
    ];
    $encryptedNoAdmin = $service->encryptArchivePayload($payload, $passphrase);

    expect(fn () => $service->restoreFromRawArchive($encryptedNoAdmin, $passphrase, 'full', $ctx['admin']))
        ->toThrow(RuntimeException::class, 'active Super Admin');

    // 2. Excessive PBKDF2 iterations (CPU DoS protection) must be rejected
    $envelope = json_decode($encryptedNoAdmin, true, 16, JSON_THROW_ON_ERROR);
    $envelope['iterations'] = 99_999_999;
    $dosArchive = json_encode($envelope, JSON_THROW_ON_ERROR);

    expect(fn () => $service->decryptArchivePayload($dosArchive, $passphrase))
        ->toThrow(RuntimeException::class, 'Unsupported or malformed');

    // 3. Path traversal on SystemBackup model must be blocked
    $maliciousBackup = new SystemBackup([
        'scope' => 'full',
        'filename' => 'evil.iqb',
        'file_path' => 'private/system-backups/../../.env',
        'checksum_sha256' => str_repeat('a', 64),
    ]);
    expect($maliciousBackup->hasValidStoragePath())->toBeFalse();
});

test('Super Admin can delete a stored backup and remove its file from private storage', function () {
    $ctx = setupBackupFixture();
    $passphrase = 'DeleteMeStrong#2026';

    $this->actingAs($ctx['admin'])
        ->post(route('admin.system-backups.store'), [
            'scope' => 'config_only',
            'passphrase' => $passphrase,
            'passphrase_confirmation' => $passphrase,
        ])
        ->assertRedirect();

    $backup = SystemBackup::query()->firstOrFail();
    $path = $backup->file_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs($ctx['admin'])
        ->delete(route('admin.system-backups.destroy', $backup))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SystemBackup::query()->whereKey($backup->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(AuditLog::query()->where('action', SystemBackupService::AUDIT_ACTION_DELETED)->exists())->toBeTrue();
});
