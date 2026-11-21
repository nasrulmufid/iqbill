<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_code',
        'full_name',
        'nik_npwp',
        'phone_whatsapp',
        'email',
        'installation_address',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Normalize WhatsApp phone numbers into digits-only 628xxxxxxxxxx format.
     */
    public static function normalizeWhatsappPhone(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $raw)) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0062')) {
            $digits = '62' . substr($digits, 4);
        }

        if (str_starts_with($digits, '6208')) {
            $digits = '62' . substr($digits, 3);
        } elseif (str_starts_with($digits, '08')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8') && strlen($digits) >= 8) {
            $digits = '62' . $digits;
        }

        return $digits;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function whatsappNotificationLogs(): HasMany
    {
        return $this->hasMany(WhatsappNotificationLog::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function maskedName(): string
    {
        $words = preg_split('/\s+/', trim($this->full_name)) ?: [];

        return implode(' ', array_map(static function (string $word): string {
            $len = mb_strlen($word);
            if ($len <= 1) {
                return $word;
            }

            return mb_substr($word, 0, 1) . str_repeat('*', $len - 1);
        }, $words));
    }
}
