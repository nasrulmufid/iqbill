<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\PppoeManagerComponent;
use App\Livewire\Admin\SystemMonitoringComponent;
use App\Livewire\Admin\WhatsappBroadcastComponent;
use App\Livewire\Admin\WhatsappDeviceComponent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CustomerSpreadsheetService;
use App\Services\Mikrotik\MikrotikBackupService;
use App\Services\Mikrotik\RouterOsClientFactory;
use App\Services\Mikrotik\RouterOsClientInterface;
use App\Services\Notification\GowaClient;
use App\Services\Payment\SumopodWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('SumopodWebhookVerifier rejects auto-mode downgrade to static token when signing secret is configured and rejects empty whsec_ key', function () {
    $verifier = new SumopodWebhookVerifier();

    // Auto mode with signing secret configured MUST NOT accept static X-Webhook-Token fallback
    $downgradeReq = Request::create('/api/webhooks/sumopod', 'POST', [], [], [], [
        'HTTP_X_WEBHOOK_TOKEN' => 'whtok_legacy_token',
    ], '{"event_type":"payment.completed"}');

    $downgradeResult = $verifier->verify(
        $downgradeReq,
        'auto',
        'whsec_' . base64_encode('strong-32-byte-signing-secret-key'),
        'whtok_legacy_token'
    );

    expect($downgradeResult['valid'])->toBeFalse()
        ->and($downgradeResult['method'])->toBe('svix_hmac');

    // Empty whsec_ (zero-byte HMAC key) must be rejected
    $ts = (string) time();
    $body = '{"event_type":"payment.completed"}';
    $emptySig = base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$body}", '', true));
    $emptyKeyReq = Request::create('/api/webhooks/sumopod', 'POST', [], [], [], [
        'HTTP_SVIX_ID' => 'msg_1',
        'HTTP_SVIX_TIMESTAMP' => $ts,
        'HTTP_SVIX_SIGNATURE' => "v1,{$emptySig}",
    ], $body);

    $emptyKeyResult = $verifier->verify($emptyKeyReq, 'signature', 'whsec_', null);
    expect($emptyKeyResult['valid'])->toBeFalse();
});

test('GowaClient::resolveAssetUrl blocks third-party hosts, protocol-relative URLs, and @userinfo bypasses', function () {
    $settings = CompanySetting::current();
    $settings->wa_gateway_url = 'http://gowa:3000';
    $client = app(GowaClient::class);

    expect($client->resolveAssetUrl($settings, '/statics/qr.png'))->toBe('http://gowa:3000/statics/qr.png')
        ->and($client->resolveAssetUrl($settings, 'http://localhost:3000/statics/qr.png'))->toBe('http://gowa:3000/statics/qr.png')
        ->and($client->resolveAssetUrl($settings, 'http://gowa:3000/statics/qr.png'))->toBe('http://gowa:3000/statics/qr.png')
        ->and($client->resolveAssetUrl($settings, '@attacker.example/steal'))->toBe('http://gowa:3000/@attacker.example/steal')
        ->and($client->resolveAssetUrl($settings, 'http://169.254.169.254/latest/meta-data/'))->toBeNull()
        ->and($client->resolveAssetUrl($settings, '//attacker.example/qr.png'))->toBeNull()
        ->and($client->resolveAssetUrl($settings, 'https://attacker.example/qr.png'))->toBeNull()
        ->and($client->resolveAssetUrl($settings, 'http://user:pass@gowa:3000/qr.png'))->toBeNull();
});

test('MikrotikRouter validates host and username against SSRF and SSH argument injection', function () {
    expect(MikrotikRouter::isValidHost('10.10.10.1'))->toBeTrue()
        ->and(MikrotikRouter::isValidHost('192.168.88.1'))->toBeTrue()
        ->and(MikrotikRouter::isValidHost('nas-core-01.isp.net'))->toBeTrue()
        ->and(MikrotikRouter::isValidHost('127.0.0.1'))->toBeFalse()
        ->and(MikrotikRouter::isValidHost('169.254.169.254'))->toBeFalse()
        ->and(MikrotikRouter::isValidHost('gowa'))->toBeFalse()
        ->and(MikrotikRouter::isValidHost('redis'))->toBeFalse()
        ->and(MikrotikRouter::isValidHost('-oProxyCommand=id'))->toBeFalse()
        ->and(MikrotikRouter::isValidHost('10.0.0.1/extra'))->toBeFalse()
        ->and(MikrotikRouter::isValidUsername('admin_noc'))->toBeTrue()
        ->and(MikrotikRouter::isValidUsername('-oProxyCommand=id'))->toBeFalse()
        ->and(MikrotikRouter::isValidUsername('admin;id'))->toBeFalse();
});

