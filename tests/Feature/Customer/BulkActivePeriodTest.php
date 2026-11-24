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

function setupBulkActivePeriodFixtures(): array
{
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);
    $finance = User::factory()->create(['role' => UserRole::Finance, 'is_active' => true]);
    $portalUser = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $router = MikrotikRouter::query()->create([
        'name' => 'NAS-BULK-01',
        'ros_version_type' => 'v7',
        'host' => '10.20.0.1',
        'username' => 'admin',
        'password_encrypted' => 'secret',
    ]);

    $package = Package::query()->create([
        'code' => 'HOME-30M-BULK',
        'name' => 'Home 30 Mbps',
        'service_type' => 'pppoe',
        'mikrotik_profile_name' => 'prof-30m',
        'speed_download_mbps' => 30,
        'speed_upload_mbps' => 15,
        'price' => 200000,
    ]);

    $makeCustomerWithSub = static function (
        string $code,
        string $name,
        string $username,
        SubscriptionStatus $status,
        string $nextBillingDate
    ) use ($router, $package): array {
        $customer = Customer::query()->create([
            'customer_code' => $code,
            'full_name' => $name,
            'phone_whatsapp' => '62812'.random_int(10000000, 99999999),
            'installation_address' => 'Jl. Raya '.$code,
        ]);

        $sub = Subscription::query()->create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'mikrotik_router_id' => $router->id,
            'service_username' => $username,
            'service_password_encrypted' => 'pass',
            'billing_day' => 1,
            'auto_isolate' => true,
            'next_billing_date' => $nextBillingDate,
            'status' => $status,
            'activated_at' => $status === SubscriptionStatus::PendingInstallation ? null : now()->subMonth(),
            'isolated_at' => $status === SubscriptionStatus::Isolated ? now()->subDays(2) : null,
        ]);

        return [$customer, $sub];
    };

    [$activeCust1, $activeSub1] = $makeCustomerWithSub('IQ-BLK-001', 'Active One', 'active.one', SubscriptionStatus::Active, '2026-12-01');
    [$activeCust2, $activeSub2] = $makeCustomerWithSub('IQ-BLK-002', 'Active Two', 'active.two', SubscriptionStatus::Active, '2026-12-10');
    [$isolatedCust, $isolatedSub] = $makeCustomerWithSub('IQ-BLK-003', 'Isolated Three', 'isolated.three', SubscriptionStatus::Isolated, '2026-11-20');
    [$pendingCust, $pendingSub] = $makeCustomerWithSub('IQ-BLK-004', 'Pending Four', 'pending.four', SubscriptionStatus::PendingInstallation, '2026-12-05');
    [$suspendedCust, $suspendedSub] = $makeCustomerWithSub('IQ-BLK-005', 'Suspended Five', 'suspended.five', SubscriptionStatus::Suspended, '2026-11-15');

    return compact(
        'admin',
        'noc',
        'finance',
        'portalUser',
        'activeCust1',
        'activeSub1',
        'activeCust2',
        'activeSub2',
        'isolatedCust',
        'isolatedSub',
        'pendingCust',
        'pendingSub',
        'suspendedCust',
        'suspendedSub'
    );
}

test('Customers list displays Bulk Active Period controls and current expiry dates', function () {
    $ctx = setupBulkActivePeriodFixtures();

    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertSee('Bulk Active Period')
        ->assertSee('Bulk Adjust Active Period')
        ->assertSee('Select Active on Page (2)')
        ->assertSee('01 Dec 2026')
        ->assertSee('10 Dec 2026');
});

test('Bulk extend (+7 days) on selected customers shifts each active customer by +7 days and skips non-active customers', function () {
    Queue::fake();
    $ctx = setupBulkActivePeriodFixtures();

    $response = $this->actingAs($ctx['admin'])
        ->from(route('admin.customers.index'))
        ->post(route('admin.customers.bulk-active-period'), [
            'form_context' => 'bulk_active_period',
            'target_scope' => 'selected',
            'customer_ids' => [
                $ctx['activeCust1']->id,
                $ctx['activeCust2']->id,
                $ctx['isolatedCust']->id,
                $ctx['pendingCust']->id,
            ],
            'operation' => 'add',
            'days' => 7,
            'reason' => 'Kompensasi gangguan masal 7 hari',
        ]);

    $response->assertRedirect(route('admin.customers.index'))
        ->assertSessionHas('success', 'Bulk active period extended by 7 days for 2 active subscription(s). (2 non-active customer(s) skipped.)');

    // Each active subscription shifts relatively from its own date
    expect($ctx['activeSub1']->fresh()->next_billing_date->toDateString())->toBe('2026-12-08')
        ->and($ctx['activeSub2']->fresh()->next_billing_date->toDateString())->toBe('2026-12-17')
        // Non-active subscriptions are completely untouched
        ->and($ctx['isolatedSub']->fresh()->next_billing_date->toDateString())->toBe('2026-11-20')
        ->and($ctx['isolatedSub']->fresh()->status)->toBe(SubscriptionStatus::Isolated)
        ->and($ctx['pendingSub']->fresh()->next_billing_date->toDateString())->toBe('2026-12-05')
        ->and($ctx['pendingSub']->fresh()->status)->toBe(SubscriptionStatus::PendingInstallation);

    Queue::assertNotPushed(MikrotikProvisioningJob::class);

    $logs = AuditLog::query()
        ->where('action', ManualActivePeriodService::BULK_AUDIT_ACTION)
        ->orderBy('auditable_id')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->auditable_id)->toBe($ctx['activeSub1']->id)
        ->and($logs[0]->old_values['next_billing_date'])->toBe('2026-12-01')
        ->and($logs[0]->new_values['next_billing_date'])->toBe('2026-12-08')
        ->and($logs[0]->new_values['delta_days'])->toBe(7)
        ->and($logs[0]->new_values['bulk'])->toBeTrue()
        ->and($logs[0]->new_values['reason'])->toBe('Kompensasi gangguan masal 7 hari')
        ->and($logs[1]->auditable_id)->toBe($ctx['activeSub2']->id)
        ->and($logs[1]->new_values['next_billing_date'])->toBe('2026-12-17')
        ->and($logs[0]->new_values['bulk_batch_id'])->toBe($logs[1]->new_values['bulk_batch_id']);

    // Bulk audit history shows up on Customer Detail page with Bulk badge
    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.show', $ctx['activeCust1']))
        ->assertOk()
        ->assertSee('Manual Active Period History')
        ->assertSee('Bulk')
        ->assertSee('Kompensasi gangguan masal 7 hari');
});

