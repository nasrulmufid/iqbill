<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MikrotikBackup;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\PppoeUsageLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mikrotik\MikrotikBackupService;
use App\Services\Mikrotik\MikrotikProvisioningService;
use App\Services\Mikrotik\PppoeUsageSyncService;
use App\Services\Mikrotik\RouterOs6BinaryClient;
use App\Services\Mikrotik\RouterOsClientFactory;
use App\Services\Mikrotik\RouterOsClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function makeFakeRouterClient(array $printMap = [], string $exportText = "# dec/17/2026 routeros\n/system identity set name=NAS-FAKE"): RouterOsClientInterface
{
    return new class($printMap, $exportText) implements RouterOsClientInterface {
        public array $added = [];
        public array $removed = [];

        public function __construct(
            private array $printMap,
            private string $exportText
        ) {
        }

        public function print(string $path, array $where = []): array
        {
            $rows = $this->printMap[$path] ?? [];
            if ($where === []) {
                return $rows;
            }

            return array_values(array_filter($rows, function (array $row) use ($where): bool {
                foreach ($where as $k => $v) {
                    if (($row[$k] ?? null) !== $v) {
                        return false;
                    }
                }

                return true;
            }));
        }

        public function add(string $path, array $attributes): array
        {
            $this->added[] = compact('path', 'attributes');

            return [];
        }

        public function set(string $path, string $id, array $attributes): array
        {
            return [];
        }

        public function remove(string $path, string $id): array
        {
            $this->removed[] = compact('path', 'id');

            return [];
        }

        public function command(string $path, array $params = []): array
        {
            return [];
        }

        public function exportRsc(): string
        {
            return $this->exportText;
        }

        public function close(): void
        {
        }
    };
}

test('RouterOS v7 uses REST API and v6 binary parser handles !empty cleanly', function () {
    $v7Router = MikrotikRouter::query()->create([
        'name' => 'ROS7-CORE',
        'ros_version_type' => 'v7',
        'host' => '10.10.10.1',
        'rest_port' => 443,
        'use_ssl' => true,
        'username' => 'apiuser',
        'password_encrypted' => 'secret123',
    ]);

    Http::fake([
        'https://10.10.10.1/rest/system/identity' => Http::response(['name' => 'ROS7-CORE'], 200),
        'https://10.10.10.1/rest/system/resource' => Http::response(['version' => '7.15.2 (stable)', 'uptime' => '3d02:10:00'], 200),
    ]);

    $info = app(MikrotikProvisioningService::class)->testConnection($v7Router);
    expect($info['identity'])->toBe('ROS7-CORE')
        ->and($info['version'])->toBe('7.15.2 (stable)')
        ->and($info['ros_type'])->toBe('v7')
        ->and($v7Router->fresh()->ros_version)->toBe('7.15.2 (stable)');

    // Verifikasi parser !empty pada RouterOs6BinaryClient tidak error
    $v6Router = MikrotikRouter::query()->create([
        'name' => 'ROS6-CORE',
        'ros_version_type' => 'v6',
        'host' => '10.10.10.2',
        'username' => 'apiuser',
        'password_encrypted' => 'secret123',
    ]);
    $v6Client = new class($v6Router) extends RouterOs6BinaryClient {
        public function parseSentencesForTest(array $sentences): array
        {
            $rows = [];
            foreach ($sentences as $sentence) {
                $tag = $sentence[0] ?? '';
                if ($tag === '!empty' || $tag === '!done') {
                    continue;
                }
            }

            return $rows;
        }
    };
    expect($v6Client->parseSentencesForTest([['!empty'], ['!done']]))->toBe([]);
});

test('Setup firewall bawaan installs ISOLIR filter rules idempotently', function () {
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-FW',
        'ros_version_type' => 'v7',
        'host' => '10.0.0.5',
        'username' => 'admin',
        'password_encrypted' => 'pass',
        'isolir_address_list' => 'ISOLIR',
    ]);

    $fakeClient = makeFakeRouterClient();
    $factory = Mockery::mock(RouterOsClientFactory::class);
    $factory->shouldReceive('make')->andReturn($fakeClient);
    app()->instance(RouterOsClientFactory::class, $factory);

    $res = app(MikrotikProvisioningService::class)->setupDefaultFirewall($router, '10.0.0.2');
    expect($res['rules_added'])->toBe(3)
        ->and($router->fresh()->default_firewall_installed_at)->not->toBeNull();
});

