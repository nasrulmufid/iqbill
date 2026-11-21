<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'public_token',
        'subscription_id',
        'customer_id',
        'billing_period_start',
        'billing_period_end',
        'subtotal',
        'tax_amount',
        'gateway_fee_charged',
        'total_amount',
        'payment_attempt_count',
        'status',
        'due_date',
        'paid_at',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gateway_fee_charged' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_attempt_count' => 'integer',
            'status' => InvoiceStatus::class,
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function whatsappNotificationLogs(): HasMany
    {
        return $this->hasMany(WhatsappNotificationLog::class);
    }
}
