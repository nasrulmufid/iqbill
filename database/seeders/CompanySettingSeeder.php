<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    public function run(): void
    {
        $payload = [
            'company_name' => env('ISP_COMPANY_NAME', 'IQ Bill'),
            'company_address' => env('ISP_COMPANY_ADDRESS', 'Jl. Jend. Sudirman No. 1, Jakarta'),
            'company_phone' => env('ISP_COMPANY_PHONE', '6281100000001'),
            'invoice_prefix' => 'INV',
            'payment_prefix' => 'PAY',
            'default_due_days' => 7,
            'grace_period_days' => 3,
            'pregenerate_qris_on_invoice' => true,
            'sumopod_environment' => env('SUMOPOD_ENVIRONMENT', 'sandbox'),
            'sumopod_webhook_verification_mode' => 'auto',
            'sumopod_fee_bearer' => 'merchant',
        ];

        $gowaUrl = trim((string) config('services.gowa.url', ''));
        if ($gowaUrl !== '') {
            $payload['wa_gateway_url'] = rtrim($gowaUrl, '/');
            $gowaUser = trim((string) config('services.gowa.basic_auth_user', ''));
            $gowaPass = trim((string) config('services.gowa.basic_auth_password', ''));
            $gowaDevice = trim((string) config('services.gowa.device_id', ''));

            if ($gowaUser !== '') {
                $payload['wa_basic_auth_user'] = $gowaUser;
            }
            if ($gowaPass !== '') {
                $payload['wa_basic_auth_password_encrypted'] = $gowaPass;
            }
            if ($gowaDevice !== '') {
                $payload['wa_device_id'] = $gowaDevice;
            }
        }

        CompanySetting::query()->updateOrCreate(['id' => 1], $payload);
    }
}