test('PPPoE usage sync records Rx/Tx bytes and respects usage_tracking_enabled toggle', function () {
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-USAGE',
        'ros_version_type' => 'v7',
        'host' => '10.0.0.6',
        'username' => 'admin',
        'password_encrypted' => 'pass',
    ]);
    $package = Package::query()->create([
        'code' => 'PKG-U', 'name' => 'Home', 'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof', 'speed_download_mbps' => 20, 'speed_upload_mbps' => 10, 'price' => 150000,
    ]);
    $customer = Customer::query()->create([
        'customer_code' => 'C-U', 'full_name' => 'Usage User', 'phone_whatsapp' => '628111', 'installation_address' => 'Jl. A',
    ]);
    $sub = Subscription::query()->create([
        'customer_id' => $customer->id, 'package_id' => $package->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'budi.pppoe', 'service_password_encrypted' => '123',
        'billing_day' => 1, 'next_billing_date' => now()->toDateString(), 'status' => SubscriptionStatus::Active,
    ]);

    $fakeClient = makeFakeRouterClient([
        '/ppp/active' => [['.id' => '*A1', 'name' => 'budi.pppoe', 'address' => '10.10.1.5', 'uptime' => '1h30m']],
        '/interface' => [['name' => '<pppoe-budi.pppoe>', 'rx-byte' => '5242880', 'tx-byte' => '1048576']],
    ]);
    $factory = Mockery::mock(RouterOsClientFactory::class);
    $factory->shouldReceive('make')->andReturn($fakeClient);
    app()->instance(RouterOsClientFactory::class, $factory);

    CompanySetting::current()->update(['usage_tracking_enabled' => false]);
    $skipped = app(PppoeUsageSyncService::class)->syncAll();
    expect($skipped['skipped'])->toBeTrue()->and(PppoeUsageLog::query()->count())->toBe(0);

    CompanySetting::current()->update(['usage_tracking_enabled' => true]);
    $synced = app(PppoeUsageSyncService::class)->syncAll();
    expect($synced['skipped'])->toBeFalse()->and($synced['synced'])->toBe(1);

    $log = PppoeUsageLog::query()->first();
    expect($log->rx_bytes_delta)->toBe(5242880)
        ->and($log->tx_bytes_delta)->toBe(1048576)
        ->and($log->uptime_seconds)->toBe(5400);
});

test('MikroTik .rsc backup creates, downloads, and deletes backup file', function () {
    Storage::fake('local');
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-BKP', 'ros_version_type' => 'v7', 'host' => '10.0.0.9', 'username' => 'admin', 'password_encrypted' => 'pass',
    ]);

    $fakeClient = makeFakeRouterClient([], "/system identity set name=NAS-BKP\n");
    $factory = Mockery::mock(RouterOsClientFactory::class);
    $factory->shouldReceive('make')->andReturn($fakeClient);
    app()->instance(RouterOsClientFactory::class, $factory);

    $backup = app(MikrotikBackupService::class)->createRscBackup($router, $noc, 'Harian');
    expect(Storage::disk('local')->exists($backup->file_path))->toBeTrue();

    $this->actingAs($noc)->get(route('admin.mikrotik.backups.download', $backup))->assertOk();
    $this->actingAs($noc)->delete(route('admin.mikrotik.backups.destroy', $backup))->assertRedirect();
    expect(MikrotikBackup::query()->count())->toBe(0);
});

