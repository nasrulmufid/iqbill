<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CompanySetting;
use Illuminate\Console\Command;

class SyncGowaConfigCommand extends Command
{
    protected $signature = 'gowa:sync-config {--force : Overwrite existing Go-WhatsApp settings in the database}';

    protected $description = 'Synchronize Go-WhatsApp (GOWA) connection settings from environment variables into company settings';

    public function handle(): int
    {
        $url = trim((string) config('services.gowa.url', ''));
        if ($url === '') {
            $this->info('GOWA_API_URL is not configured; skipping Go-WhatsApp synchronization.');

            return self::SUCCESS;
        }

        $settings = CompanySetting::current();
        $shouldForce = (bool) $this->option('force') || (bool) config('services.gowa.force_sync', false);

        if (!empty($settings->wa_gateway_url) && !$shouldForce) {
            $this->info("Go-WhatsApp gateway URL is already configured ({$settings->wa_gateway_url}); preserving existing settings.");

            return self::SUCCESS;
        }

        $updates = [
            'wa_gateway_url' => rtrim($url, '/'),
        ];

        $user = trim((string) config('services.gowa.basic_auth_user', ''));
        $password = trim((string) config('services.gowa.basic_auth_password', ''));
        $deviceId = trim((string) config('services.gowa.device_id', ''));

        if ($user !== '' || $shouldForce) {
            $updates['wa_basic_auth_user'] = $user !== '' ? $user : null;
        }

        if ($password !== '' || $shouldForce) {
            $updates['wa_basic_auth_password_encrypted'] = $password !== '' ? $password : null;
        }

        if ($deviceId !== '' || $shouldForce) {
            $updates['wa_device_id'] = $deviceId !== '' ? $deviceId : null;
        }

        $settings->update($updates);

        $this->info("Synchronized Go-WhatsApp gateway URL ({$updates['wa_gateway_url']}).");

        return self::SUCCESS;
    }
}
