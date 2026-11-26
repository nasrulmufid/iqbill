<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

test('first-time deployment seed flags Super Admin for mandatory one-time password change and blocks admin routes until completed', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', 'admin@iqbill.local')->firstOrFail();

    expect($admin->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->must_change_password)->toBeTrue()
        ->and($admin->password_changed_at)->toBeNull()
        ->and($admin->requiresInitialPasswordChange())->toBeTrue();

    // Signing in redirects directly to the mandatory setup-password page
    $this->post(route('login.attempt'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.password.force.edit'));

    // Web routes and JSON/Livewire requests are blocked until password is changed
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.password.force.edit'));

    $this->get(route('admin.users.index'))
        ->assertRedirect(route('admin.password.force.edit'));

    $this->getJson('/api/admin/metrics')
        ->assertForbidden();

    $this->get(route('admin.password.force.edit'))
        ->assertOk()
        ->assertSee('Set Super Admin Password')
        ->assertSee('First-Deployment Security Requirement')
        ->assertSee($admin->email);

    // Reject wrong current password
    $this->put(route('admin.password.force.update'), [
        'current_password' => 'wrong-password',
        'password' => 'StrongPass2026!',
        'password_confirmation' => 'StrongPass2026!',
    ])->assertSessionHasErrors('current_password');

    // Reject weak password (< 12 chars)
    $this->put(route('admin.password.force.update'), [
        'current_password' => 'password',
        'password' => 'Short123',
        'password_confirmation' => 'Short123',
    ])->assertSessionHasErrors('password');

    // Reject reusing the exact same initial password (set a 12-char initial password to verify reuse guard)
    $admin->forceFill(['password' => Hash::make('InitialPass2026')])->save();
    $this->post(route('logout'));
    $this->post(route('login.attempt'), [
        'email' => $admin->email,
        'password' => 'InitialPass2026',
    ])->assertRedirect(route('admin.password.force.edit'));

    $this->put(route('admin.password.force.update'), [
        'current_password' => 'InitialPass2026',
        'password' => 'InitialPass2026',
        'password_confirmation' => 'InitialPass2026',
    ])->assertSessionHasErrors('password');

    // Complete mandatory password change with a strong new password
    $this->put(route('admin.password.force.update'), [
        'current_password' => 'InitialPass2026',
        'password' => 'NewEnterprisePass2026!',
        'password_confirmation' => 'NewEnterprisePass2026!',
    ])->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('success');

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and($admin->password_changed_at)->not->toBeNull()
        ->and($admin->requiresInitialPasswordChange())->toBeFalse()
        ->and(Hash::check('NewEnterprisePass2026!', (string) $admin->password))->toBeTrue();

    expect(AuditLog::query()
        ->where('user_id', $admin->id)
        ->where('action', 'super_admin_initial_password_changed')
        ->exists())->toBeTrue();

    // Dashboard now loads cleanly while staying authenticated under AuthenticateSession
    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Executive Dashboard');

    // Setup-password page never appears again after first completion
    $this->get(route('admin.password.force.edit'))
        ->assertRedirect(route('admin.dashboard'));

    // Re-running AdminUserSeeder after initial setup does NOT re-trigger forced password change
    $this->seed(AdminUserSeeder::class);
    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and($admin->password_changed_at)->not->toBeNull()
        ->and($admin->requiresInitialPasswordChange())->toBeFalse();
});

test('mandatory first-deployment password change applies only to Super Admin and does not affect other roles', function () {
    $noc = User::factory()->create([
        'role' => UserRole::Noc,
        'is_active' => true,
        'must_change_password' => true,
        'password_changed_at' => null,
    ]);

    expect($noc->requiresInitialPasswordChange())->toBeFalse();

    $this->actingAs($noc)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($noc)
        ->get(route('admin.password.force.edit'))
        ->assertForbidden();
});
