<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\MikrotikProvisioningJob;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payment\SumopodPaymentSessionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function seedBillingContext(
    SubscriptionStatus $subStatus = SubscriptionStatus::Active,
    InvoiceStatus $invStatus = InvoiceStatus::Unpaid
): array {
    $rawSecret = base64_encode('super-secret-sumopod-signing-key-32bytes');
    $signingSecret = 'whsec_' . $rawSecret;

    $settings = CompanySetting::current();
    $settings->update([
        'sumopod_environment' => 'sandbox',
        'sumopod_api_key_encrypted' => 'sk_test_1234567890',
        'sumopod_webhook_verification_mode' => 'signature',
        'sumopod_signing_secret_encrypted' => $signingSecret,
        'sumopod_webhook_token_encrypted' => 'whtok_test_token',
    ]);

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-TEST-01',
        'host' => '10.0.0.1',
        'api_port' => 8728,
        'use_ssl' => false,
        'username' => 'billing',
        'password_encrypted' => 'router-secret',
        'isolir_address_list' => 'ISOLIR',
        'is_active' => true,
    ]);

    $package = Package::query()->create([
        'code' => 'HOME-30M',
        'name' => 'Home Fiber 30 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m',
        'speed_download_mbps' => 30,
        'speed_upload_mbps' => 15,
        'price' => 150000,
        'tax_rate' => 0,
        'is_active' => true,
    ]);

    $customer = Customer::query()->create([
        'customer_code' => 'CUST-20260001',
        'full_name' => 'Budi Santoso',
        'nik_npwp' => '3174012345678901',
        'phone_whatsapp' => '6281234567890',
        'email' => 'budi@example.com',
        'installation_address' => 'Jl. Merdeka No. 10, Jakarta',
    ]);

    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'budi.pppoe',
        'service_password_encrypted' => 'pppoe-pass',
        'static_ip_address' => '100.64.0.10',
        'billing_day' => 15,
        'next_billing_date' => '2026-11-15',
        'status' => $subStatus,
    ]);

    $invoice = Invoice::query()->create([
        'invoice_number' => 'INV-202611-000123',
        'public_token' => Str::random(32),
        'subscription_id' => $subscription->id,
        'customer_id' => $customer->id,
        'billing_period_start' => '2026-11-15',
        'billing_period_end' => '2026-12-14',
        'subtotal' => 150000,
        'tax_amount' => 0,
        'gateway_fee_charged' => 0,
        'total_amount' => 150000,
        'payment_attempt_count' => 1,
        'status' => $invStatus,
        'due_date' => '2026-11-22',
        'paid_at' => $invStatus === InvoiceStatus::Paid ? now() : null,
    ]);

    return compact('settings', 'router', 'package', 'customer', 'subscription', 'invoice', 'rawSecret');
}

function buildSvixHeaders(string $svixId, int $timestamp, string $rawBody, string $rawBase64Secret): array
{
    $secretBytes = base64_decode($rawBase64Secret, true);
    $signedContent = "{$svixId}.{$timestamp}.{$rawBody}";
    $sig = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

    return [
        'svix-id' => $svixId,
        'svix-timestamp' => (string) $timestamp,
        'svix-signature' => "v1,{$sig}",
    ];
}

test('1. Smart Link redirects immediately to active session without calling Sumopod API', function () {
    Http::fake();
    $ctx = seedBillingContext();
    $invoice = $ctx['invoice'];

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => 'pay_active_01',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'qris_string' => '00020101021226610016ID.CO.SHOPEE.WWW',
        'payment_url' => 'https://pay.sumopod.com/p/pay_active_01',
        'qris_expires_at' => now()->addHours(12),
        'status' => PaymentStatus::Pending,
    ]);

    $response = $this->get(route('public.pay', $invoice->public_token));

    $response->assertRedirect('https://pay.sumopod.com/p/pay_active_01');
    Http::assertNothingSent();
});

