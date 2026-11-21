<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\Admin\WhatsappDeviceComponent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\NotificationTemplate;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notification\GowaClient;
use App\Services\Notification\TelegramNotificationService;
use App\Services\Notification\WhatsAppService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('GowaClient uses Basic Auth, fetches devices/QR, and sends message via /send/message', function () {
    CompanySetting::current()->update([
        'wa_gateway_url' => 'http://gowa.local:3000',
        'wa_basic_auth_user' => 'gowa_user',
        'wa_basic_auth_password_encrypted' => 'gowa_pass',
        'wa_device_id' => 'dev-isp-01',
    ]);

    Http::fake([
        'http://gowa.local:3000/app/devices' => Http::response([
            'code' => 'SUCCESS',
            'results' => [['device_id' => 'dev-isp-01', 'push_name' => 'ISP Bot', 'status' => 'CONNECTED']],
        ], 200),
        'http://gowa.local:3000/app/login' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        'http://gowa.local:3000/send/message' => Http::response(['code' => 'SUCCESS'], 200),
    ]);

    $client = app(GowaClient::class);
    $devices = $client->getDevices();
    expect($devices['ok'])->toBeTrue()->and($devices['devices'][0]['push_name'])->toBe('ISP Bot');

    $qr = $client->getLoginQr();
    expect($qr['ok'])->toBeTrue()->and($qr['qr_data_uri'])->toStartWith('data:image/png;base64,');

    $send = $client->sendMessage('+62 812-3456-7890', 'Halo test');
    expect($send['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'http://gowa.local:3000/send/message') {
            return false;
        }
        $expectedAuth = 'Basic ' . base64_encode('gowa_user:gowa_pass');

        return $request->header('Authorization')[0] === $expectedAuth
            && $request->header('X-Device-Id')[0] === 'dev-isp-01'
            && $request['phone'] === '6281234567890';
    });
});

