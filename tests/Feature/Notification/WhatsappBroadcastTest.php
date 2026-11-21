<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\SendBulkWhatsAppMessageJob;
use App\Livewire\Admin\WhatsappBroadcastComponent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappNotificationLog;
use App\Services\Notification\WhatsappBroadcastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('RBAC restricts Broadcast WA page to staff roles only', function () {
    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);

    $this->actingAs($customer)->get(route('admin.whatsapp.broadcast'))->assertForbidden();
    $this->actingAs($noc)->get(route('admin.whatsapp.broadcast'))->assertOk()->assertSee('Bulk WhatsApp Broadcast');
    $this->actingAs($admin)->get(route('admin.whatsapp.broadcast'))->assertOk()->assertSee('Bulk WhatsApp Broadcast');
});

test('Bulk WA filters recipients, normalizes 08 phone numbers, personalizes placeholders, and logs delivery', function () {
    CompanySetting::current()->update([
        'wa_gateway_url' => 'http://gowa.local:3000',
        'wa_basic_auth_user' => 'gowa',
        'wa_basic_auth_password_encrypted' => 'secret',
    ]);

    Http::fake([
        'http://gowa.local:3000/send/message' => Http::response(['code' => 'SUCCESS'], 200),
    ]);

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $r1 = MikrotikRouter::query()->create(['name' => 'POP-Utara', 'ros_version_type' => 'v7', 'host' => '10.1.0.1', 'username' => 'u', 'password_encrypted' => 'p']);
    $r2 = MikrotikRouter::query()->create(['name' => 'POP-Selatan', 'ros_version_type' => 'v6', 'host' => '10.2.0.1', 'username' => 'u', 'password_encrypted' => 'p']);
    $pkg1 = Package::query()->create(['code' => 'H50', 'name' => 'Home 50M', 'service_type' => 'pppoe', 'mikrotik_profile_name' => 'p50', 'speed_download_mbps' => 50, 'speed_upload_mbps' => 25, 'price' => 250000]);

    $custA = Customer::query()->create(['customer_code' => 'IQ-261117001', 'full_name' => 'Budi Santoso', 'phone_whatsapp' => '628111222333', 'installation_address' => 'Jl Melati 1']);
    $subA = Subscription::query()->create(['customer_id' => $custA->id, 'package_id' => $pkg1->id, 'mikrotik_router_id' => $r1->id, 'service_username' => 'budi.50m', 'service_password_encrypted' => 'x', 'billing_day' => 1, 'next_billing_date' => '2026-12-01', 'status' => 'active']);
    Invoice::query()->create([
        'invoice_number' => 'INV-202611-000101', 'public_token' => 'paytok101', 'subscription_id' => $subA->id,
        'customer_id' => $custA->id, 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-30',
        'subtotal' => 250000, 'total_amount' => 250000, 'due_date' => '2026-11-25', 'status' => 'unpaid',
    ]);

    $custB = Customer::query()->create(['customer_code' => 'IQ-261117002', 'full_name' => 'Siti Aminah', 'phone_whatsapp' => '081299988877', 'installation_address' => 'Jl Melati 2']);
    Subscription::query()->create(['customer_id' => $custB->id, 'package_id' => $pkg1->id, 'mikrotik_router_id' => $r1->id, 'service_username' => 'siti.50m', 'service_password_encrypted' => 'x', 'billing_day' => 1, 'next_billing_date' => '2026-12-01', 'status' => 'active']);

    $custC = Customer::query()->create(['customer_code' => 'IQ-261117003', 'full_name' => 'Rudi Selatan', 'phone_whatsapp' => '628777888999', 'installation_address' => 'Jl Mawar 3']);
    Subscription::query()->create(['customer_id' => $custC->id, 'package_id' => $pkg1->id, 'mikrotik_router_id' => $r2->id, 'service_username' => 'rudi.50m', 'service_password_encrypted' => 'x', 'billing_day' => 1, 'next_billing_date' => '2026-12-01', 'status' => 'isolated']);

    $this->actingAs($admin);

    Livewire::test(WhatsappBroadcastComponent::class)
        ->set('segment', 'active')
        ->set('routerId', $r1->id)
        ->set('title', 'Info Maintenance POP Utara')
        ->set('messageBody', 'Halo {customer_name} ({pppoe_username}), paket {package} - tagihan {no_invoice} {payment_link}')
        ->set('dispatchMode', 'sync')
        ->call('sendBroadcast')
        ->assertHasNoErrors();

    $broadcast = WhatsappBroadcast::query()->latest('id')->first();
    expect($broadcast)->not->toBeNull()
        ->and($broadcast->total_recipients)->toBe(2)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(0)
        ->and($broadcast->status)->toBe('completed');

    Http::assertSentCount(2);
    Http::assertSent(fn ($req) => $req['phone'] === '6281299988877');

    $logA = WhatsappNotificationLog::query()->where('customer_id', $custA->id)->first();
    expect($logA)->not->toBeNull()
        ->and($logA->broadcast_id)->toBe($broadcast->id)
        ->and($logA->delivery_status)->toBe('sent')
        ->and($logA->message_body)->toContain('Budi Santoso')
        ->and($logA->message_body)->toContain('budi.50m')
        ->and($logA->message_body)->toContain('Home 50M')
        ->and($logA->message_body)->toContain('INV-202611-000101')
        ->and($logA->message_body)->toContain('/bayar/paytok101');
});