test('2. Smart Link auto-renews expired session (-01 -> -02) and redirects to new payment_link_url', function () {
    $ctx = seedBillingContext();
    $invoice = $ctx['invoice'];

    $expiredPayment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => 'pay_expired_01',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'qris_string' => 'OLD_QRIS',
        'payment_url' => 'https://pay.sumopod.com/p/pay_expired_01',
        'qris_expires_at' => now()->subMinutes(10),
        'status' => PaymentStatus::Pending,
    ]);

    Http::fake([
        'https://api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay_new_02',
            'order_id' => 'PAY-202611-000123-02',
            'amount' => 150000,
            'fee' => 1350,
            'net_amount' => 148650,
            'currency' => 'IDR',
            'payment_method_type_code' => 'QRIS',
            'payment_code' => '000201010212NEW_QRIS_02',
            'payment_link_url' => 'https://pay.sumopod.com/p/pay_new_02',
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'status' => 'pending',
        ], 200),
    ]);

    $response = $this->get(route('public.pay', $invoice->public_token));

    $response->assertRedirect('https://pay.sumopod.com/p/pay_new_02');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api-pay-sandbox.sumopod.com/api/v1/payments'
            && $request['order_id'] === 'PAY-202611-000123-02'
            && $request['amount'] === 150000
            && $request['payment_method_type_code'] === 'QRIS';
    });

    expect($expiredPayment->fresh()->status)->toBe(PaymentStatus::Expired);
    expect($invoice->fresh()->payment_attempt_count)->toBe(2);

    $newPayment = Payment::query()->where('payment_code', 'PAY-202611-000123-02')->first();
    expect($newPayment)->not->toBeNull()
        ->and($newPayment->external_reference)->toBe('pay_new_02')
        ->and($newPayment->status)->toBe(PaymentStatus::Pending);
});

test('3. Smart Link displays masked public paid page when invoice is already paid', function () {
    $ctx = seedBillingContext(SubscriptionStatus::Active, InvoiceStatus::Paid);
    $invoice = $ctx['invoice'];

    $response = $this->get(route('public.pay', $invoice->public_token));

    $response->assertOk()
        ->assertViewIs('public.payment-paid')
        ->assertSee('Invoice Paid')
        ->assertSee('INV-202611-000123')
        ->assertSee('B*** S******')
        ->assertDontSee('Budi Santoso')
        ->assertDontSee('3174012345678901')
        ->assertDontSee('Jl. Merdeka No. 10');
});

test('4. Webhook validates Svix HMAC-SHA256, handles late settlement of -01, cancels -02, and dispatches restore job', function () {
    Queue::fake();
    $ctx = seedBillingContext(SubscriptionStatus::Isolated, InvoiceStatus::Unpaid);
    $invoice = $ctx['invoice'];
    $invoice->update(['payment_attempt_count' => 2]);

    $payment01 = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => 'pay_01_late',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'qris_expires_at' => now()->subMinutes(5),
        'status' => PaymentStatus::Expired,
    ]);

    $payment02 = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 2,
        'payment_code' => 'PAY-202611-000123-02',
        'external_reference' => 'pay_02_pending',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'qris_expires_at' => now()->addHours(23),
        'status' => PaymentStatus::Pending,
    ]);

    $payload = [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay_01_late',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 150000,
            'fee' => 1350,
            'net_amount' => 148650,
            'status' => 'completed',
            'paid_at' => '2026-11-15T01:45:12.000Z',
            'settled_at' => '2026-11-17T01:45:12.000Z',
        ],
    ];

    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = buildSvixHeaders('msg_svix_001', time(), $rawBody, $ctx['rawSecret']);

    $response = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    );

    $response->assertOk()->assertJson(['received' => true, 'status' => 'processed']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    expect($payment01->fresh()->status)->toBe(PaymentStatus::Paid);
    expect((float) $payment01->fresh()->gateway_fee)->toBe(1350.0);
    expect((float) $payment01->fresh()->net_amount)->toBe(148650.0);
    expect($payment02->fresh()->status)->toBe(PaymentStatus::Cancelled);
    expect($ctx['subscription']->fresh()->status)->toBe(SubscriptionStatus::Active);

    $log = PaymentWebhookLog::query()->where('svix_id', 'msg_svix_001')->first();
    expect($log)->not->toBeNull()
        ->and($log->processing_status)->toBe('late_settled')
        ->and($log->signature_valid)->toBeTrue();

    Queue::assertPushed(MikrotikProvisioningJob::class, fn ($job) => $job->action === 'restore');
    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($job) => $job->templateType === 'payment_receipt');

    // Duplicate replay with same svix-id returns duplicate_ignored
    $replay = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    );

    $replay->assertOk()->assertJson(['received' => true, 'status' => 'duplicate_ignored']);
});