test('RouterOS 7 sync_secret auto-creates missing PPP profile from package and HTTP 400 does not mark router offline', function () {
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $router = MikrotikRouter::query()->create([
        'name' => 'LBM',
        'ros_version_type' => 'v7',
        'host' => '192.168.9.10',
        'rest_port' => 80,
        'use_ssl' => false,
        'username' => 'admin',
        'password_encrypted' => 'secret',
        'isolir_address_list' => 'ISOLIR',
        'isolir_profile_name' => 'ISOLIR',
        'is_active' => true,
        'is_reachable' => false,
    ]);
    $pkg = Package::query()->create([
        'code' => 'HOME-20M',
        'name' => 'Home Fiber 20 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-20m',
        'speed_download_mbps' => 20,
        'speed_upload_mbps' => 10,
        'price' => 150000,
    ]);
    $cust = Customer::query()->create([
        'customer_code' => 'IQ-261118010',
        'full_name' => 'Mufid',
        'phone_whatsapp' => '6282281501145',
        'installation_address' => 'Jl. Merdeka',
    ]);
    $sub = Subscription::query()->create([
        'customer_id' => $cust->id,
        'package_id' => $pkg->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'MUFID',
        'service_password_encrypted' => 'pass1234',
        'billing_day' => 18,
        'next_billing_date' => now()->addMonth()->toDateString(),
        'status' => SubscriptionStatus::Active,
        'mikrotik_sync_status' => 'failed',
        'mikrotik_pending_action' => 'sync_secret',
    ]);

    $failWith400 = false;

    Http::fake(function ($request) use (&$failWith400) {
        if ($failWith400) {
            return Http::response(['detail' => 'failure: bad request'], 400);
        }

        return match ($request->url()) {
            'http://192.168.9.10/rest/ppp/profile/print' => Http::response([], 200),
            'http://192.168.9.10/rest/ppp/profile/add' => Http::response(['.id' => '*P20M'], 200),
            'http://192.168.9.10/rest/ppp/secret/print' => Http::response([], 200),
            'http://192.168.9.10/rest/ppp/secret/add' => Http::response(['.id' => '*SEC1'], 200),
            'http://192.168.9.10/rest/ppp/active/print' => Http::response([], 200),
            default => Http::response([], 200),
        };
    });

    $this->actingAs($noc)
        ->post(route('admin.subscriptions.provision', $sub), ['action' => 'sync_secret'])
        ->assertRedirect()
        ->assertSessionHas('success');

    Http::assertSent(fn ($req) => $req->url() === 'http://192.168.9.10/rest/ppp/profile/add'
        && ($req['name'] ?? null) === 'prof-20m'
        && ($req['rate-limit'] ?? null) === '10M/20M'
        && ($req['comment'] ?? null) === 'BILLING:HOME-20M');

    Http::assertSent(fn ($req) => $req->url() === 'http://192.168.9.10/rest/ppp/secret/add'
        && ($req['name'] ?? null) === 'MUFID'
        && ($req['profile'] ?? null) === 'prof-20m'
        && ($req['disabled'] ?? null) === 'no');

    expect($sub->fresh()->mikrotik_sync_status)->toBe('synced')
        ->and($sub->fresh()->mikrotik_pending_action)->toBeNull()
        ->and($router->fresh()->is_reachable)->toBeTrue();

    // Uji jika REST API mengembalikan HTTP 400 -> is_reachable tetap true (router merespons, bukan offline)
    $failWith400 = true;

    $errRes = app(MikrotikProvisioningService::class)->executeForSubscription($sub->fresh(), 'sync_secret', 'manual_noc');
    expect($errRes['ok'])->toBeFalse()
        ->and($errRes['is_connection_error'])->toBeFalse()
        ->and($router->fresh()->is_reachable)->toBeTrue();
});

