<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'service_type',
        'mikrotik_profile_name',
        'speed_download_mbps',
        'speed_upload_mbps',
        'price',
        'tax_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'speed_download_mbps' => 'integer',
            'speed_upload_mbps' => 'integer',
            'price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function routers(): BelongsToMany
    {
        return $this->belongsToMany(MikrotikRouter::class, 'mikrotik_router_package')
            ->withTimestamps()
            ->orderBy('name');
    }

    /**
     * If a package has not restricted specific routers, all routers are permitted.
     */
    public function supportsRouter(int $routerId): bool
    {
        if ($this->relationLoaded('routers')) {
            return $this->routers->isEmpty() || $this->routers->contains('id', $routerId);
        }

        if (!$this->routers()->exists()) {
            return true;
        }

        return $this->routers()->where('mikrotik_routers.id', $routerId)->exists();
    }

    public static function assertRouterAllowed(?int $packageId, ?int $routerId): void
    {
        if ($packageId === null || $routerId === null) {
            return;
        }

        $package = static::query()->with('routers:id,name')->find($packageId);
        if ($package === null || $package->supportsRouter($routerId)) {
            return;
        }

        $allowedNames = $package->routers->pluck('name')->implode(', ');

        throw ValidationException::withMessages([
            'mikrotik_router_id' => "The selected router does not support package {$package->name}. Please choose one of: {$allowedNames}.",
        ]);
    }
}
