<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'code' => 'HOME-20M',
                'name' => 'Home Fiber 20 Mbps',
                'service_type' => 'pppoe',
                'mikrotik_profile_name' => 'prof-20m',
                'speed_download_mbps' => 20,
                'speed_upload_mbps' => 10,
                'price' => 150000,
                'tax_rate' => 0.00,
                'is_active' => true,
            ],
            [
                'code' => 'HOME-30M',
                'name' => 'Home Fiber 30 Mbps',
                'service_type' => 'pppoe',
                'mikrotik_profile_name' => 'prof-30m',
                'speed_download_mbps' => 30,
                'speed_upload_mbps' => 15,
                'price' => 225000,
                'tax_rate' => 0.00,
                'is_active' => true,
            ],
            [
                'code' => 'BIZ-50M',
                'name' => 'Business Fiber 50 Mbps',
                'service_type' => 'pppoe',
                'mikrotik_profile_name' => 'prof-50m',
                'speed_download_mbps' => 50,
                'speed_upload_mbps' => 50,
                'price' => 450000,
                'tax_rate' => 11.00,
                'is_active' => true,
            ],
        ];

        foreach ($packages as $package) {
            Package::query()->updateOrCreate(
                ['code' => $package['code']],
                $package
            );
        }
    }
}
