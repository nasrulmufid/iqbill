<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MikrotikRouter extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ros_version_type',
        'host',
        'api_port',
        'rest_port',
        'ssh_port',
        'use_ssl',
        'username',
        'password_encrypted',
        'isolir_address_list',
        'isolir_profile_name',
        'is_active',
        'is_reachable',
        'last_health_check_at',
        'last_health_error',
        'ros_version',
        'default_firewall_installed_at',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'api_port' => 'integer',
            'rest_port' => 'integer',
            'ssh_port' => 'integer',
            'use_ssl' => 'boolean',
            'password_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'is_reachable' => 'boolean',
            'last_health_check_at' => 'datetime',
            'default_firewall_installed_at' => 'datetime',
        ];
    }

    public function backups(): HasMany
    {
        return $this->hasMany(MikrotikBackup::class);
    }

    public function isRouterOs7(): bool
    {
        return $this->ros_version_type === 'v7';
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'mikrotik_router_package')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(MikrotikProvisioningLog::class);
    }
}
