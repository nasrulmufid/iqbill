<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikBackup extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'mikrotik_router_id',
        'backup_type',
        'filename',
        'file_path',
        'file_size_bytes',
        'notes',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function mikrotikRouter(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
