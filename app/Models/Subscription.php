<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'package_id',
        'mikrotik_router_id',
        'service_username',
        'service_password_encrypted',
        'static_ip_address',
        'isolir_profile_name',
        'billing_day',
        'isolir_day',
        'auto_isolate',
        'next_billing_date',
        'status',
        'activated_at',
        'isolated_at',
        'mikrotik_sync_status',
        'mikrotik_pending_action',
        'mikrotik_pending_notify',
        'mikrotik_pending_invoice_id',
        'mikrotik_sync_attempts',
        'mikrotik_last_sync_error',
        'mikrotik_last_synced_at',
    ];

    protected $hidden = [
        'service_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'service_password_encrypted' => 'encrypted',
            'billing_day' => 'integer',
            'isolir_day' => 'integer',
            'auto_isolate' => 'boolean',
            'next_billing_date' => 'date',
            'status' => SubscriptionStatus::class,
            'activated_at' => 'datetime',
            'isolated_at' => 'datetime',
            'mikrotik_pending_notify' => 'boolean',
            'mikrotik_sync_attempts' => 'integer',
            'mikrotik_last_synced_at' => 'datetime',
        ];
    }

    public function hasPendingMikrotikSync(): bool
    {
        return in_array($this->mikrotik_sync_status ?? 'synced', ['pending', 'failed'], true);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(PppoeUsageLog::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function mikrotikRouter(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(MikrotikProvisioningLog::class);
    }
}