test('CustomerSpreadsheetService neutralizes spreadsheet formula injection payloads', function () {
    expect(CustomerSpreadsheetService::neutralizeSpreadsheetCell('=HYPERLINK("http://evil")'))->toBe("'=HYPERLINK(\"http://evil\")")
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell('+cmd|\' /C calc\'!A0'))->toBe("'+cmd|' /C calc'!A0")
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell('-2+3'))->toBe("'-2+3")
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell('@SUM(1+1)'))->toBe("'@SUM(1+1)")
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell("\t=1+1"))->toBe("'\t=1+1")
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell('Budi Santoso'))->toBe('Budi Santoso')
        ->and(CustomerSpreadsheetService::neutralizeSpreadsheetCell(150000.0))->toBe(150000.0);
});

test('Deactivated portal users are blocked and logged out immediately across portal routes and policies', function () {
    $inactiveCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'is_active' => false,
    ]);

    $this->actingAs($inactiveCustomer)
        ->get(route('portal.dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('Admin Livewire components enforce RBAC in boot() even on direct component invocation', function () {
    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);

    Livewire::actingAs($customer)->test(PppoeManagerComponent::class)->assertForbidden();
    Livewire::actingAs($finance)->test(PppoeManagerComponent::class)->assertForbidden();
    Livewire::actingAs($noc)->test(WhatsappDeviceComponent::class)->assertForbidden();
    Livewire::actingAs($customer)->test(WhatsappBroadcastComponent::class)->assertForbidden();
    Livewire::actingAs($customer)->test(SystemMonitoringComponent::class)->assertForbidden();
});

test('MikroTik .rsc backups are encrypted at rest and transparently decrypted on download', function () {
    Storage::fake('local');
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-ENC',
        'ros_version_type' => 'v7',
        'host' => '10.10.10.9',
        'username' => 'admin',
        'password_encrypted' => 'secret',
        'is_active' => true,
    ]);

    $plaintextRsc = "/system identity set name=NAS-ENC\n/ppp secret add name=user1 password=supersecret\n";
    $fakeClient = new class($plaintextRsc) implements RouterOsClientInterface {
        public function __construct(private string $rsc) {}
        public function print(string $path, array $where = []): array { return []; }
        public function add(string $path, array $attributes): array { return []; }
        public function set(string $path, string $id, array $attributes): array { return []; }
        public function remove(string $path, string $id): array { return []; }
        public function command(string $path, array $params = []): array { return []; }
        public function exportRsc(): string { return $this->rsc; }
        public function close(): void {}
    };

    $factory = Mockery::mock(RouterOsClientFactory::class);
    $factory->shouldReceive('make')->andReturn($fakeClient);
    app()->instance(RouterOsClientFactory::class, $factory);

    $backup = app(MikrotikBackupService::class)->createRscBackup($router, $noc, 'Encrypted backup');
    $storedRaw = (string) Storage::disk('local')->get($backup->file_path);

    expect($storedRaw)->toStartWith(MikrotikBackupService::ENCRYPTED_PREFIX)
        ->and($storedRaw)->not->toContain('supersecret');

    $download = $this->actingAs($noc)
        ->get(route('admin.mikrotik.backups.download', $backup))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($download->getContent())->toBe($plaintextRsc);
});

test('NOC cannot overwrite a staff user via updateCustomerPortalAccount', function () {
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $customer = Customer::query()->create([
        'user_id' => $superAdmin->id,
        'customer_code' => 'IQ-261121999',
        'full_name' => 'Linked Admin',
        'phone_whatsapp' => '628123456999',
        'installation_address' => 'Jl. Security',
    ]);

    $this->actingAs($noc)
        ->post(route('admin.customers.portal-account', $customer), [
            'portal_username' => 'hijacked_admin',
            'portal_password' => 'NewPassword123',
        ])
        ->assertForbidden();
});
