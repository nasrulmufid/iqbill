<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\MikrotikProvisioningJob;
use App\Jobs\SendTelegramNotificationJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Shuchkin\SimpleXLSXGen;

test('Admin can CRUD packages, export customers xlsx, and import customers from xlsx', function () {
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);

    MikrotikRouter::query()->create([
        'name' => 'NAS-CORE-01', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'admin', 'password_encrypted' => 'pass',
    ]);

    $this->actingAs($noc)->post(route('admin.packages.store'), [
        'code' => 'HOME-50M',
        'name' => 'Home 50 Mbps',
        'description' => 'Unlimited FUP 50 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 25,
        'price' => 300000,
        'tax_rate' => 11,
    ])->assertRedirect();

    $package = Package::query()->where('code', 'HOME-50M')->first();
    expect($package)->not->toBeNull()->and($package->description)->toBe('Unlimited FUP 50 Mbps');

    $this->actingAs($noc)->get(route('admin.packages.index'))
        ->assertOk()
        ->assertSee('Edit Service Package')
        ->assertSee('edit-pkg-code')
        ->assertSee('Download (Mbps)')
        ->assertSee('Upload (Mbps)')
        ->assertSee('Price (IDR)')
        ->assertSee('VAT (%)')
        ->assertSee('Save Changes');

    $this->actingAs($noc)->put(route('admin.packages.update', $package), [
        'editing_package_id' => $package->id,
        'code' => 'HOME-50M',
        'name' => 'Home 50 Mbps Updated',
        'description' => 'SLA 99.5%',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-50m-v2',
        'speed_download_mbps' => 60,
        'speed_upload_mbps' => 30,
        'price' => 325000,
        'tax_rate' => 11,
        'is_active' => '1',
    ])->assertRedirect();

    $package->refresh();
    expect($package->name)->toBe('Home 50 Mbps Updated')
        ->and($package->speed_download_mbps)->toBe(60)
        ->and((float) $package->price)->toBe(325000.0)
        ->and($package->is_active)->toBeTrue();

    // Validation error on Edit reopens Edit modal instead of Add modal
    $otherPackage = Package::query()->create([
        'code' => 'BIZ-100M',
        'name' => 'Business 100 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-100m',
        'speed_download_mbps' => 100,
        'speed_upload_mbps' => 100,
        'price' => 650000,
        'tax_rate' => 11,
        'is_active' => true,
    ]);

    $this->actingAs($noc)->from(route('admin.packages.index'))
        ->put(route('admin.packages.update', $package), [
            'editing_package_id' => $package->id,
            'code' => $otherPackage->code,
            'name' => 'Edited Before Conflict',
            'description' => '',
            'service_type' => 'pppoe',
            'mikrotik_profile_name' => 'prof-50m-v2',
            'speed_download_mbps' => 60,
            'speed_upload_mbps' => 30,
            'price' => 325000,
            'tax_rate' => 11,
        ])
        ->assertRedirect(route('admin.packages.index'))
        ->assertSessionHasErrors('code');

    $this->actingAs($noc)->get(route('admin.packages.index'))
        ->assertOk()
        ->assertSee('addOpen: false', false)
        ->assertSee('Edited Before Conflict');

    $rows = [
        ['customer_code', 'full_name', 'phone_whatsapp', 'email', 'installation_address', 'package_code', 'router_name', 'service_username', 'service_password', 'billing_day', 'isolir_day', 'auto_isolate'],
        ['CUST-X01', 'Rudi Hartono', '62812340001', 'rudi@mail.test', 'Jl. Melati 9', 'HOME-50M', 'NAS-CORE-01', 'rudi.pppoe', 'secret', '10', '10', '1'],
    ];
    $tmpPath = sys_get_temp_dir() . '/import_test_' . uniqid() . '.xlsx';
    file_put_contents($tmpPath, SimpleXLSXGen::fromArray($rows)->__toString());

    $upload = new UploadedFile($tmpPath, 'pelanggan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->actingAs($noc)->post(route('admin.customers.import'), ['file' => $upload])->assertRedirect();
    @unlink($tmpPath);

    $customer = Customer::query()->where('customer_code', 'CUST-X01')->first();
    expect($customer)->not->toBeNull();
    $sub = Subscription::query()->where('customer_id', $customer->id)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->isolir_day)->toBe(10)
        ->and($sub->auto_isolate)->toBeTrue();

    $this->actingAs($noc)->get(route('admin.customers.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('Isolate command respects per-customer isolir_day (due today at 23:59) and auto_isolate flag', function () {
    Queue::fake();
    CompanySetting::current()->update(['grace_period_days' => 5]);

    $router = MikrotikRouter::query()->create(['name' => 'R1', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $pkg = Package::query()->create(['code' => 'P1', 'name' => 'Home', 'service_type' => 'pppoe', 'mikrotik_profile_name' => 'p', 'speed_download_mbps' => 10, 'speed_upload_mbps' => 10, 'price' => 100000]);
    $cust1 = Customer::query()->create(['customer_code' => 'C1', 'full_name' => 'Auto On', 'phone_whatsapp' => '6281', 'installation_address' => 'A']);
    $cust2 = Customer::query()->create(['customer_code' => 'C2', 'full_name' => 'Auto Off', 'phone_whatsapp' => '6282', 'installation_address' => 'B']);

    $today = now('Asia/Jakarta');

    $subAuto = Subscription::query()->create([
        'customer_id' => $cust1->id, 'package_id' => $pkg->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'user.autoon', 'service_password_encrypted' => 'x',
        'billing_day' => 1, 'isolir_day' => $today->day, 'auto_isolate' => true,
        'next_billing_date' => $today->toDateString(), 'status' => SubscriptionStatus::Active,
    ]);

    $subNoAuto = Subscription::query()->create([
        'customer_id' => $cust2->id, 'package_id' => $pkg->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'user.autooff', 'service_password_encrypted' => 'x',
        'billing_day' => 1, 'isolir_day' => $today->day, 'auto_isolate' => false,
        'next_billing_date' => $today->toDateString(), 'status' => SubscriptionStatus::Active,
    ]);

    Invoice::query()->create([
        'invoice_number' => 'INV-ISO-1', 'public_token' => str_repeat('x', 32), 'subscription_id' => $subAuto->id,
        'customer_id' => $cust1->id, 'billing_period_start' => $today->toDateString(), 'billing_period_end' => $today->copy()->addMonth()->toDateString(),
        'subtotal' => 100000, 'total_amount' => 100000, 'status' => InvoiceStatus::Unpaid, 'due_date' => $today->toDateString(),
    ]);

    Invoice::query()->create([
        'invoice_number' => 'INV-ISO-2', 'public_token' => str_repeat('y', 32), 'subscription_id' => $subNoAuto->id,
        'customer_id' => $cust2->id, 'billing_period_start' => $today->toDateString(), 'billing_period_end' => $today->copy()->addMonth()->toDateString(),
        'subtotal' => 100000, 'total_amount' => 100000, 'status' => InvoiceStatus::Unpaid, 'due_date' => $today->toDateString(),
    ]);

    $this->artisan('billing:isolate-overdue')->assertExitCode(0);

    expect($subAuto->fresh()->status)->toBe(SubscriptionStatus::Isolated)
        ->and($subNoAuto->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('Admin Terima Cash on isolated subscription restores MikroTik, extends period, and notifies Telegram & WA', function () {
    Queue::fake();
    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);
    $router = MikrotikRouter::query()->create(['name' => 'R1', 'ros_version_type' => 'v7', 'host' => '10.0.0.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $pkg = Package::query()->create(['code' => 'P1', 'name' => 'Home', 'service_type' => 'pppoe', 'mikrotik_profile_name' => 'p', 'speed_download_mbps' => 10, 'speed_upload_mbps' => 10, 'price' => 150000]);
    $cust = Customer::query()->create(['customer_code' => 'C-CASH', 'full_name' => 'Cash User', 'phone_whatsapp' => '628999', 'installation_address' => 'Addr']);

    $sub = Subscription::query()->create([
        'customer_id' => $cust->id, 'package_id' => $pkg->id, 'mikrotik_router_id' => $router->id,
        'service_username' => 'cash.user', 'service_password_encrypted' => 'x',
        'billing_day' => 10, 'isolir_day' => 10, 'auto_isolate' => true,
        'next_billing_date' => '2026-11-10', 'status' => SubscriptionStatus::Isolated,
    ]);

    $this->actingAs($finance)->post(route('admin.subscriptions.receive-cash', $sub))->assertRedirect();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->fresh()->next_billing_date->toDateString())->toBe('2026-12-10');

    Queue::assertPushed(MikrotikProvisioningJob::class, fn ($j) => $j->action === 'restore');
    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($j) => $j->templateType === 'payment_receipt');
    Queue::assertPushed(SendTelegramNotificationJob::class, fn ($j) => $j->type === 'payment');
});

test('Package can select >1 router and restricts customer onboarding, subscription updates, and XLSX import', function () {
    Queue::fake();
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);

    $r1 = MikrotikRouter::query()->create(['name' => 'NAS-UTARA', 'ros_version_type' => 'v7', 'host' => '10.0.1.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $r2 = MikrotikRouter::query()->create(['name' => 'NAS-SELATAN', 'ros_version_type' => 'v7', 'host' => '10.0.2.1', 'username' => 'a', 'password_encrypted' => 'b']);
    $r3 = MikrotikRouter::query()->create(['name' => 'NAS-TIMUR', 'ros_version_type' => 'v6', 'host' => '10.0.3.1', 'username' => 'a', 'password_encrypted' => 'b']);

    // 1. Store package with > 1 router selected
    $this->actingAs($noc)->post(route('admin.packages.store'), [
        'code' => 'MULTI-50M',
        'name' => 'Multi Router 50M',
        'description' => 'Tersedia di Utara dan Selatan',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-multi-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 50,
        'price' => 350000,
        'tax_rate' => 11,
        'router_ids' => [$r1->id, $r2->id],
    ])->assertRedirect();

    $pkg = Package::query()->with('routers')->where('code', 'MULTI-50M')->firstOrFail();
    expect($pkg->routers->pluck('id')->sort()->values()->all())
        ->toBe([$r1->id, $r2->id])
        ->and($pkg->supportsRouter($r1->id))->toBeTrue()
        ->and($pkg->supportsRouter($r2->id))->toBeTrue()
        ->and($pkg->supportsRouter($r3->id))->toBeFalse();

    $this->actingAs($noc)->get(route('admin.packages.index'))
        ->assertOk()
        ->assertSee('NAS-UTARA')
        ->assertSee('NAS-SELATAN')
        ->assertSee('MikroTik Routers (Multi-select supported)');

    // 2. Onboarding allows assigned routers (r1 & r2) and rejects unassigned router (r3)
    $this->actingAs($noc)->post(route('admin.customers.store'), [
        'full_name' => 'Pelanggan Invalid Router',
        'phone_whatsapp' => '628111000111',
        'installation_address' => 'Jl. Timur No. 1',
        'package_id' => $pkg->id,
        'mikrotik_router_id' => $r3->id,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors('mikrotik_router_id');

    $this->actingAs($noc)->post(route('admin.customers.store'), [
        'full_name' => 'Pelanggan Router Kedua',
        'phone_whatsapp' => '628111000222',
        'installation_address' => 'Jl. Selatan No. 2',
        'package_id' => $pkg->id,
        'mikrotik_router_id' => $r2->id,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $cust2 = Customer::query()->where('phone_whatsapp', '628111000222')->firstOrFail();
    $sub = Subscription::query()->where('customer_id', $cust2->id)->firstOrFail();
    expect($sub->mikrotik_router_id)->toBe($r2->id);

    // 3. Updating subscription rejects unassigned router r3, allows switching to assigned router r1
    $this->actingAs($noc)->put(route('admin.subscriptions.update', $sub), [
        'package_id' => $pkg->id,
        'mikrotik_router_id' => $r3->id,
        'service_username' => $sub->service_username,
        'billing_day' => $sub->billing_day,
        'next_billing_date' => $sub->next_billing_date->toDateString(),
        'auto_isolate' => '1',
    ])->assertSessionHasErrors('mikrotik_router_id');

    $this->actingAs($noc)->put(route('admin.subscriptions.update', $sub), [
        'package_id' => $pkg->id,
        'mikrotik_router_id' => $r1->id,
        'service_username' => $sub->service_username,
        'billing_day' => $sub->billing_day,
        'next_billing_date' => $sub->next_billing_date->toDateString(),
        'auto_isolate' => '1',
    ])->assertRedirect();

    expect($sub->fresh()->mikrotik_router_id)->toBe($r1->id);

    // 4. XLSX import rejects mismatched router (r3) and imports valid assigned router (r2)
    $rows = [
        ['customer_code', 'full_name', 'phone_whatsapp', 'email', 'installation_address', 'package_code', 'router_name', 'service_username', 'service_password', 'billing_day', 'isolir_day', 'auto_isolate'],
        ['CUST-BADR', 'Bad Router', '628990001', 'bad@mail.test', 'Jl. Bad', 'MULTI-50M', 'NAS-TIMUR', 'bad.router.pppoe', 'secret', '10', '10', '1'],
        ['CUST-OKR', 'Ok Router', '628990002', 'ok@mail.test', 'Jl. Ok', 'MULTI-50M', 'NAS-SELATAN', 'ok.router.pppoe', 'secret', '10', '10', '1'],
    ];
    $tmpPath = sys_get_temp_dir() . '/import_multi_router_' . uniqid() . '.xlsx';
    file_put_contents($tmpPath, SimpleXLSXGen::fromArray($rows)->__toString());
    $upload = new UploadedFile($tmpPath, 'multi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->actingAs($noc)->post(route('admin.customers.import'), ['file' => $upload])->assertRedirect();
    @unlink($tmpPath);

    expect(Subscription::query()->where('service_username', 'bad.router.pppoe')->exists())->toBeFalse()
        ->and(Subscription::query()->where('service_username', 'ok.router.pppoe')->where('mikrotik_router_id', $r2->id)->exists())->toBeTrue();

    // 5. Update package to select r2 & r3, then reset all routers via sync_routers=1
    $this->actingAs($noc)->put(route('admin.packages.update', $pkg), [
        'editing_package_id' => $pkg->id,
        'code' => 'MULTI-50M',
        'name' => 'Multi Router 50M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-multi-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 50,
        'price' => 350000,
        'tax_rate' => 11,
        'is_active' => '1',
        'sync_routers' => '1',
        'router_ids' => [$r2->id, $r3->id],
    ])->assertRedirect();

    expect($pkg->fresh()->routers->pluck('id')->sort()->values()->all())->toBe([$r2->id, $r3->id]);

    $this->actingAs($noc)->put(route('admin.packages.update', $pkg), [
        'editing_package_id' => $pkg->id,
        'code' => 'MULTI-50M',
        'name' => 'Multi Router 50M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-multi-50m',
        'speed_download_mbps' => 50,
        'speed_upload_mbps' => 50,
        'price' => 350000,
        'tax_rate' => 11,
        'is_active' => '1',
        'sync_routers' => '1',
    ])->assertRedirect();

    expect($pkg->fresh()->routers)->toBeEmpty()
        ->and($pkg->fresh()->supportsRouter($r1->id))->toBeTrue();
});
