<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PppoeUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'mikrotik_router_id',
        'session_id',
        'address',
        'rx_bytes_delta',
        'tx_bytes_delta',
        'last_counter_rx',
        'last_counter_tx',
        'uptime_seconds',
        'recorded_date',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'rx_bytes_delta' => 'integer',
            'tx_bytes_delta' => 'integer',
            'last_counter_rx' => 'integer',
            'last_counter_tx' => 'integer',
            'uptime_seconds' => 'integer',
            'recorded_date' => 'date',
            'recorded_at' => 'datetime',
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
