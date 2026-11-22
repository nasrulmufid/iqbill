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

    public static function isValidHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || strlen($host) > 120 || str_starts_with($host, '-')) {
            return false;
        }

        if (preg_match('/[\s:\/?#@\\\\]/', $host) === 1) {
            return false;
        }

        $lower = strtolower($host);
        $blockedHosts = [
            'localhost',
            'localhost.localdomain',
            'host.docker.internal',
            'metadata.google.internal',
            'gowa',
            'db',
            'mysql',
            'redis',
            'app',
            'web',
            'queue',
            'scheduler',
            'iqbill_gowa',
            'iqbill_db',
            'iqbill_redis',
            'iqbill_app',
            'iqbill_web',
            'iqbill_queue',
            'iqbill_scheduler',
        ];
        if (in_array($lower, $blockedHosts, true) || str_ends_with($lower, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($host);
            if ($long === false) {
                return false;
            }
            $octet1 = ($long >> 24) & 0xFF;
            $octet2 = ($long >> 16) & 0xFF;

            // Block unspecified (0.0.0.0/8), loopback (127.0.0.0/8), and link-local/cloud metadata (169.254.0.0/16)
            if ($octet1 === 0 || $octet1 === 127 || ($octet1 === 169 && $octet2 === 254)) {
                return false;
            }

            return true;
        }

        return preg_match(
            '/^(?=.{1,120}$)[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
            $host
        ) === 1;
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{0,79}$/', $username) === 1;
    }
}
