<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\MikrotikProvisioningJob;
use App\Jobs\SendTelegramNotificationJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function setupOnboardingCatalog(): array
{
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-ONBOARD', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'admin', 'password_encrypted' => 'pass',
    ]);
    $package = Package::query()->create([
        'code' => 'HOME-30M', 'name' => 'Home 30M', 'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m', 'speed_download_mbps' => 30, 'speed_upload_mbps' => 15, 'price' => 200000,
    ]);

    return compact('admin', 'router', 'package');
}

test('Add Customer auto-generates IQ-yymmddXXX ID, creates default portal login without IQ, and sends WA when online', function () {
    Queue::fake();
    Http::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    $response = $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'customer_code' => '',
        'full_name' => 'Surya Pratama',
        'phone_whatsapp' => '628123456001',
        'installation_address' => 'Jl. Sudirman No. 1',
        'latitude' => '-6.2088',
        'longitude' => '106.8456',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'static_ip_address' => '10.20.30.11',
        'payment_method' => 'online',
        'portal_username' => '',
        'portal_password' => '',
    ]);

    $customer = Customer::query()->where('full_name', 'Surya Pratama')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->customer_code)->toMatch('/^IQ-' . now('Asia/Jakarta')->format('ymd') . '\d{3,4}$/');

    $response->assertRedirect(route('admin.customers.show', $customer));
    $generatedPassword = (string) session('generated_portal_password');
    expect($generatedPassword)->not->toBe('')
        ->and($generatedPassword)->not->toBe('12345678')
        ->and(strlen($generatedPassword))->toBeGreaterThanOrEqual(12)
        ->and((string) session('success'))->not->toContain($generatedPassword);

    $expectedUsername = preg_replace('/^IQ-?/i', '', $customer->customer_code);
    expect($customer->user->username)->toBe($expectedUsername);

    $sub = Subscription::query()->where('customer_id', $customer->id)->first();
    expect($sub->static_ip_address)->toBe('10.20.30.11')
        ->and($sub->status)->toBe(SubscriptionStatus::PendingInstallation);

    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($j) => $j->templateType === 'invoice_created');

    // Pelanggan tidak bisa login dengan password default lemah 12345678, tetapi bisa dengan password acak yang di-generate
    $this->post('/logout');
    $this->post(route('login.attempt'), [
        'email' => $expectedUsername,
        'password' => '12345678',
    ])->assertSessionHasErrors('email');

    $this->post(route('login.attempt'), [
        'email' => $expectedUsername,
        'password' => $generatedPassword,
    ])->assertRedirect(route('portal.dashboard'));
});

test('Add Customer with cash payment activates package immediately without sending WA billing notice', function () {
    Queue::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'customer_code' => 'IQ-261117001',
        'full_name' => 'Andi Cash',
        'phone_whatsapp' => '628123456002',
        'installation_address' => 'Jl. Thamrin No. 5',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'static_ip_address' => '10.20.30.12',
        'payment_method' => 'cash',
    ])->assertRedirect();

    $customer = Customer::query()->where('customer_code', 'IQ-261117001')->first();
    $sub = Subscription::query()->where('customer_id', $customer->id)->first();

    expect($customer->user->username)->toBe('261117001')
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->activated_at)->not->toBeNull();

    Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    Queue::assertPushed(SendTelegramNotificationJob::class, fn ($j) => $j->type === 'payment');
});

test('Add Customer rejects duplicate customer_code and duplicate IP PPPoE with clear validation errors', function () {
    Queue::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'customer_code' => 'IQ-261117099',
        'full_name' => 'First User',
        'phone_whatsapp' => '628123456003',
        'installation_address' => 'Jl. Satu',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'static_ip_address' => '10.20.30.50',
        'payment_method' => 'cash',
    ])->assertRedirect();

    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'customer_code' => 'IQ-261117099',
        'full_name' => 'Second User',
        'phone_whatsapp' => '628123456004',
        'installation_address' => 'Jl. Dua',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'static_ip_address' => '10.20.30.50',
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['customer_code', 'static_ip_address']);
});

