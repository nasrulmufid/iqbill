<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikProvisioningLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'subscription_id',
        'mikrotik_router_id',
        'action',
        'trigger_source',
        'is_success',
        'response_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function mikrotikRouter(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class);
    }
}
