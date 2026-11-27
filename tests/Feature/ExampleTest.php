<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_super_admin_can_view_executive_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Executive Dashboard')
            ->assertSee('Net Revenue');
    }

    public function test_executive_dashboard_displays_monthly_revenue_chart_and_removes_webhook_card(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $noc = User::factory()->create([
            'role' => UserRole::Noc,
            'is_active' => true,
        ]);

        $router = MikrotikRouter::query()->create([
            'name' => 'POP-DASH',
            'ros_version_type' => 'v7',
            'host' => '10.10.10.1',
            'username' => 'admin',
            'password_encrypted' => 'secret',
            'is_active' => true,
        ]);

        $package = Package::query()->create([
            'code' => 'DASH-30M',
            'name' => 'Home 30M',
            'service_type' => 'pppoe',
            'mikrotik_profile_name' => 'prof-30m',
            'speed_download_mbps' => 30,
            'speed_upload_mbps' => 15,
            'price' => 250000,
            'tax_rate' => 11,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'IQ-261101',
            'full_name' => 'Budi Santoso',
            'phone_whatsapp' => '6281234567890',
            'installation_address' => 'Jl. Merdeka No. 1',
        ]);

        $nowWib = now('Asia/Jakarta');
        $twoMonthsAgoWib = $nowWib->copy()->startOfMonth()->subMonths(2)->addDays(4);
        $prevMonthKey = $nowWib->copy()->startOfMonth()->subMonth()->format('Y-m');

        $subscription = Subscription::query()->create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'mikrotik_router_id' => $router->id,
            'service_username' => 'budi.pppoe',
            'service_password_encrypted' => 'secret123',
            'billing_day' => 1,
            'next_billing_date' => $nowWib->copy()->addMonth()->toDateString(),
            'status' => SubscriptionStatus::Active,
        ]);

        $paidCurrentInv = Invoice::query()->create([
            'invoice_number' => 'INV-202611-001',
            'public_token' => 'tok-cur-001',
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'billing_period_start' => $nowWib->copy()->startOfMonth()->toDateString(),
            'billing_period_end' => $nowWib->copy()->endOfMonth()->toDateString(),
            'subtotal' => 250000,
            'tax_amount' => 0,
            'gateway_fee_charged' => 0,
            'total_amount' => 250000,
            'status' => InvoiceStatus::Paid,
            'due_date' => $nowWib->copy()->toDateString(),
            'paid_at' => $nowWib->copy(),
        ]);

        Payment::query()->create([
            'invoice_id' => $paidCurrentInv->id,
            'payment_code' => 'PAY-CUR-001',
            'gateway' => 'sumopod_qris',
            'amount' => 250000,
            'gateway_fee' => 2050,
            'net_amount' => 247950,
            'status' => PaymentStatus::Paid,
            'paid_at' => $nowWib->copy(),
        ]);

        $paidTwoMonthsAgoInv = Invoice::query()->create([
            'invoice_number' => 'INV-202609-001',
            'public_token' => 'tok-old-001',
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'billing_period_start' => $twoMonthsAgoWib->copy()->startOfMonth()->toDateString(),
            'billing_period_end' => $twoMonthsAgoWib->copy()->endOfMonth()->toDateString(),
            'subtotal' => 300000,
            'tax_amount' => 0,
            'gateway_fee_charged' => 0,
            'total_amount' => 300000,
            'status' => InvoiceStatus::Paid,
            'due_date' => $twoMonthsAgoWib->toDateString(),
            'paid_at' => $twoMonthsAgoWib->copy(),
        ]);

        Payment::query()->create([
            'invoice_id' => $paidTwoMonthsAgoInv->id,
            'payment_code' => 'PAY-OLD-001',
            'gateway' => 'manual_cash',
            'amount' => 300000,
            'gateway_fee' => 0,
            'net_amount' => 300000,
            'status' => PaymentStatus::Paid,
            'paid_at' => $twoMonthsAgoWib->copy(),
        ]);

        $unpaidInvoice = Invoice::query()->create([
            'invoice_number' => 'INV-202612-001',
            'public_token' => 'tok-unpaid-001',
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'billing_period_start' => $nowWib->copy()->startOfMonth()->addMonth()->toDateString(),
            'billing_period_end' => $nowWib->copy()->startOfMonth()->addMonth()->endOfMonth()->toDateString(),
            'subtotal' => 250000,
            'tax_amount' => 0,
            'gateway_fee_charged' => 0,
            'total_amount' => 250000,
            'status' => InvoiceStatus::Unpaid,
            'due_date' => $nowWib->copy()->addMonth()->toDateString(),
        ]);

        PaymentWebhookLog::query()->create([
            'svix_id' => 'msg_test_webhook_001',
            'external_reference' => 'EXT-001',
            'payment_code' => 'PAY-CUR-001',
            'event_type' => 'payment.succeeded',
            'verification_method' => 'svix_hmac',
            'signature_valid' => true,
            'http_status_returned' => 200,
            'processing_status' => 'processed',
            'payload_json' => ['event' => 'payment.succeeded'],
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Monthly Revenue')
            ->assertSee('Recent Invoices')
            ->assertSee('metricValue(item)', false)
            ->assertDontSee('valueOf(item)', false)
            ->assertSee($unpaidInvoice->invoice_number)
            ->assertSee(route('admin.finance.index'))
            ->assertDontSee('Sumopod Webhook Activity')
            ->assertDontSee('Aktivitas Webhook Sumopod');

        $monthlyRevenue = collect($response->viewData('monthlyRevenue'));
        $this->assertCount(12, $monthlyRevenue);

        $currentRow = $monthlyRevenue->firstWhere('key', $nowWib->format('Y-m'));
        $twoMonthsAgoRow = $monthlyRevenue->firstWhere('key', $twoMonthsAgoWib->format('Y-m'));
        $emptyRow = $monthlyRevenue->firstWhere('key', $prevMonthKey);

        $this->assertSame(247950.0, $currentRow['net']);
        $this->assertSame(250000.0, $currentRow['gross']);
        $this->assertSame(1, $currentRow['count']);
        $this->assertTrue($currentRow['is_current']);

        $this->assertSame(300000.0, $twoMonthsAgoRow['net']);
        $this->assertSame(300000.0, $twoMonthsAgoRow['gross']);
        $this->assertSame(1, $twoMonthsAgoRow['count']);

        $this->assertSame(0.0, $emptyRow['net']);
        $this->assertSame(0, $emptyRow['count']);

        $nocResponse = $this->actingAs($noc)->get(route('admin.dashboard'));
        $nocResponse->assertOk()
            ->assertSee('Monthly Revenue')
            ->assertSee('Recent Invoices')
            ->assertDontSee('Sumopod Webhook Activity')
            ->assertDontSee('Aktivitas Webhook Sumopod')
            ->assertDontSee(route('admin.finance.index'));
    }

    public function test_logo_and_favicon_assets_exist_and_render_across_layouts(): void
    {
        foreach ([
            'favicon.ico',
            'favicon-16x16.png',
            'favicon-32x32.png',
            'apple-touch-icon.png',
            'images/iqbill-logo.png',
            'images/iqbill-logo-192.png',
        ] as $assetPath) {
            $fullPath = public_path($assetPath);
            $this->assertFileExists($fullPath);
            $this->assertGreaterThan(0, filesize($fullPath));
        }

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('images/iqbill-logo-192.png', false)
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('apple-touch-icon.png', false);

        $admin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('images/iqbill-logo-192.png', false)
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('apple-touch-icon.png', false);
    }
}
