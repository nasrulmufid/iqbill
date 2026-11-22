<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Models\MikrotikBackup;
use App\Models\MikrotikRouter;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MikrotikBackupService
{
    public const ENCRYPTED_PREFIX = 'IQBILL-ENC-V1:';

    public function __construct(
        private readonly RouterOsClientFactory $clientFactory
    ) {
    }

    public function createRscBackup(MikrotikRouter $router, ?User $actor = null, ?string $notes = null): MikrotikBackup
    {
        $client = $this->clientFactory->make($router);
        $rscContent = $client->exportRsc();

        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($router->name)) ?: 'router';
        $suffix = Str::lower(Str::random(6));
        $filename = sprintf('%s-%s-%s.rsc', $safeSlug, now('Asia/Jakarta')->format('Ymd-His'), $suffix);
        $relativePath = "mikrotik-backups/{$router->id}/{$filename}";

        Storage::disk('local')->put($relativePath, self::ENCRYPTED_PREFIX . Crypt::encryptString($rscContent));

        return MikrotikBackup::query()->create([
            'mikrotik_router_id' => $router->id,
            'backup_type' => 'export_rsc',
            'filename' => $filename,
            'file_path' => $relativePath,
            'file_size_bytes' => strlen($rscContent),
            'notes' => $notes,
            'created_by_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    public function readBackupContents(string $rawStoredContent): string
    {
        if (str_starts_with($rawStoredContent, self::ENCRYPTED_PREFIX)) {
            return Crypt::decryptString(substr($rawStoredContent, strlen(self::ENCRYPTED_PREFIX)));
        }

        return $rawStoredContent;
    }
}