test('NOC can bulk reduce (-5 days) selected active customers and duplicate IDs are deduplicated', function () {
    Queue::fake();
    $ctx = setupBulkActivePeriodFixtures();

    $this->actingAs($ctx['noc'])
        ->post(route('admin.customers.bulk-active-period'), [
            'target_scope' => 'selected',
            'customer_ids' => [$ctx['activeCust1']->id, $ctx['activeCust1']->id],
            'operation' => 'subtract',
            'days' => 5,
            'reason' => 'Billing alignment',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Bulk active period reduced by 5 days for 1 active subscription(s).');

    expect($ctx['activeSub1']->fresh()->next_billing_date->toDateString())->toBe('2026-11-26')
        ->and($ctx['activeSub2']->fresh()->next_billing_date->toDateString())->toBe('2026-12-10');

    $log = AuditLog::query()->where('action', ManualActivePeriodService::BULK_AUDIT_ACTION)->firstOrFail();
    expect($log->user_id)->toBe($ctx['noc']->id)
        ->and($log->new_values['delta_days'])->toBe(-5);
});

test('Bulk adjustment with all_active scope updates all active subscriptions and excludes all non-active statuses', function () {
    Queue::fake();
    $ctx = setupBulkActivePeriodFixtures();

    $this->actingAs($ctx['admin'])
        ->post(route('admin.customers.bulk-active-period'), [
            'target_scope' => 'all_active',
            'operation' => 'add',
            'days' => 3,
            'reason' => 'All-network maintenance bonus',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Bulk active period extended by 3 days for 2 active subscription(s).');

    expect($ctx['activeSub1']->fresh()->next_billing_date->toDateString())->toBe('2026-12-04')
        ->and($ctx['activeSub2']->fresh()->next_billing_date->toDateString())->toBe('2026-12-13')
        ->and($ctx['isolatedSub']->fresh()->next_billing_date->toDateString())->toBe('2026-11-20')
        ->and($ctx['pendingSub']->fresh()->next_billing_date->toDateString())->toBe('2026-12-05')
        ->and($ctx['suspendedSub']->fresh()->next_billing_date->toDateString())->toBe('2026-11-15');
});

test('Selecting only non-active customers returns an error and writes no AuditLog', function () {
    $ctx = setupBulkActivePeriodFixtures();

    $this->actingAs($ctx['admin'])
        ->post(route('admin.customers.bulk-active-period'), [
            'target_scope' => 'selected',
            'customer_ids' => [$ctx['isolatedCust']->id, $ctx['pendingCust']->id],
            'operation' => 'add',
            'days' => 7,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($ctx['isolatedSub']->fresh()->next_billing_date->toDateString())->toBe('2026-11-20')
        ->and($ctx['pendingSub']->fresh()->next_billing_date->toDateString())->toBe('2026-12-05')
        ->and(AuditLog::query()->where('action', ManualActivePeriodService::BULK_AUDIT_ACTION)->count())->toBe(0);
});

test('Validation errors reopen bulk modal instead of Add Customer modal', function () {
    $ctx = setupBulkActivePeriodFixtures();

    $this->actingAs($ctx['admin'])
        ->from(route('admin.customers.index'))
        ->post(route('admin.customers.bulk-active-period'), [
            'form_context' => 'bulk_active_period',
            'target_scope' => 'selected',
            'operation' => 'add',
            'days' => 0,
        ])
        ->assertRedirect(route('admin.customers.index'))
        ->assertSessionHasErrors(['customer_ids', 'days']);

    $this->actingAs($ctx['admin'])
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertSee('addOpen: false', false)
        ->assertSee('bulkActivePeriodOpen: true', false);
});

test('Finance, Customer, and guests cannot invoke bulk active period endpoint', function () {
    $ctx = setupBulkActivePeriodFixtures();

    $payload = [
        'target_scope' => 'all_active',
        'operation' => 'add',
        'days' => 7,
    ];

    $this->post(route('admin.customers.bulk-active-period'), $payload)
        ->assertRedirect(route('login'));

    $this->actingAs($ctx['finance'])
        ->post(route('admin.customers.bulk-active-period'), $payload)
        ->assertForbidden();

    $this->actingAs($ctx['portalUser'])
        ->post(route('admin.customers.bulk-active-period'), $payload)
        ->assertForbidden();
});
