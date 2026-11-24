<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PppoeUsageLog;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function setupPortalContext(): array
{
    CompanySetting::current()->update([
        'company_name' => 'Nusantara Fiber Net',
        'company_phone' => '62811000999',
        'company_address' => 'Jl. Sudirman Kav. 10, Jakarta',
    ]);

    $router = MikrotikRouter::query()->create([
        'name' => 'POP-PUSAT',
        'ros_version_type' => 'v7',
        'host' => '10.10.10.1',
        'username' => 'admin',
        'password_encrypted' => 'secret',
        'is_active' => true,
        'is_reachable' => true,
    ]);

    $package = Package::query()->create([
        'code' => 'FIBER-30M',
        'name' => 'Home Fiber 30 Mbps',
        'description' => 'Unlimited FUP Tanpa Kuota',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m',
        'speed_download_mbps' => 30,
        'speed_upload_mbps' => 15,
        'price' => 250000,
        'tax_rate' => 11,
    ]);

    $userA = User::factory()->create([
        'name' => 'Rizky Pratama',
        'username' => '261118001',
        'email' => 'rizky@portal.test',
        'password' => Hash::make('secret123'),
        'role' => UserRole::Customer,
        'is_active' => true,
    ]);

    $customerA = Customer::query()->create([
        'user_id' => $userA->id,
        'customer_code' => 'IQ-261118001',
        'full_name' => 'Rizky Pratama',
        'phone_whatsapp' => '6281234567801',
        'email' => 'rizky@portal.test',
        'installation_address' => 'Perum Asri Blok B No. 7',
    ]);

    $subA = Subscription::query()->create([
        'customer_id' => $customerA->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'rizky.fiber',
        'service_password_encrypted' => 'pppoe123',
        'static_ip_address' => '10.50.10.12',
        'billing_day' => 18,
        'next_billing_date' => now('Asia/Jakarta')->addDays(14)->toDateString(),
        'status' => SubscriptionStatus::Active,
    ]);

    PppoeUsageLog::query()->create([
        'subscription_id' => $subA->id,
        'mikrotik_router_id' => $router->id,
        'rx_bytes_delta' => 2147483648,
        'tx_bytes_delta' => 536870912,
        'last_counter_rx' => 2147483648,
        'last_counter_tx' => 536870912,
        'uptime_seconds' => 36000,
        'recorded_date' => now('Asia/Jakarta')->toDateString(),
        'recorded_at' => now('Asia/Jakarta'),
    ]);

    $unpaidInvoice = Invoice::query()->create([
        'invoice_number' => 'INV-202611-000101',
        'public_token' => Str::random(32),
        'subscription_id' => $subA->id,
        'customer_id' => $customerA->id,
        'billing_period_start' => '2026-11-18',
        'billing_period_end' => '2026-12-17',
        'subtotal' => 250000,
        'tax_amount' => 27500,
        'gateway_fee_charged' => 2243,
        'total_amount' => 279743,
        'status' => InvoiceStatus::Unpaid,
        'due_date' => '2026-11-25',
    ]);

    $paidInvoice = Invoice::query()->create([
        'invoice_number' => 'INV-202610-000088',
        'public_token' => Str::random(32),
        'subscription_id' => $subA->id,
        'customer_id' => $customerA->id,
        'billing_period_start' => '2026-10-18',
        'billing_period_end' => '2026-11-17',
        'subtotal' => 250000,
        'tax_amount' => 27500,
        'gateway_fee_charged' => 0,
        'total_amount' => 277500,
        'status' => InvoiceStatus::Paid,
        'due_date' => '2026-10-25',
        'paid_at' => now()->subDays(20),
    ]);

    Payment::query()->create([
        'invoice_id' => $paidInvoice->id,
        'attempt_number' => 1,
        'payment_code' => 'PAY-202610-000088-01',
        'gateway' => 'sumopod_qris',
        'amount' => 277500,
        'status' => PaymentStatus::Paid,
        'paid_at' => now()->subDays(20),
    ]);

    $userB = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Customer::query()->create([
        'user_id' => $userB->id,
        'customer_code' => 'IQ-261118002',
        'full_name' => 'Pelanggan Lain',
        'phone_whatsapp' => '628199999999',
        'installation_address' => 'Alamat Lain',
    ]);

    return compact('userA', 'userB', 'customerA', 'subA', 'unpaidInvoice', 'paidInvoice');
}

