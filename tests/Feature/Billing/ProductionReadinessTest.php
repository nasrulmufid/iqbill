<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\MikrotikProvisioningJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeSubscription(SubscriptionStatus $status, string $nextBilling): Subscription
{
    $router = MikrotikRouter::query()->create([
        'name' => 'NAS', 'host' => '10.0.0.1', 'username' => 'a', 'password_encrypted' => 'b',
    ]);
    $package = Package::query()->create([
        'code' => 'P' . uniqid(), 'name' => 'Home', 'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'p', 'speed_download_mbps' => 10, 'speed_upload_mbps' => 10,
        'price' => 100000, 'tax_rate' => 0,
    ]);
    $customer = Customer::query()->create([
        'customer_code' => 'C' . uniqid(), 'full_name' => 'Test User',
        'phone_whatsapp' => '628100000000', 'installation_address' => 'Addr',
    ]);

    return Subscription::query()->create([
        'customer_id' => $customer->id, 'package_id' => $package->id,
        'mikrotik_router_id' => $router->id, 'service_username' => 'u' . uniqid(),
        'service_password_encrypted' => 'x', 'billing_day' => 1,
        'next_billing_date' => $nextBilling, 'status' => $status,
    ]);
}

test('gateway fee is 0.7% + Rp300 rounded up and charged to customer when configured', function () {
    expect(InvoiceService::calculateGatewayFee(150000.0))->toBe(1350.0);

    Queue::fake();
    Http::fake();
    CompanySetting::current()->update(['sumopod_fee_bearer' => 'customer', 'pregenerate_qris_on_invoice' => false]);

    $sub = makeSubscription(SubscriptionStatus::Active, now()->addDays(3)->toDateString());
    $invoice = app(InvoiceService::class)->issueForSubscription($sub);

    expect((float) $invoice->gateway_fee_charged)->toBe(1000.0)
        ->and((float) $invoice->total_amount)->toBe(101000.0)
        ->and($invoice->invoice_number)->toStartWith('INV-');

    // idempoten
    expect(app(InvoiceService::class)->issueForSubscription($sub))->toBeNull();
});

test('manual cash payment on active subscription advances next_billing_date by one cycle', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);
    $sub = makeSubscription(SubscriptionStatus::Active, '2026-12-01');

    $invoice = Invoice::query()->create([
        'invoice_number' => 'INV-202612-000001', 'public_token' => str_repeat('b', 32),
        'subscription_id' => $sub->id, 'customer_id' => $sub->customer_id,
        'billing_period_start' => '2026-12-01', 'billing_period_end' => '2026-12-31',
        'subtotal' => 100000, 'total_amount' => 100000, 'status' => InvoiceStatus::Unpaid,
        'due_date' => '2026-12-08',
    ]);

    $this->actingAs($admin)->post(route('admin.invoices.manual-payment', $invoice))->assertRedirect();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($sub->fresh()->next_billing_date->toDateString())->toBe('2027-01-01')
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
    Queue::assertNotPushed(MikrotikProvisioningJob::class);
});

test('NOC can activate pending subscription: creates secret job, activates and issues first invoice', function () {
    Queue::fake();
    Http::fake();
    CompanySetting::current()->update(['pregenerate_qris_on_invoice' => false]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $sub = makeSubscription(SubscriptionStatus::PendingInstallation, now()->addDays(5)->toDateString());

    $this->actingAs($noc)
        ->post(route('admin.subscriptions.provision', $sub), ['action' => 'activate'])
        ->assertRedirect();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->fresh()->activated_at)->not->toBeNull()
        ->and(Invoice::query()->where('subscription_id', $sub->id)->count())->toBe(1);
    Queue::assertPushed(MikrotikProvisioningJob::class, fn ($j) => $j->action === 'sync_secret');
});

test('login is rate limited after 5 failed attempts and security headers are present', function () {
    User::factory()->create(['email' => 'x@y.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.attempt'), ['email' => 'x@y.test', 'password' => 'wrong']);
    }

    $this->post(route('login.attempt'), ['email' => 'x@y.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many login attempts');

    $this->get(route('login'))->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
});