test('5. Webhook rejects invalid Svix signature with 401 and logs rejection', function () {
    $ctx = seedBillingContext();
    $rawBody = json_encode(['event_type' => 'payment.completed', 'data' => ['payment_id' => 'pay_x']], JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        [
            'HTTP_SVIX_ID' => 'msg_bad_sig',
            'HTTP_SVIX_TIMESTAMP' => (string) time(),
            'HTTP_SVIX_SIGNATURE' => 'v1,invalid_base64_sig',
            'CONTENT_TYPE' => 'application/json',
        ],
        $rawBody
    );

    $response->assertStatus(401)->assertJson(['received' => false, 'error' => 'invalid_webhook_signature']);

    $log = PaymentWebhookLog::query()->latest('id')->first();
    expect($log->processing_status)->toBe('rejected_invalid_auth')
        ->and($log->signature_valid)->toBeFalse();
});

test('6. Webhook rejects amount mismatch with 422', function () {
    $ctx = seedBillingContext();
    $invoice = $ctx['invoice'];

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => 'pay_mismatch',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'status' => PaymentStatus::Pending,
    ]);

    $rawBody = json_encode([
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay_mismatch',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 100000,
        ],
    ], JSON_THROW_ON_ERROR);

    $headers = buildSvixHeaders('msg_mismatch', time(), $rawBody, $ctx['rawSecret']);

    $response = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    );

    $response->assertStatus(422)->assertJson(['received' => false, 'error' => 'amount_mismatch']);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('7. Admin can configure Sandbox and Live Sumopod Base URLs and requests target configured URL cleanly', function () {
    $ctx = seedBillingContext();
    $invoice = $ctx['invoice'];
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);

    $this->actingAs($admin)
        ->get(route('admin.settings.payment.edit'))
        ->assertOk()
        ->assertSee('name="sumopod_sandbox_base_url"', false)
        ->assertSee('name="sumopod_live_base_url"', false)
        ->assertSee(CompanySetting::DEFAULT_SUMOPOD_SANDBOX_BASE_URL)
        ->assertSee(CompanySetting::DEFAULT_SUMOPOD_LIVE_BASE_URL);

    // Configure Sandbox URL (without /payments suffix) and Live URL (with /payments suffix)
    $this->actingAs($admin)->put(route('admin.settings.payment.update'), [
        'company_name' => 'IQ Bill ISP',
        'sumopod_environment' => 'live',
        'sumopod_sandbox_base_url' => 'https://custom-sandbox.sumopod.test/api/v1/',
        'sumopod_live_base_url' => 'https://custom-live.sumopod.test/api/v1/payments/',
        'sumopod_webhook_verification_mode' => 'auto',
        'sumopod_fee_bearer' => 'merchant',
        'default_due_days' => 7,
        'grace_period_days' => 3,
    ])->assertRedirect();

    $settings = CompanySetting::current()->fresh();
    expect($settings->sumopodSandboxBaseUrl())->toBe('https://custom-sandbox.sumopod.test/api/v1')
        ->and($settings->sumopodLiveBaseUrl())->toBe('https://custom-live.sumopod.test/api/v1/payments')
        ->and($settings->sumopodPaymentsEndpoint())->toBe('https://custom-live.sumopod.test/api/v1/payments');

    Http::fake([
        'https://custom-live.sumopod.test/api/v1/payments' => Http::response([
            'payment_id' => 'pay_live_01',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 150000,
            'fee' => 1350,
            'net_amount' => 148650,
            'payment_link_url' => 'https://pay.sumopod.com/p/pay_live_01',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
        'https://custom-sandbox.sumopod.test/api/v1/payments' => Http::response([
            'payment_id' => 'pay_sbx_02',
            'order_id' => 'PAY-202611-000123-02',
            'amount' => 150000,
            'fee' => 1350,
            'net_amount' => 148650,
            'payment_link_url' => 'https://pay.sumopod.com/p/pay_sbx_02',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    $expectedReturnUrl = route('public.pay', $invoice->public_token);

    // 1. Live mode targets exact configured /payments URL and omits payment_code safely
    $livePayment = app(SumopodPaymentSessionService::class)->getOrCreateActiveSession($invoice);
    expect($livePayment->external_reference)->toBe('pay_live_01')
        ->and($livePayment->qris_string)->toBeNull()
        ->and($livePayment->payment_url)->toBe('https://pay.sumopod.com/p/pay_live_01');

    Http::assertSent(function ($request) use ($expectedReturnUrl) {
        return $request->url() === 'https://custom-live.sumopod.test/api/v1/payments'
            && $request['currency'] === 'IDR'
            && $request['expires_in_hours'] === 24
            && $request['success_return_url'] === $expectedReturnUrl
            && $request['cancel_return_url'] === $expectedReturnUrl
            && $request['payment_method_type_code'] === 'QRIS';
    });

    // Expire previous payment, switch to sandbox (/api/v1 without /payments), and verify auto-appended /payments
    $livePayment->update(['status' => PaymentStatus::Expired]);
    $settings->update(['sumopod_environment' => 'sandbox']);

    $sandboxPayment = app(SumopodPaymentSessionService::class)->getOrCreateActiveSession($invoice->fresh());
    expect($sandboxPayment->external_reference)->toBe('pay_sbx_02')
        ->and($sandboxPayment->payment_url)->toBe('https://pay.sumopod.com/p/pay_sbx_02');

    Http::assertSent(fn ($req) => $req->url() === 'https://custom-sandbox.sumopod.test/api/v1/payments');
});

test('8. Webhook accepts Sumopod payment.test event without payment identifiers', function () {
    $ctx = seedBillingContext();

    $rawBody = json_encode([
        'event_type' => 'payment.test',
        'data' => [
            'message' => 'Webhook connection test from Sumopod Settings',
        ],
    ], JSON_THROW_ON_ERROR);

    $headers = buildSvixHeaders('msg_test_ping_01', time(), $rawBody, $ctx['rawSecret']);

    $response = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    );

    $response->assertOk()->assertJson(['received' => true, 'status' => 'test_ok']);

    $log = PaymentWebhookLog::query()->where('svix_id', 'msg_test_ping_01')->first();
    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe('payment.test')
        ->and($log->processing_status)->toBe('processed');
});

test('9. Sumopod customer-borne fee mode stores gross amount on session creation and settles webhook cleanly', function () {
    Queue::fake();
    $ctx = seedBillingContext(SubscriptionStatus::PendingInstallation, InvoiceStatus::Unpaid);
    $invoice = $ctx['invoice'];
    $invoice->update([
        'gateway_fee_charged' => 1350,
        'total_amount' => 151350,
        'payment_attempt_count' => 0,
    ]);

    Http::fake([
        'https://api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 152710,
            'fee' => 1360,
            'net_amount' => 151350,
            'payment_link_url' => 'https://pay.sumopod.com/p/589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'status' => 'pending',
        ], 200),
    ]);

    $payment = app(SumopodPaymentSessionService::class)->getOrCreateActiveSession($invoice->fresh());

    expect((float) $payment->amount)->toBe(152710.0)
        ->and((float) $payment->gateway_fee)->toBe(1360.0)
        ->and((float) $payment->net_amount)->toBe(151350.0);

    $rawBody = json_encode([
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 152710,
            'fee' => 1360,
            'net_amount' => 151350,
            'status' => 'completed',
            'payment_method' => 'qris',
            'paid_at' => '2026-11-24T09:33:25Z',
            'settled_at' => '2026-11-26T10:00:00Z',
            'completed_at' => '2026-11-26T10:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR);

    $headers = buildSvixHeaders('msg_passthrough_new_01', time(), $rawBody, $ctx['rawSecret']);

    $response = $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    );

    $response->assertOk()->assertJson(['received' => true, 'status' => 'processed']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($ctx['subscription']->fresh()->status)->toBe(SubscriptionStatus::Active);

    Queue::assertPushed(MikrotikProvisioningJob::class, fn ($job) => $job->action === 'restore');
    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($job) => $job->templateType === 'payment_receipt');
});

test('10. Webhook settles legacy pending payment with Sumopod pass-through fee and rejects tampered or inconsistent fee payloads', function () {
    Queue::fake();
    $ctx = seedBillingContext(SubscriptionStatus::PendingInstallation, InvoiceStatus::Unpaid);
    $invoice = $ctx['invoice'];
    $invoice->update([
        'gateway_fee_charged' => 1350,
        'total_amount' => 151350,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
        'gateway' => 'sumopod_qris',
        'amount' => 151350,
        'gateway_fee' => 1360,
        'net_amount' => 151350,
        'status' => PaymentStatus::Pending,
    ]);

    // 1. Negative fee attempting underpayment must be rejected with 422
    $negativeFeeBody = json_encode([
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 150000,
            'fee' => -1350,
            'net_amount' => 151350,
        ],
    ], JSON_THROW_ON_ERROR);
    $negHeaders = buildSvixHeaders('msg_neg_fee', time(), $negativeFeeBody, $ctx['rawSecret']);
    $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($negHeaders)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $negativeFeeBody
    )->assertStatus(422)->assertJson(['received' => false, 'error' => 'amount_mismatch']);

    // 2. Inconsistent gross (amount !== net_amount + fee) must be rejected with 422
    $inconsistentBody = json_encode([
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 152710,
            'fee' => 0,
            'net_amount' => 151350,
        ],
    ], JSON_THROW_ON_ERROR);
    $incHeaders = buildSvixHeaders('msg_inconsistent_fee', time(), $inconsistentBody, $ctx['rawSecret']);
    $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($incHeaders)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $inconsistentBody
    )->assertStatus(422)->assertJson(['received' => false, 'error' => 'amount_mismatch']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    // Webhook Logs UI displays recorded error_message for staff diagnostics
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $this->actingAs($admin)
        ->get(route('admin.webhooks.index'))
        ->assertOk()
        ->assertSee('Amount mismatch: expected 151350, got 150000.');

    // 3. Valid resent Sumopod pass-through webhook settles legacy pending payment cleanly
    $validBody = json_encode([
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => '589533ba-c32c-47f8-8167-8d84a17bb0f5',
            'order_id' => 'PAY-202611-000123-01',
            'amount' => 152710,
            'fee' => 1360,
            'net_amount' => 151350,
            'status' => 'completed',
            'payment_method' => 'qris',
            'paid_at' => '2026-11-24T09:33:25Z',
            'settled_at' => '2026-11-26T10:00:00Z',
            'completed_at' => '2026-11-26T10:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR);
    $validHeaders = buildSvixHeaders('msg_passthrough_legacy', time(), $validBody, $ctx['rawSecret']);

    $this->call(
        'POST',
        '/api/webhooks/sumopod',
        [],
        [],
        [],
        collect($validHeaders)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()
            + ['CONTENT_TYPE' => 'application/json'],
        $validBody
    )->assertOk()->assertJson(['received' => true, 'status' => 'processed']);

    $settledPayment = $payment->fresh();
    expect($settledPayment->status)->toBe(PaymentStatus::Paid)
        ->and((float) $settledPayment->amount)->toBe(152710.0)
        ->and((float) $settledPayment->gateway_fee)->toBe(1360.0)
        ->and((float) $settledPayment->net_amount)->toBe(151350.0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($ctx['subscription']->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('11. Magic Link stays public without login prompt on gateway error or stale session, and secret inputs prevent autofill', function () {
    $ctx = seedBillingContext();
    $invoice = $ctx['invoice'];

    // 1. When Sumopod returns HTTP 401, fallback page stays accessible without login CTA
    Http::fake([
        'https://api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([], 401),
    ]);

    $this->get(route('public.pay', $invoice->public_token))
        ->assertOk()
        ->assertViewIs('public.payment-fallback')
        ->assertSee('Retry Connection')
        ->assertSee($invoice->invoice_number)
        ->assertDontSee('Sign In to Customer Portal')
        ->assertDontSee(route('login'), false);

    // 2. Even if browser sends a stale/mismatched portal session cookie (AuthenticateSession mismatch),
    // the Magic Link must still redirect to Sumopod instead of redirecting to /login.
    $userA = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $userB = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $this->actingAs($userA)->get(route('portal.dashboard'))->assertOk();
    $this->withSession(['password_hash_web' => 'stale-mismatched-hash'])
        ->actingAs($userB);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202611-000123-01',
        'external_reference' => 'pay_no_login_01',
        'gateway' => 'sumopod_qris',
        'amount' => 150000,
        'payment_url' => 'https://pay.sumopod.com/p/pay_no_login_01',
        'qris_expires_at' => now()->addHours(12),
        'status' => PaymentStatus::Pending,
    ]);

    $this->get(route('public.pay', $invoice->public_token))
        ->assertRedirect('https://pay.sumopod.com/p/pay_no_login_01');

    // 3. Payment Settings renders autocomplete="new-password" on secret fields to avoid browser autofill overwrites
    $this->flushSession();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $this->actingAs($admin)
        ->get(route('admin.settings.payment.edit'))
        ->assertOk()
        ->assertSee('autocomplete="off"', false)
        ->assertSee('autocomplete="new-password"', false);
});