test('Portal dashboard shows executive summary, usage stats, tickets, and invoice download actions', function () {
    $ctx = setupPortalContext();

    Ticket::query()->create([
        'ticket_number' => 'TKT-202611-0009',
        'customer_id' => $ctx['customerA']->id,
        'subscription_id' => $ctx['subA']->id,
        'category' => 'gangguan',
        'priority' => 'high',
        'subject' => 'Ping agak tinggi malam hari',
        'description' => 'Tolong dicek jalurnya.',
        'status' => 'open',
    ]);

    $this->actingAs($ctx['userA'])
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertSee('IQ-261118001')
        ->assertSee('Home Fiber 30 Mbps')
        ->assertSee('2.50 GB')
        ->assertSee('INV-202611-000101')
        ->assertSee('TKT-202611-0009')
        ->assertSee(route('portal.invoices.download', $ctx['unpaidInvoice']), false)
        ->assertSee(route('portal.invoices.index'), false)
        ->assertSee(route('portal.account.edit'), false);
});

test('Customer can filter invoices, download valid PDF invoice, preview print faktur, and cannot access others', function () {
    $ctx = setupPortalContext();

    $this->actingAs($ctx['userA'])
        ->get(route('portal.invoices.index', ['status' => 'paid']))
        ->assertOk()
        ->assertSee('INV-202610-000088')
        ->assertDontSee('INV-202611-000101');

    $pdfResponse = $this->actingAs($ctx['userA'])
        ->get(route('portal.invoices.download', $ctx['paidInvoice']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $binary = $pdfResponse->getContent();
    expect($binary)->toStartWith('%PDF-1.4')
        ->toContain('%%EOF')
        ->toContain('INV-202610-000088')
        ->toContain('Rizky Pratama')
        ->toContain('STATUS: PAID');

    $this->actingAs($ctx['userA'])
        ->get(route('portal.invoices.print', $ctx['unpaidInvoice']))
        ->assertOk()
        ->assertSee('INV-202611-000101')
        ->assertSee('Print Invoice')
        ->assertSee('2.243');

    // IDOR protection (fresh session for userB so AuthenticateSession evaluates userB's own session)
    $this->flushSession();

    $this->actingAs($ctx['userB'])
        ->get(route('portal.invoices.download', $ctx['paidInvoice']))
        ->assertForbidden();

    $this->actingAs($ctx['userB'])
        ->get(route('portal.invoices.print', $ctx['paidInvoice']))
        ->assertForbidden();
});

test('Customer can view portal account and update contact email or password', function () {
    $ctx = setupPortalContext();

    $this->actingAs($ctx['userA'])
        ->get(route('portal.account.edit'))
        ->assertOk()
        ->assertSee('IQ-261118001')
        ->assertSee('rizky.fiber')
        ->assertSee('10.50.10.12');

    $this->actingAs($ctx['userA'])
        ->put(route('portal.account.profile'), [
            'email' => 'baru@portal.test',
        ])
        ->assertRedirect();

    expect($ctx['userA']->fresh()->email)->toBe('baru@portal.test')
        ->and($ctx['customerA']->fresh()->email)->toBe('baru@portal.test');

    // Wrong current password rejected
    $this->actingAs($ctx['userA'])
        ->put(route('portal.account.password'), [
            'current_password' => 'salah123',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])
        ->assertSessionHasErrors('current_password');

    // Valid password change succeeds
    $this->actingAs($ctx['userA'])
        ->put(route('portal.account.password'), [
            'current_password' => 'secret123',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Hash::check('newpass123', (string) $ctx['userA']->fresh()->password))->toBeTrue();
});
