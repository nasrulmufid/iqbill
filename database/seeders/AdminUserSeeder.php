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
        $password = trim((string) env('ADMIN_PASSWORD', ''));

        if (app()->environment('production')) {
            $lowerPassword = strtolower($password);

            if (
                $password === ''
                || $lowerPassword === 'password'
                || str_starts_with($lowerPassword, 'change_me')
                || strlen($password) < 12
            ) {
                throw new \RuntimeException(
                    'Set a strong, non-placeholder ADMIN_PASSWORD (minimum 12 characters) in .env before running the seeder in production.'
                );
            }
        } elseif ($password === '') {
            $password = 'password';
        }

        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@iqbill.local')],
            [
                'name' => 'Super Administrator',
                'phone' => env('ADMIN_PHONE', '6281100000000'),
                'password' => Hash::make((string) $password),
                'role' => UserRole::SuperAdmin,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