test('Bulk WA queue mode staggers jobs and retries failed recipients cleanly', function () {
    CompanySetting::current()->update(['wa_gateway_url' => 'http://gowa.local:3000']);

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $cust1 = Customer::query()->create(['customer_code' => 'IQ-261117011', 'full_name' => 'Andi Satu', 'phone_whatsapp' => '6281110001', 'installation_address' => 'Jl 1']);
    $cust2 = Customer::query()->create(['customer_code' => 'IQ-261117012', 'full_name' => 'Andi Dua', 'phone_whatsapp' => '6281110002', 'installation_address' => 'Jl 2']);

    $this->actingAs($admin);

    // 1. Uji preselect customer_ids via query string & dispatch mode queue
    Queue::fake();

    $response = $this->get(route('admin.whatsapp.broadcast', ['customer_ids' => [$cust1->id, $cust2->id]]));
    $response->assertOk()->assertSee('Andi Satu')->assertSee('Andi Dua');

    $service = app(WhatsappBroadcastService::class);
    $queuedBroadcast = $service->dispatchBroadcast($admin, [$cust1->id, $cust2->id], [
        'title' => 'Promo Fiber',
        'target_segment' => 'manual',
        'message_body' => 'Promo spesial untuk {customer_name}!',
        'dispatch_mode' => 'queue',
        'delay_seconds' => 5,
    ]);

    expect($queuedBroadcast->status)->toBe('queued')
        ->and($queuedBroadcast->total_recipients)->toBe(2);

    Queue::assertPushed(SendBulkWhatsAppMessageJob::class, 2);
    Queue::assertPushed(SendBulkWhatsAppMessageJob::class, fn ($job) => $job->delay !== null);

    // 2. Uji partial failure + retryFailed pada sync mode
    Http::fake([
        'http://gowa.local:3000/send/message' => Http::sequence()
            ->push(['code' => 'SUCCESS'], 200)
            ->push(['error' => 'Session disconnected'], 500)
            ->push(['code' => 'SUCCESS'], 200),
    ]);

    $partial = $service->dispatchBroadcast($admin, [$cust1->id, $cust2->id], [
        'title' => 'Tes Retry',
        'target_segment' => 'manual',
        'message_body' => 'Halo {customer_name}',
        'dispatch_mode' => 'sync',
        'delay_seconds' => 0,
    ]);

    expect($partial->status)->toBe('completed_with_errors')
        ->and($partial->sent_count)->toBe(1)
        ->and($partial->failed_count)->toBe(1);

    Livewire::test(WhatsappBroadcastComponent::class)
        ->call('retryFailedBroadcast', $partial->id)
        ->assertHasNoErrors();

    $partial->refresh();
    expect($partial->status)->toBe('completed')
        ->and($partial->sent_count)->toBe(2)
        ->and($partial->failed_count)->toBe(0);
});
