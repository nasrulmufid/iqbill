<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\MikrotikProvisioningJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\ManualActivePeriodService;
use Illuminate\Support\Facades\Queue;

function setupActivePeriodFixture(SubscriptionStatus $status = SubscriptionStatus::Active, ?string $nextBillingDate = null): array
{
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);
    $portalUser = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-ACTIVE-PERIOD',
        'ros_version_type' => 'v7',
        'host' => '10.10.0.1',
        'username' => 'admin',
        'password_encrypted' => 'secret',
    ]);

    $package = Package::query()->create([
        'code' => 'HOME-20M',
        'name' => 'Home 20 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-20m',
        'speed_download_mbps' => 20,
        'speed_upload_mbps' => 10,
        'price' => 150000,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $portalUser->id,
        'customer_code' => 'IQ-261124001',
        'full_name' => 'Budi Santoso',
        'phone_whatsapp' => '6281234567890',
        'installation_address' => 'Jl. Merdeka No. 45',
    ]);

    $date = $nextBillingDate ?? now('Asia/Jakarta')->addDays(10)->toDateString();

    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'package_id' => $package->id,
        'mikrotik_router_id' => $router->id,
        'service_username' => 'budi.pppoe',
        'service_password_encrypted' => 'pass123',
        'billing_day' => 10,
        'auto_isolate' => true,
        'next_billing_date' => $date,
        'status' => $status,
        'activated_at' => $status === SubscriptionStatus::PendingInstallation ? null : now()->subMonth(),
        'isolated_at' => $status === SubscriptionStatus::Isolated ? now()->subDays(2) : null,
    ]);

    return compact('admin', 'noc', 'finance', 'portalUser', 'router', 'package', 'customer', 'subscription');
}

test('Edit Active Period button and date picker modal are shown only on Customer Detail page', function () {
    $ctx = setupActivePeriodFixture();

    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.show', $ctx['customer']))
        ->assertOk()
        ->assertSee('Edit Active Period')
        ->assertSee('manual-active-period-date')
        ->assertSee('type="date"', false);

    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertDontSee('Edit Active Period')
        ->assertDontSee('manual-active-period-date');

    $this->actingAs($ctx['admin'])
        ->get(route('admin.subscriptions.index'))
        ->assertOk()
        ->assertDontSee('Edit Active Period')
        ->assertDontSee('manual-active-period-date');
});