test('Customer Detail page shows Isolir and Buka Isolir controls and works end-to-end', function () {
    Queue::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'customer_code' => 'IQ-261117555',
        'full_name' => 'Detail User',
        'phone_whatsapp' => '628123456555',
        'installation_address' => 'Jl. Detail',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'static_ip_address' => '10.20.30.99',
        'payment_method' => 'cash',
    ])->assertRedirect();

    $customer = Customer::query()->where('customer_code', 'IQ-261117555')->firstOrFail();
    $sub = $customer->subscriptions()->firstOrFail();

    $this->actingAs($ctx['admin'])->get(route('admin.customers.show', $customer))
        ->assertOk()
        ->assertSee('Isolate Now')
        ->assertSee('10.20.30.99');

    $this->actingAs($ctx['admin'])->post(route('admin.subscriptions.provision', $sub), ['action' => 'isolate'])->assertRedirect();
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Isolated);

    $this->actingAs($ctx['admin'])->get(route('admin.customers.show', $customer))
        ->assertOk()
        ->assertSee('Restore Access');

    $this->actingAs($ctx['admin'])->post(route('admin.subscriptions.provision', $sub), ['action' => 'restore'])->assertRedirect();
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('FAB quick-search renders on staff pages and returns autocomplete matches by ID or name', function () {
    Queue::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);
    $portalUser = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $cust1 = Customer::query()->create([
        'customer_code' => 'IQ-261117777',
        'full_name' => 'Raka Pratama',
        'phone_whatsapp' => '628111777888',
        'installation_address' => 'Jl. Merdeka 10',
    ]);
    Subscription::query()->create([
        'customer_id' => $cust1->id,
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'service_username' => 'raka.fiber',
        'service_password_encrypted' => 'x',
        'static_ip_address' => '10.99.0.10',
        'billing_day' => 1,
        'next_billing_date' => '2026-12-01',
        'status' => 'active',
    ]);

    $cust2 = Customer::query()->create([
        'customer_code' => 'IQ-261117888',
        'full_name' => 'Dewi Anggraini',
        'phone_whatsapp' => '628222888999',
        'installation_address' => 'Jl. Sudirman 20',
    ]);

    // FAB tampil pada halaman staff, tidak tampil pada halaman portal pelanggan
    $this->actingAs($ctx['admin'])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('customerQuickSearch')
        ->assertSee('Quick Customer Search');

    $this->actingAs($portalUser)
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertDontSee('customerQuickSearch');

    // Customer biasa diblokir (403)
    $this->actingAs($portalUser)
        ->getJson(route('admin.customers.quick-search', ['q' => 'Raka']))
        ->assertForbidden();

    // SuperAdmin / NOC mendapat hasil pencarian ID + link ke Customer Detail
    $this->actingAs($ctx['admin'])
        ->getJson(route('admin.customers.quick-search', ['q' => 'IQ-261117777']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.customer_code', 'IQ-261117777')
        ->assertJsonPath('results.0.full_name', 'Raka Pratama')
        ->assertJsonPath('results.0.pppoe_username', 'raka.fiber')
        ->assertJsonPath('results.0.url', route('admin.customers.show', $cust1));

    // Pencarian nama + akses Finance diarahkan ke tagihan pelanggan
    $this->actingAs($finance)
        ->getJson(route('admin.customers.quick-search', ['q' => 'Dewi']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.customer_code', 'IQ-261117888')
        ->assertJsonPath('results.0.url', route('admin.invoices.index', ['search' => 'IQ-261117888']));
});

test('Add and Edit Customer auto-normalize WhatsApp phone formats to 628xxxxxxxxxx', function () {
    Queue::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $ctx = setupOnboardingCatalog();

    expect(Customer::normalizeWhatsappPhone('082281501145'))->toBe('6282281501145')
        ->and(Customer::normalizeWhatsappPhone('+628 22 81 50 1145'))->toBe('6282281501145')
        ->and(Customer::normalizeWhatsappPhone('+628-228150-1145'))->toBe('6282281501145')
        ->and(Customer::normalizeWhatsappPhone('+62 0822-8150-1145'))->toBe('6282281501145')
        ->and(Customer::normalizeWhatsappPhone('82281501145'))->toBe('6282281501145');

    // 1. Add customer with 082281501145
    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'full_name' => 'Pelanggan Nol Delapan',
        'phone_whatsapp' => '082281501145',
        'installation_address' => 'Jl. Sudirman No. 1',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $cust1 = Customer::query()->where('full_name', 'Pelanggan Nol Delapan')->firstOrFail();
    expect($cust1->phone_whatsapp)->toBe('6282281501145')
        ->and($cust1->user->phone)->toBe('6282281501145');

    // 2. Add customer with "+628 22 81 50 1145"
    $this->actingAs($ctx['admin'])->post(route('admin.customers.store'), [
        'full_name' => 'Pelanggan Spasi Plus',
        'phone_whatsapp' => '+628 22 81 50 1145',
        'installation_address' => 'Jl. Sudirman No. 2',
        'package_id' => $ctx['package']->id,
        'mikrotik_router_id' => $ctx['router']->id,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $cust2 = Customer::query()->where('full_name', 'Pelanggan Spasi Plus')->firstOrFail();
    expect($cust2->phone_whatsapp)->toBe('6282281501145');

    // 3. Edit customer with "+628-228150-1145"
    $this->actingAs($ctx['admin'])->put(route('admin.customers.update', $cust2), [
        'customer_code' => $cust2->customer_code,
        'full_name' => $cust2->full_name,
        'phone_whatsapp' => '+628-228150-1145',
        'installation_address' => $cust2->installation_address,
    ])->assertRedirect();

    expect($cust2->fresh()->phone_whatsapp)->toBe('6282281501145');
});