test('1-way authoritative Sync DB -> MikroTik pushes profiles, active, suspended, and isolated states', function () {
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $router = MikrotikRouter::query()->create([
        'name' => 'POP-SYNC',
        'ros_version_type' => 'v7',
        'host' => '10.10.99.1',
        'rest_port' => 443,
        'use_ssl' => true,
        'username' => 'admin',
        'password_encrypted' => 'secret',
        'isolir_address_list' => 'ISOLIR',
        'isolir_profile_name' => 'ISOLIR',
        'is_active' => true,
    ]);

    $pkgPpp = Package::query()->create([
        'code' => 'HOME-30M',
        'name' => 'Home 30M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m',
        'speed_download_mbps' => 30,
        'speed_upload_mbps' => 15,
        'price' => 225000,
    ]);
    $pkgPpp50 = Package::query()->create([
        'code' => 'HOME-50M',
        'name' => 'Home 50M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 25,
        'price' => 350000,
    ]);

    $c1 = Customer::query()->create(['customer_code' => 'IQ-001', 'full_name' => 'User Aktif', 'phone_whatsapp' => '628101', 'installation_address' => 'A']);
    $c2 = Customer::query()->create(['customer_code' => 'IQ-002', 'full_name' => 'User Suspend', 'phone_whatsapp' => '628102', 'installation_address' => 'B']);
    $c3 = Customer::query()->create(['customer_code' => 'IQ-003', 'full_name' => 'User Isolir', 'phone_whatsapp' => '628103', 'installation_address' => 'C']);

    Subscription::query()->create([
        'customer_id' => $c1->id, 'package_id' => $pkgPpp->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'aktif.user', 'service_password_encrypted' => 'newpass',
        'static_ip_address' => null,
        'billing_day' => 1, 'next_billing_date' => now()->addMonth()->toDateString(),
        'status' => SubscriptionStatus::Active,
    ]);
    Subscription::query()->create([
        'customer_id' => $c2->id, 'package_id' => $pkgPpp->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'suspend.user', 'service_password_encrypted' => 'pass2',
        'billing_day' => 1, 'next_billing_date' => now()->addMonth()->toDateString(),
        'status' => SubscriptionStatus::Suspended,
    ]);
    Subscription::query()->create([
        'customer_id' => $c3->id, 'package_id' => $pkgPpp->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'isolir.user', 'service_password_encrypted' => 'pass3',
        'static_ip_address' => '10.99.1.30',
        'billing_day' => 1, 'next_billing_date' => now()->subDays(2)->toDateString(),
        'status' => SubscriptionStatus::Isolated,
    ]);

    $recorder = new class implements RouterOsClientInterface {
        public array $added = [];
        public array $setCalls = [];
        public array $removed = [];
        public array $commands = [];

        public function print(string $path, array $where = []): array
        {
            return match ($path) {
                '/system/identity' => [['name' => 'POP-SYNC']],
                '/system/resource' => [['version' => '7.16', 'uptime' => '5d']],
                '/ppp/profile' => match ($where['name'] ?? null) {
                    'prof-30m' => [['.id' => '*PR30', 'name' => 'prof-30m', 'rate-limit' => '5M/5M', 'comment' => 'old']],
                    default => [],
                },
                '/ppp/secret' => match ($where['name'] ?? null) {
                    'aktif.user' => [['.id' => '*S1', 'name' => 'aktif.user', 'profile' => 'default', 'disabled' => 'yes', 'remote-address' => '10.99.9.9']],
                    'suspend.user' => [['.id' => '*S2', 'name' => 'suspend.user', 'profile' => 'prof-30m', 'disabled' => 'no']],
                    default => [],
                },
                '/ip/firewall/address-list' => [],
                '/ppp/active' => match ($where['name'] ?? null) {
                    'suspend.user' => [['.id' => '*ACT2', 'name' => 'suspend.user']],
                    'isolir.user' => [['.id' => '*ACT3', 'name' => 'isolir.user']],
                    default => [],
                },
                default => [],
            };
        }

        public function add(string $path, array $attributes): array
        {
            $this->added[] = compact('path', 'attributes');

            return ['.id' => '*NEW'];
        }

        public function set(string $path, string $id, array $attributes): array
        {
            $this->setCalls[] = compact('path', 'id', 'attributes');

            return [];
        }

        public function remove(string $path, string $id): array
        {
            $this->removed[] = compact('path', 'id');

            return [];
        }

        public function command(string $path, array $params = []): array
        {
            $this->commands[] = compact('path', 'params');

            return [];
        }

        public function exportRsc(): string
        {
            return '';
        }

        public function close(): void
        {
        }
    };

    $factory = Mockery::mock(RouterOsClientFactory::class);
    $factory->shouldReceive('make')->andReturn($recorder);
    app()->instance(RouterOsClientFactory::class, $factory);

    $this->actingAs($noc)
        ->post(route('admin.mikrotik.sync-from-db', $router))
        ->assertRedirect()
        ->assertSessionHas('success');

    // prof-30m diperbarui rate-limit-nya (15M/30M), prof-50m & ISOLIR dibuat baru di /ppp/profile
    expect(collect($recorder->setCalls)->contains(fn ($c) => $c['path'] === '/ppp/profile'
        && $c['id'] === '*PR30'
        && ($c['attributes']['rate-limit'] ?? null) === '15M/30M'))->toBeTrue()
        ->and(collect($recorder->added)->contains(fn ($a) => $a['path'] === '/ppp/profile'
            && ($a['attributes']['name'] ?? null) === 'prof-50m'
            && ($a['attributes']['rate-limit'] ?? null) === '25M/50M'
            && ($a['attributes']['comment'] ?? null) === 'BILLING:HOME-50M'))->toBeTrue()
        ->and(collect($recorder->added)->contains(fn ($a) => $a['path'] === '/ppp/profile'
            && ($a['attributes']['name'] ?? null) === 'ISOLIR'))->toBeTrue();

    // aktif.user ditimpa agar disabled=no, profile=prof-30m, dan remote-address di-unset
    expect(collect($recorder->setCalls)->contains(fn ($c) => $c['path'] === '/ppp/secret'
        && $c['id'] === '*S1'
        && ($c['attributes']['profile'] ?? null) === 'prof-30m'
        && ($c['attributes']['disabled'] ?? null) === 'no'
        && ($c['attributes']['password'] ?? null) === 'newpass'))->toBeTrue()
        ->and(collect($recorder->commands)->contains(fn ($cmd) => $cmd['path'] === '/ppp/secret/unset'
            && ($cmd['params']['.id'] ?? null) === '*S1'
            && ($cmd['params']['value-name'] ?? null) === 'remote-address'))->toBeTrue();

    // suspend.user di-disable & diputus sesinya
    expect(collect($recorder->setCalls)->contains(fn ($c) => $c['path'] === '/ppp/secret'
        && $c['id'] === '*S2'
        && ($c['attributes']['disabled'] ?? null) === 'yes'))->toBeTrue()
        ->and(collect($recorder->removed)->contains(fn ($r) => $r['path'] === '/ppp/active' && $r['id'] === '*ACT2'))->toBeTrue();

    // isolir.user dibuat dengan profile ISOLIR, dimasukkan ke firewall ISOLIR list, dan diputus sesinya
    expect(collect($recorder->added)->contains(fn ($a) => $a['path'] === '/ip/firewall/address-list'
        && ($a['attributes']['address'] ?? null) === '10.99.1.30'))->toBeTrue()
        ->and(collect($recorder->added)->contains(fn ($a) => $a['path'] === '/ppp/secret'
            && ($a['attributes']['name'] ?? null) === 'isolir.user'
            && ($a['attributes']['profile'] ?? null) === 'ISOLIR'))->toBeTrue()
        ->and(collect($recorder->removed)->contains(fn ($r) => $r['path'] === '/ppp/active' && $r['id'] === '*ACT3'))->toBeTrue();
});

test('Hotspot and Voucher routes are removed and hotspot service_type is rejected on package creation', function () {
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);

    $this->actingAs($noc)->get('/admin/mikrotik/hotspot')->assertNotFound();
    $this->actingAs($noc)->get('/admin/vouchers')->assertNotFound();

    $this->actingAs($noc)
        ->post(route('admin.packages.store'), [
            'code' => 'HS-TEST',
            'name' => 'Hotspot Test',
            'service_type' => 'hotspot',
            'mikrotik_profile_name' => 'hs-test',
            'speed_download_mbps' => 10,
            'speed_upload_mbps' => 5,
            'price' => 100000,
            'tax_rate' => 11,
        ])
        ->assertSessionHasErrors('service_type');

    $this->assertDatabaseMissing('packages', ['code' => 'HS-TEST']);
});
