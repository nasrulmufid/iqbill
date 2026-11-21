<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'attempt_number',
        'payment_code',
        'external_reference',
        'gateway',
        'amount',
        'gateway_fee',
        'net_amount',
        'qris_string',
        'payment_url',
        'qris_expires_at',
        'status',
        'paid_at',
        'settled_at',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'qris_expires_at' => 'datetime',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isActiveQrisSession(int $bufferMinutes = 2): bool
    {
        return $this->status === PaymentStatus::Pending
            && $this->qris_expires_at !== null
            && $this->qris_expires_at->greaterThan(now()->addMinutes($bufferMinutes));
    }
}
