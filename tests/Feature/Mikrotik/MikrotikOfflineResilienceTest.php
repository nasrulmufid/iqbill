<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\MikrotikException;
use App\Jobs\MikrotikProvisioningJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikProvisioningLog;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappNotificationLog;
use App\Services\Mikrotik\RouterOsClientFactory;
use App\Services\Mikrotik\RouterOsClientInterface;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function setupOfflineResilienceScenario(): array
{
    $rawSecret = base64_encode('whsec_offline_resilience_secret_32b');

    CompanySetting::current()->update([
        'company_name' => 'NetGuard ISP',
        'sumopod_environment' => 'sandbox',
        'sumopod_webhook_verification_mode' => 'signature',
        'sumopod_signing_secret_encrypted' => 'whsec_' . $rawSecret,
        'wa_gateway_url' => 'http://gowa.local:3000',
        'telegram_enabled' => true,
        'telegram_bot_token_encrypted' => '12345:TESTTOKEN',
        'telegram_chat_id' => '-100123456',
        'telegram_notify_payment' => true,
        'telegram_notify_error' => true,
    ]);

    $router = MikrotikRouter::query()->create([
        'name' => 'POP-TIMUR-01',
        'ros_version_type' => 'v7',
        'host' => '10.10.20.1',
        'api_port' => 8728,
        'rest_port' => 443,
        'username' => 'api-billing',
        'password_encrypted' => 'secret',
        'isolir_address_list' => 'ISOLIR',
        'isolir_profile_name' => 'prof-isolir',
        'is_active' => true,
        'is_reachable' => true,
    ]);

    $package = Package::query()->create([
        'code' => 'FIBER-30M',
        'name' => 'Fiber 30 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m',
        'speed_download_mbps' => 30,
        'speed_upload_mbps' => 15,
        'price' => 200000,
    ]);

    $portalUser = User::factory()->create([
        'role' => UserRole::Customer,
        'username' => '261118001',
        'is_active' => true,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $portalUser->id,
        'customer_code' => 'IQ-261118001',
        'full_name' => 'Bagus Santoso',
        'phone_whatsapp' => '6281234567001',
        'installation_address' => 'Jl. Cendana No. 1',
    ]);

    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'bagus.pppoe',
        'service_password_encrypted' => 'pppoepass',
        'static_ip_address' => '10.50.0.25',
        'billing_day' => 18,
        'isolir_day' => 18,
        'next_billing_date' => '2026-11-18',
        'status' => SubscriptionStatus::Isolated,
        'isolated_at' => now()->subDay(),
    ]);

    $invoice = Invoice::query()->create([
        'invoice_number' => 'INV-202611-000888',
        'public_token' => Str::random(32),
        'subscription_id' => $subscription->id,
        'customer_id' => $customer->id,
        'billing_period_start' => '2026-11-18',
        'billing_period_end' => '2026-12-17',
        'subtotal' => 200000,
        'total_amount' => 200000,
        'payment_attempt_count' => 1,
        'status' => InvoiceStatus::Overdue,
        'due_date' => '2026-11-18',
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000888-01',
        'external_reference' => 'pay_offline_router_01',
        'gateway' => 'sumopod_qris',
        'amount' => 200000,
        'qris_expires_at' => now()->addHours(12),
        'status' => PaymentStatus::Pending,
    ]);

    return compact('rawSecret', 'router', 'package', 'portalUser', 'customer', 'subscription', 'invoice', 'payment');
}

function bindOfflineRouterClient(): void
{
    app()->bind(RouterOsClientFactory::class, fn () => new class extends RouterOsClientFactory {
        public function make(MikrotikRouter $router): RouterOsClientInterface
        {
            return new class implements RouterOsClientInterface {
                public function print(string $path, array $where = []): array
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function add(string $path, array $attributes): array
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function set(string $path, string $id, array $attributes): array
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function remove(string $path, string $id): array
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function command(string $path, array $params = []): array
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function exportRsc(): string
                {
                    throw new MikrotikException('Connection timed out (router offline)');
                }

                public function close(): void
                {
                }
            };
        }
    });
}

