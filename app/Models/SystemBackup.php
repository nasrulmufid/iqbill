<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemBackup extends Model
{
    public const UPDATED_AT = null;

    public const STORAGE_PREFIX = 'private/system-backups/';

    protected $fillable = [
        'scope',
        'trigger_source',
        'filename',
        'file_path',
        'file_size_bytes',
        'checksum_sha256',
        'encryption_cipher',
        'tables_count',
        'records_count',
        'app_version',
        'notes',
        'telegram_sent_at',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'tables_count' => 'integer',
            'records_count' => 'integer',
            'telegram_sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isScheduled(): bool
    {
        return $this->trigger_source === 'scheduled';
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function hasValidStoragePath(): bool
    {
        $path = (string) $this->file_path;

        return str_starts_with($path, self::STORAGE_PREFIX)
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0")
            && preg_match('/^private\/system-backups\/[a-zA-Z0-9._-]+\.iqb$/', $path) === 1;
    }

    public function isFullBackup(): bool
    {
        return $this->scope === 'full';
    }
}