test('Super Admin and NOC can manually extend and reduce active period date and record AuditLog', function () {
    Queue::fake();
    $initialDate = now('Asia/Jakarta')->addDays(10)->toDateString();
    $extendedDate = now('Asia/Jakarta')->addDays(25)->toDateString();
    $reducedDate = now('Asia/Jakarta')->addDays(5)->toDateString();

    $ctx = setupActivePeriodFixture(SubscriptionStatus::Active, $initialDate);
    $sub = $ctx['subscription'];

    // 1. Extend by +15 days as SuperAdmin
    $this->actingAs($ctx['admin'])
        ->from(route('admin.customers.show', $ctx['customer']))
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'active_period_subscription_id' => $sub->id,
            'next_billing_date' => $extendedDate,
            'reason' => 'Outage compensation 15 days',
        ])
        ->assertRedirect(route('admin.customers.show', $ctx['customer']))
        ->assertSessionHas('success');

    expect($sub->fresh()->next_billing_date->toDateString())->toBe($extendedDate)
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::Active);

    $extendLog = AuditLog::query()
        ->where('action', ManualActivePeriodService::AUDIT_ACTION)
        ->where('auditable_id', $sub->id)
        ->latest('id')
        ->first();

    expect($extendLog)->not->toBeNull()
        ->and($extendLog->user_id)->toBe($ctx['admin']->id)
        ->and($extendLog->auditable_type)->toBe(Subscription::class)
        ->and($extendLog->old_values['next_billing_date'])->toBe($initialDate)
        ->and($extendLog->new_values['next_billing_date'])->toBe($extendedDate)
        ->and($extendLog->new_values['delta_days'])->toBe(15)
        ->and($extendLog->new_values['auto_restored'])->toBeFalse()
        ->and($extendLog->new_values['reason'])->toBe('Outage compensation 15 days');

    Queue::assertNotPushed(MikrotikProvisioningJob::class);

    // 2. Reduce by -20 days as NOC
    $this->actingAs($ctx['noc'])
        ->from(route('admin.customers.show', $ctx['customer']))
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'active_period_subscription_id' => $sub->id,
            'next_billing_date' => $reducedDate,
            'reason' => 'Billing date correction',
        ])
        ->assertRedirect(route('admin.customers.show', $ctx['customer']))
        ->assertSessionHas('success');

    expect($sub->fresh()->next_billing_date->toDateString())->toBe($reducedDate);

    $reduceLog = AuditLog::query()
        ->where('action', ManualActivePeriodService::AUDIT_ACTION)
        ->where('auditable_id', $sub->id)
        ->latest('id')
        ->first();

    expect($reduceLog->user_id)->toBe($ctx['noc']->id)
        ->and($reduceLog->old_values['next_billing_date'])->toBe($extendedDate)
        ->and($reduceLog->new_values['next_billing_date'])->toBe($reducedDate)
        ->and($reduceLog->new_values['delta_days'])->toBe(-20)
        ->and($reduceLog->new_values['auto_restored'])->toBeFalse()
        ->and($reduceLog->new_values['reason'])->toBe('Billing date correction');

    // Audit history renders on Customer Detail page
    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.show', $ctx['customer']))
        ->assertOk()
        ->assertSee('Manual Active Period History')
        ->assertSee('Outage compensation 15 days')
        ->assertSee('Billing date correction');
});

test('Updating isolated subscription to a future date automatically restores service, queues MikroTik restore, and records AuditLog', function () {
    Queue::fake();
    $pastDate = now('Asia/Jakarta')->subDays(3)->toDateString();
    $futureDate = now('Asia/Jakarta')->addDays(7)->toDateString();

    $ctx = setupActivePeriodFixture(SubscriptionStatus::Isolated, $pastDate);
    $sub = $ctx['subscription'];

    $this->actingAs($ctx['admin'])
        ->from(route('admin.customers.show', $ctx['customer']))
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'active_period_subscription_id' => $sub->id,
            'next_billing_date' => $futureDate,
            'reason' => 'Customer promised payment next week',
        ])
        ->assertRedirect(route('admin.customers.show', $ctx['customer']))
        ->assertSessionHas('success');

    $fresh = $sub->fresh();
    expect($fresh->next_billing_date->toDateString())->toBe($futureDate)
        ->and($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and($fresh->isolated_at)->toBeNull()
        ->and($fresh->mikrotik_sync_status)->toBe('pending')
        ->and($fresh->mikrotik_pending_action)->toBe('restore')
        ->and($fresh->mikrotik_pending_notify)->toBeTrue();

    Queue::assertPushed(
        MikrotikProvisioningJob::class,
        fn (MikrotikProvisioningJob $job) => $job->subscription->id === $sub->id
            && $job->action === 'restore'
            && $job->triggerSource === 'manual_noc'
    );

    $audit = AuditLog::query()
        ->where('action', ManualActivePeriodService::AUDIT_ACTION)
        ->where('auditable_id', $sub->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['status'])->toBe('isolated')
        ->and($audit->new_values['status'])->toBe('active')
        ->and($audit->new_values['delta_days'])->toBe(10)
        ->and($audit->new_values['auto_restored'])->toBeTrue();
});

