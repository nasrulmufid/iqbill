<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\MikrotikProvisioningJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('billing:generate-invoices creates single invoice per cycle and pre-generates QRIS', function () {
    Queue::fake();

    $settings = CompanySetting::current();
    $settings->update([
        'sumopod_api_key_encrypted' => 'sk_test_123',
        'pregenerate_qris_on_invoice' => true,
        'default_due_days' => 7,
    ]);

    Http::fake([
        'https://api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay_auto_01',
            'order_id' => 'PAY-202611-000001-01',
            'amount' => 200000,
            'payment_code' => 'QRIS_AUTO_01',
            'payment_link_url' => 'https://pay.sumopod.com/p/pay_auto_01',
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'status' => 'pending',
        ], 200),
    ]);

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-01',
        'host' => '10.0.0.1',
        'username' => 'admin',
        'password_encrypted' => 'secret',
    ]);

    $package = Package::query()->create([
        'code' => 'HOME-20M',
        'name' => 'Home 20M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-20m',
        'speed_download_mbps' => 20,
        'speed_upload_mbps' => 10,
        'price' => 200000,
        'tax_rate' => 0,
    ]);

    $customer = Customer::query()->create([
        'customer_code' => 'CUST-01',
        'full_name' => 'Siti Aminah',
        'phone_whatsapp' => '628111111111',
        'installation_address' => 'Jl. Anggrek 1',
    ]);

    Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'siti.pppoe',
        'service_password_encrypted' => 'pass123',
        'billing_day' => 24,
        'next_billing_date' => now('Asia/Jakarta')->addDays(7)->toDateString(),
        'status' => SubscriptionStatus::Active,
    ]);

    $this->artisan('billing:generate-invoices')->assertExitCode(0);
    // Running twice must remain idempotent
    $this->artisan('billing:generate-invoices')->assertExitCode(0);

    expect(Invoice::query()->count())->toBe(1);
    $invoice = Invoice::query()->first();
    expect($invoice->payment_attempt_count)->toBe(1)
        ->and(strlen($invoice->public_token))->toBe(32);

    Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
});

test('billing:isolate-overdue isolates overdue subscriptions past grace period', function () {
    Queue::fake();

    $settings = CompanySetting::current();
    $settings->update(['grace_period_days' => 3]);

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-01',
        'host' => '10.0.0.1',
        'username' => 'admin',
        'password_encrypted' => 'secret',
    ]);

    $package = Package::query()->create([
        'code' => 'HOME-20M',
        'name' => 'Home 20M',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-20m',
        'speed_download_mbps' => 20,
        'speed_upload_mbps' => 10,
        'price' => 200000,
        'tax_rate' => 0,
    ]);

    $customer = Customer::query()->create([
        'customer_code' => 'CUST-02',
        'full_name' => 'Andi Wijaya',
        'phone_whatsapp' => '628222222222',
        'installation_address' => 'Jl. Melati 2',
    ]);

    $sub = Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'andi.pppoe',
        'service_password_encrypted' => 'pass123',
        'billing_day' => 1,
        'next_billing_date' => now('Asia/Jakarta')->subDays(10)->toDateString(),
        'status' => SubscriptionStatus::Active,
    ]);

    $invoice = Invoice::query()->create([
        'invoice_number' => 'INV-202611-000999',
        'public_token' => str_repeat('a', 32),
        'subscription_id' => $sub->id,
        'customer_id' => $customer->id,
        'billing_period_start' => now('Asia/Jakarta')->subDays(10)->toDateString(),
        'billing_period_end' => now('Asia/Jakarta')->addDays(20)->toDateString(),
        'subtotal' => 200000,
        'total_amount' => 200000,
        'status' => InvoiceStatus::Unpaid,
        'due_date' => now('Asia/Jakarta')->subDays(5)->toDateString(),
    ]);

    $this->artisan('billing:isolate-overdue')->assertExitCode(0);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Isolated);

    Queue::assertPushed(MikrotikProvisioningJob::class, fn ($job) => $job->action === 'isolate');
    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($job) => $job->templateType === 'service_isolated');
});