test('WhatsAppService substitutes regex placeholders from NotificationTemplate', function () {
    $this->seed(NotificationTemplateSeeder::class);
    CompanySetting::current()->update(['wa_gateway_url' => 'http://gowa.local:3000']);
    Http::fake(['http://gowa.local:3000/send/message' => Http::response(['code' => 'SUCCESS'], 200)]);

    NotificationTemplate::query()->where('key', 'reminder_h3')->update([
        'body_template' => 'Halo {customer_name}, paket {package} invoice {no_invoice} sebesar {amount}. Bayar di {payment_link}',
    ]);

    $router = MikrotikRouter::query()->create(['name' => 'R', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $pkg = Package::query()->create(['code' => 'P30', 'name' => 'Fiber 30M', 'service_type' => 'pppoe', 'mikrotik_profile_name' => 'p', 'speed_download_mbps' => 30, 'speed_upload_mbps' => 15, 'price' => 225000]);
    $cust = Customer::query()->create(['customer_code' => 'C1', 'full_name' => 'Dewi Lestari', 'phone_whatsapp' => '628111', 'installation_address' => 'Jl X']);
    $sub = Subscription::query()->create(['customer_id' => $cust->id, 'package_id' => $pkg->id, 'mikrotik_router_id' => $router->id, 'service_username' => 'dewi', 'service_password_encrypted' => 'x', 'billing_day' => 1, 'next_billing_date' => '2026-12-01']);
    $inv = Invoice::query()->create([
        'invoice_number' => 'INV-202611-000777', 'public_token' => 'tok777', 'subscription_id' => $sub->id,
        'customer_id' => $cust->id, 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-30',
        'subtotal' => 225000, 'total_amount' => 225000, 'due_date' => '2026-11-20',
    ]);

    $log = app(WhatsAppService::class)->sendTemplate($cust, 'reminder_h3', $inv);
    expect($log->message_body)->toContain('Dewi Lestari')
        ->and($log->message_body)->toContain('Fiber 30M')
        ->and($log->message_body)->toContain('INV-202611-000777')
        ->and($log->message_body)->toContain('Rp 225.000')
        ->and($log->message_body)->toContain('/bayar/tok777');
});

test('TelegramNotificationService sends payment and deduplicated error notifications', function () {
    CompanySetting::current()->update([
        'telegram_enabled' => true,
        'telegram_bot_token_encrypted' => '12345:BOTTOKEN',
        'telegram_chat_id' => '-10098765',
        'telegram_notify_payment' => true,
        'telegram_notify_error' => true,
    ]);

    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $router = MikrotikRouter::query()->create(['name' => 'R', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $pkg = Package::query()->create(['code' => 'P10', 'name' => 'Fiber 10M', 'service_type' => 'pppoe', 'mikrotik_profile_name' => 'p', 'speed_download_mbps' => 10, 'speed_upload_mbps' => 10, 'price' => 100000]);
    $cust = Customer::query()->create(['customer_code' => 'C1', 'full_name' => 'Agus', 'phone_whatsapp' => '628111', 'installation_address' => 'Jl X']);
    $sub = Subscription::query()->create(['customer_id' => $cust->id, 'package_id' => $pkg->id, 'mikrotik_router_id' => $router->id, 'service_username' => 'agus', 'service_password_encrypted' => 'x', 'billing_day' => 1, 'next_billing_date' => '2026-12-01']);
    $inv = Invoice::query()->create([
        'invoice_number' => 'INV-TG-1', 'public_token' => 'toktg', 'subscription_id' => $sub->id,
        'customer_id' => $cust->id, 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-30',
        'subtotal' => 100000, 'total_amount' => 100000, 'due_date' => '2026-11-20',
    ]);
    $payment = Payment::query()->create([
        'invoice_id' => $inv->id, 'attempt_number' => 1, 'payment_code' => 'PAY-TG-1',
        'gateway' => 'sumopod_qris', 'amount' => 100000, 'gateway_fee' => 1000, 'net_amount' => 99000,
    ]);

    $tg = app(TelegramNotificationService::class);
    $tg->notifyPaymentReceived($payment);
    $tg->notifyError('MikroTik', 'Timeout');
    $tg->notifyError('MikroTik', 'Timeout'); // deduplicated dalam 300s

    Http::assertSentCount(2);
});

test('GOWA configuration lives on /admin/whatsapp/device and payment settings update preserves it', function () {
    Http::fake([
        'http://127.0.0.1:3000/app/devices' => Http::response([
            'code' => 'SUCCESS',
            'results' => [['device_id' => 'dev-1', 'push_name' => 'GOWA Server', 'status' => 'CONNECTED']],
        ], 200),
    ]);

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $this->actingAs($admin);

    // Halaman payment settings tidak menampilkan form GOWA lagi
    $this->get(route('admin.settings.payment.edit'))
        ->assertOk()
        ->assertDontSee('name="wa_gateway_url"', false)
        ->assertDontSee('name="wa_basic_auth_user"', false);

    // Halaman Go-WhatsApp menampilkan dan menyimpan konfigurasi GOWA
    Livewire::test(WhatsappDeviceComponent::class)
        ->set('waGatewayUrl', 'http://127.0.0.1:3000')
        ->set('waDeviceId', 'dev-1')
        ->set('waBasicAuthUser', 'gowa_admin')
        ->set('waBasicAuthPassword', 'secret_gowa')
        ->call('saveGatewaySettings')
        ->assertHasNoErrors();

    $settings = CompanySetting::current()->fresh();
    expect($settings->wa_gateway_url)->toBe('http://127.0.0.1:3000')
        ->and($settings->wa_device_id)->toBe('dev-1')
        ->and($settings->wa_basic_auth_user)->toBe('gowa_admin')
        ->and($settings->wa_basic_auth_password_encrypted)->toBe('secret_gowa');

    // Menyimpan pengaturan payment tidak boleh menghapus konfigurasi GOWA yang tersimpan
    $this->put(route('admin.settings.payment.update'), [
        'company_name' => 'ISP Mantap',
        'sumopod_environment' => 'sandbox',
        'sumopod_webhook_verification_mode' => 'auto',
        'sumopod_fee_bearer' => 'merchant',
        'default_due_days' => 7,
        'grace_period_days' => 3,
    ])->assertRedirect();

    $after = CompanySetting::current()->fresh();
    expect($after->wa_gateway_url)->toBe('http://127.0.0.1:3000')
        ->and($after->wa_device_id)->toBe('dev-1')
        ->and($after->wa_basic_auth_user)->toBe('gowa_admin')
        ->and($after->wa_basic_auth_password_encrypted)->toBe('secret_gowa');
});

test('WhatsApp message formatter supports regex placeholders, *bold*, _italic_, ~strike~, `code`, and protects URLs & XSS', function () {
    $service = app(WhatsAppService::class);

    // Substitusi placeholder toleran spasi & huruf besar/kecil
    $rendered = $service->renderTemplateBody(
        "Halo *{CUSTOMER_NAME}*, paket _{ package }_.\r\nLink: https://pay.local/bayar/tok_abc_123",
        ['{customer_name}' => 'Budi Santoso', '{package}' => 'Fiber 30M']
    );

    expect($rendered)->toBe("Halo *Budi Santoso*, paket _Fiber 30M_.\nLink: https://pay.local/bayar/tok_abc_123");

    // Pratinjau HTML format WhatsApp
    $html = WhatsAppService::formatPreviewHtml(
        "halo *TEBAL* _Miring_ ~Coret~ `kode` *_Kombinasi_*\n* Bullet satu\n* Bullet dua\nUser: budi_fiber_01 https://pay.local/bayar/tok_abc_123\n<script>alert(1)</script>"
    );

    expect($html)
        ->toContain('<strong class="font-bold">TEBAL</strong>')
        ->toContain('<em class="italic">Miring</em>')
        ->toContain('<del class="line-through text-slate-500">Coret</del>')
        ->toContain('>kode</code>')
        ->toContain('<strong class="font-bold"><em class="italic">Kombinasi</em></strong>')
        ->toContain('budi_fiber_01')
        ->toContain('href="https://pay.local/bayar/tok_abc_123"')
        ->not->toContain('tok<em>abc</em>123')
        ->not->toContain('<script>');

    // Bullet '* Bullet ...' must not be formatted as bold across lines
    expect(WhatsAppService::formatPreviewHtml("* Item 1\n* Item 2"))
        ->not->toContain('<strong');
});

test('gowa:sync-config populates settings automatically, preserves existing URL without --force, and overwrites with --force', function () {
    CompanySetting::current()->update([
        'wa_gateway_url' => null,
        'wa_basic_auth_user' => null,
        'wa_basic_auth_password_encrypted' => null,
        'wa_device_id' => null,
    ]);

    config()->set('services.gowa', [
        'url' => 'http://gowa:3000/',
        'basic_auth_user' => 'iqbill',
        'basic_auth_password' => 'docker_secret',
        'device_id' => 'dev-docker',
        'auto_sync' => true,
        'force_sync' => false,
    ]);

    $this->artisan('gowa:sync-config')->assertSuccessful();

    $settings = CompanySetting::current()->fresh();
    expect($settings->wa_gateway_url)->toBe('http://gowa:3000')
        ->and($settings->wa_basic_auth_user)->toBe('iqbill')
        ->and($settings->wa_basic_auth_password_encrypted)->toBe('docker_secret')
        ->and($settings->wa_device_id)->toBe('dev-docker');

    // Running again with new env URL without --force preserves manual DB configuration
    config()->set('services.gowa.url', 'http://other-gowa:3000');
    $this->artisan('gowa:sync-config')->assertSuccessful();
    expect(CompanySetting::current()->fresh()->wa_gateway_url)->toBe('http://gowa:3000');

    // Passing --force overwrites with env config
    $this->artisan('gowa:sync-config', ['--force' => true])->assertSuccessful();
    expect(CompanySetting::current()->fresh()->wa_gateway_url)->toBe('http://other-gowa:3000');
});

test('GowaClient::getLoginQr rewrites loopback and relative qr_link values to configured gateway origin', function () {
    CompanySetting::current()->update([
        'wa_gateway_url' => 'http://gowa:3000',
        'wa_basic_auth_user' => 'iqbill',
        'wa_basic_auth_password_encrypted' => 'secret',
    ]);

    Http::fakeSequence('http://gowa:3000/app/login')
        ->push(['results' => ['qr_link' => 'http://localhost:3000/statics/qr-local.png', 'qr_duration' => 30]], 200)
        ->push(['results' => ['qr_link' => '/statics/qr-rel.png', 'qr_duration' => 45]], 200);

    Http::fake([
        'http://gowa:3000/statics/qr-local.png' => Http::response('png-local', 200, ['Content-Type' => 'image/png']),
        'http://gowa:3000/statics/qr-rel.png' => Http::response('png-rel', 200, ['Content-Type' => 'image/png']),
    ]);

    $client = app(GowaClient::class);

    $first = $client->getLoginQr();
    expect($first['ok'])->toBeTrue()
        ->and($first['qr_data_uri'])->toBe('data:image/png;base64,' . base64_encode('png-local'));

    $second = $client->getLoginQr();
    expect($second['ok'])->toBeTrue()
        ->and($second['qr_duration'])->toBe(45)
        ->and($second['qr_data_uri'])->toBe('data:image/png;base64,' . base64_encode('png-rel'));
});

test('NotificationTemplate::defaults preserves Indonesian WhatsApp customer message templates', function () {
    $defaults = collect(NotificationTemplate::defaults())->keyBy('key');

    expect($defaults->get('reminder_h3')['body_template'])->toContain('Halo {customer_name}')
        ->and($defaults->get('service_isolated')['body_template'])->toContain('terisolir')
        ->and($defaults->get('payment_receipt')['body_template'])->toContain('Terima kasih {customer_name}!');
});