function bindOnlineFakeRouterClient(): object
{
    $recorder = new class implements RouterOsClientInterface {
        public array $added = [];
        public array $setCalls = [];
        public array $removed = [];

        public function print(string $path, array $where = []): array
        {
            return match ($path) {
                '/system/identity' => [['name' => 'POP-TIMUR-01']],
                '/system/resource' => [['version' => '7.16.1', 'uptime' => '3m12s']],
                '/ip/firewall/address-list' => [
                    ['.id' => '*A1', 'list' => 'ISOLIR', 'address' => '10.50.0.25'],
                ],
                '/ppp/profile' => [
                    ['.id' => '*PR1', 'name' => 'prof-30m', 'rate-limit' => '15M/30M', 'comment' => 'BILLING:FIBER-30M'],
                ],
                '/ppp/secret' => [], // Menguji pula pembuatan secret otomatis bila sebelumnya belum terbuat
                '/ppp/active' => [
                    ['.id' => '*P1', 'name' => 'bagus.pppoe'],
                ],
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
            return [];
        }

        public function exportRsc(): string
        {
            return "# fake export\n";
        }

        public function close(): void
        {
        }
    };

    app()->bind(RouterOsClientFactory::class, fn () => new class($recorder) extends RouterOsClientFactory {
        public function __construct(private RouterOsClientInterface $client)
        {
        }

        public function make(MikrotikRouter $router): RouterOsClientInterface
        {
            return $this->client;
        }
    });

    return $recorder;
}

test('1 & 3. Online payment when router is offline marks sync failed, withholds service_restored WA, and auto-reconciles when router returns online', function () {
    $this->seed(NotificationTemplateSeeder::class);
    $ctx = setupOfflineResilienceScenario();

    Http::fake([
        'http://gowa.local:3000/send/message' => Http::response(['code' => 'SUCCESS'], 200),
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    bindOfflineRouterClient();

    $payload = [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay_offline_router_01',
            'order_id' => 'PAY-202611-000888-01',
            'amount' => 200000,
            'fee' => 1700,
            'net_amount' => 198300,
            'status' => 'completed',
        ],
    ];

    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $svixId = 'msg_offline_001';
    $timestamp = time();
    $sig = base64_encode(hash_hmac('sha256', "{$svixId}.{$timestamp}.{$rawBody}", base64_decode($ctx['rawSecret'], true), true));

    $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        [
            'HTTP_SVIX_ID' => $svixId,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,{$sig}",
            'CONTENT_TYPE' => 'application/json',
        ],
        $rawBody
    )->assertOk()->assertJson(['received' => true]);

    $sub = $ctx['subscription']->fresh();
    $router = $ctx['router']->fresh();

    expect($ctx['invoice']->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($ctx['payment']->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->mikrotik_sync_status)->toBe('failed')
        ->and($sub->mikrotik_pending_action)->toBe('restore')
        ->and($sub->mikrotik_pending_notify)->toBeTrue()
        ->and($sub->mikrotik_last_sync_error)->toContain('timed out')
        ->and($router->is_reachable)->toBeFalse();

    // Kuitansi pembayaran sudah terkirim, tetapi service_restored TIDAK dikirim prematur
    expect(WhatsappNotificationLog::query()->where('template_type', 'payment_receipt')->count())->toBe(1)
        ->and(WhatsappNotificationLog::query()->where('template_type', 'service_restored')->count())->toBe(0);

    // Alert error router offline terkirim ke Telegram admin
    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org')
        && str_contains((string) ($req['text'] ?? ''), 'MikroTik Offline'));

    // --- Sekarang router kembali online dan scheduler mikrotik:reconcile-pending dijalankan ---
    $recorder = bindOnlineFakeRouterClient();

    $this->artisan('mikrotik:reconcile-pending')
        ->expectsOutputToContain('synced 1 subscription(s)')
        ->assertExitCode(0);

    $sub->refresh();
    $router->refresh();

    expect($sub->mikrotik_sync_status)->toBe('synced')
        ->and($sub->mikrotik_pending_action)->toBeNull()
        ->and($sub->mikrotik_pending_notify)->toBeFalse()
        ->and($sub->mikrotik_last_sync_error)->toBeNull()
        ->and($router->is_reachable)->toBeTrue();

    // Secret dibuat dengan profil aktif, entry ISOLIR dihapus, dan sesi aktif diputus
    expect($recorder->removed)->toHaveCount(2)
        ->and($recorder->added[0]['path'])->toBe('/ppp/secret')
        ->and($recorder->added[0]['attributes']['profile'])->toBe('prof-30m');

    // Baru sekarang WA service_restored terkirim ke pelanggan
    expect(WhatsappNotificationLog::query()->where('template_type', 'service_restored')->count())->toBe(1);

    $reconcileLog = MikrotikProvisioningLog::query()->where('trigger_source', 'reconciler')->latest('id')->first();
    expect($reconcileLog)->not->toBeNull()->and($reconcileLog->is_success)->toBeTrue();
});

test('2. Provisioning job releases with backoff on async queue retry and skips stale isolate after restore supersedes it', function () {
    $ctx = setupOfflineResilienceScenario();
    bindOfflineRouterClient();

    $sub = $ctx['subscription'];
    $sub->forceFill([
        'status' => SubscriptionStatus::Active,
        'mikrotik_sync_status' => 'pending',
        'mikrotik_pending_action' => 'restore',
        'mikrotik_pending_notify' => true,
    ])->save();

    $mockQueueJob = new class implements QueueJobContract {
        public ?int $releasedDelay = null;
        public function uuid() { return null; }
        public function getJobId() { return 'job-1'; }
        public function getRawBody() { return ''; }
        public function fire() {}
        public function release($delay = 0) { $this->releasedDelay = $delay; }
        public function isReleased() { return $this->releasedDelay !== null; }
        public function delete() {}
        public function isDeleted() { return false; }
        public function isDeletedOrReleased() { return $this->isReleased(); }
        public function attempts() { return 1; }
        public function markAsFailed() {}
        public function hasFailed() { return false; }
        public function fail($e = null) {}
        public function maxTries() { return 4; }
        public function maxExceptions() { return null; }
        public function timeout() { return null; }
        public function retryUntil() { return null; }
        public function getName() { return 'MikrotikProvisioningJob'; }
        public function resolveName() { return 'MikrotikProvisioningJob'; }
        public function resolveQueuedJobClass() { return MikrotikProvisioningJob::class; }
        public function getConnectionName() { return 'database'; }
        public function getQueue() { return 'default'; }
        public function payload() { return []; }
    };

    $job = new MikrotikProvisioningJob($sub, 'restore', 'webhook_paid');
    $job->setJob($mockQueueJob);
    app()->call([$job, 'handle']);

    expect($mockQueueJob->releasedDelay)->toBe(15)
        ->and($sub->fresh()->mikrotik_sync_status)->toBe('pending')
        ->and($sub->fresh()->mikrotik_pending_action)->toBe('restore');

    // Job isolir usang tidak boleh mengeksekusi karena pending_action sudah digantikan 'restore' (Last-Write-Wins)
    $recorder = bindOnlineFakeRouterClient();
    $staleIsolateJob = new MikrotikProvisioningJob($sub, 'isolate', 'scheduler_isolir');
    app()->call([$staleIsolateJob, 'handle']);

    expect($recorder->added)->toBeEmpty()
        ->and($sub->fresh()->mikrotik_pending_action)->toBe('restore');
});

test('4. Customer portal shows soothing sync notice while pending and hides after manual reconcile', function () {
    $this->seed(NotificationTemplateSeeder::class);
    $ctx = setupOfflineResilienceScenario();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);

    Http::fake([
        'http://gowa.local:3000/send/message' => Http::response(['code' => 'SUCCESS'], 200),
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $ctx['invoice']->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now()])->save();
    $ctx['subscription']->forceFill([
        'status' => SubscriptionStatus::Active,
        'mikrotik_sync_status' => 'failed',
        'mikrotik_pending_action' => 'restore',
        'mikrotik_pending_notify' => true,
        'mikrotik_pending_invoice_id' => $ctx['invoice']->id,
        'mikrotik_last_sync_error' => 'Router POP-TIMUR-01 timeout',
    ])->save();
    $ctx['router']->forceFill(['is_reachable' => false, 'last_health_error' => 'Timeout'])->save();

    // Customer portal displays reassuring pending-sync notice on dashboard & invoice detail
    $this->actingAs($ctx['portalUser'])
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertSee('Connection Activation Awaiting Router Online');

    $this->actingAs($ctx['portalUser'])
        ->get(route('portal.invoices.show', $ctx['invoice']))
        ->assertOk()
        ->assertSee('Automatic Network Sync in Progress');

    // Admin Dashboard & Routers page show pending sync queue
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('MikroTik Network Attention Required');

    $this->actingAs($admin)
        ->get(route('admin.routers.index'))
        ->assertOk()
        ->assertSee('Pending Router Sync Queue');

    // Admin triggers manual reconciliation once router comes online
    bindOnlineFakeRouterClient();

    $this->actingAs($admin)
        ->post(route('admin.mikrotik.reconcile'))
        ->assertRedirect();

    expect($ctx['subscription']->fresh()->mikrotik_sync_status)->toBe('synced');

    // Pending notice disappears from portal after reconciliation
    $this->actingAs($ctx['portalUser'])
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertDontSee('Connection Activation Awaiting Router Online');
});