test('Updating isolated subscription to today or a past date updates date only without auto-restoring', function () {
    Queue::fake();
    $oldDate = now('Asia/Jakarta')->subDays(5)->toDateString();
    $todayDate = now('Asia/Jakarta')->toDateString();
    $pastDate = now('Asia/Jakarta')->subDays(2)->toDateString();

    $ctx = setupActivePeriodFixture(SubscriptionStatus::Isolated, $oldDate);
    $sub = $ctx['subscription'];

    // Set to today (not strictly in the future)
    $this->actingAs($ctx['admin'])
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'next_billing_date' => $todayDate,
        ])
        ->assertRedirect();

    expect($sub->fresh()->next_billing_date->toDateString())->toBe($todayDate)
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::Isolated)
        ->and($sub->fresh()->isolated_at)->not->toBeNull();

    // Set to past date
    $this->actingAs($ctx['admin'])
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'next_billing_date' => $pastDate,
        ])
        ->assertRedirect();

    expect($sub->fresh()->next_billing_date->toDateString())->toBe($pastDate)
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::Isolated);

    Queue::assertNotPushed(MikrotikProvisioningJob::class);
    expect(AuditLog::query()->where('action', ManualActivePeriodService::AUDIT_ACTION)->count())->toBe(2);
    expect(AuditLog::query()->where('action', ManualActivePeriodService::AUDIT_ACTION)->latest('id')->first()->new_values['auto_restored'])->toBeFalse();
});

test('Updating non-isolated suspended subscription to future date does not auto-restore it', function () {
    Queue::fake();
    $oldDate = now('Asia/Jakarta')->subDays(2)->toDateString();
    $futureDate = now('Asia/Jakarta')->addDays(14)->toDateString();

    $ctx = setupActivePeriodFixture(SubscriptionStatus::Suspended, $oldDate);
    $sub = $ctx['subscription'];

    $this->actingAs($ctx['admin'])
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'next_billing_date' => $futureDate,
        ])
        ->assertRedirect();

    expect($sub->fresh()->next_billing_date->toDateString())->toBe($futureDate)
        ->and($sub->fresh()->status)->toBe(SubscriptionStatus::Suspended);

    Queue::assertNotPushed(MikrotikProvisioningJob::class);
});

test('Submitting unchanged date on active subscription creates no duplicate AuditLog', function () {
    Queue::fake();
    $sameDate = now('Asia/Jakarta')->addDays(10)->toDateString();
    $ctx = setupActivePeriodFixture(SubscriptionStatus::Active, $sameDate);

    $this->actingAs($ctx['admin'])
        ->patch(route('admin.subscriptions.active-period.update', $ctx['subscription']), [
            'next_billing_date' => $sameDate,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(AuditLog::query()->where('action', ManualActivePeriodService::AUDIT_ACTION)->count())->toBe(0);
    Queue::assertNotPushed(MikrotikProvisioningJob::class);
});

test('Validation rejects invalid calendar dates and reopens active period modal on Customer Detail page', function () {
    $ctx = setupActivePeriodFixture();
    $sub = $ctx['subscription'];

    $this->actingAs($ctx['admin'])
        ->from(route('admin.customers.show', $ctx['customer']))
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'active_period_subscription_id' => $sub->id,
            'next_billing_date' => '2026-02-30',
            'reason' => 'Invalid calendar date test',
        ])
        ->assertRedirect(route('admin.customers.show', $ctx['customer']))
        ->assertSessionHasErrors('next_billing_date');

    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.show', $ctx['customer']))
        ->assertOk()
        ->assertSee('Invalid calendar date test');
});

test('Finance, Customer, and unauthenticated users cannot update subscription active period', function () {
    $ctx = setupActivePeriodFixture();
    $sub = $ctx['subscription'];
    $futureDate = now('Asia/Jakarta')->addDays(20)->toDateString();

    $this->patch(route('admin.subscriptions.active-period.update', $sub), [
        'next_billing_date' => $futureDate,
    ])->assertRedirect(route('login'));

    $this->actingAs($ctx['finance'])
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'next_billing_date' => $futureDate,
        ])->assertForbidden();

    $this->actingAs($ctx['portalUser'])
        ->patch(route('admin.subscriptions.active-period.update', $sub), [
            'next_billing_date' => $futureDate,
        ])->assertForbidden();
});
