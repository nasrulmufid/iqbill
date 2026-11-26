<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('ADMIN_PASSWORD', 'password');

        if (app()->environment('production') && ($password === 'password' || strlen($password) < 12)) {
            throw new \RuntimeException(
                'Set ADMIN_PASSWORD (minimum 12 characters) in .env before running the seeder in production.'
            );
        }

        $email = (string) env('ADMIN_EMAIL', 'admin@iqbill.local');
        $existing = User::query()->where('email', $email)->first();

        $requireFirstChange = filter_var(
            env('ADMIN_REQUIRE_PASSWORD_CHANGE', true),
            FILTER_VALIDATE_BOOLEAN
        );

        // Enforce mandatory password change only on initial first-time deployment
        // when no Super Admin has ever completed initial password setup yet.
        $alreadyCompletedInitialSetup = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->whereNotNull('password_changed_at')
            ->exists();

        $attributes = [
            'name' => 'Super Administrator',
            'phone' => (string) env('ADMIN_PHONE', '6281100000000'),
            'password' => Hash::make($password),
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'email_verified_at' => now(),
        ];

        if ($existing === null) {
            $attributes['must_change_password'] = $requireFirstChange && !$alreadyCompletedInitialSetup;
            $attributes['password_changed_at'] = null;

            User::query()->create(array_merge(['email' => $email], $attributes));

            return;
        }

        $existing->update($attributes);
    }
}
