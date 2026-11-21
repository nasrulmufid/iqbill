<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappNotificationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'broadcast_id',
        'template_type',
        'recipient_phone',
        'message_body',
        'delivery_status',
        'gateway_response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(WhatsappBroadcast::class, 'broadcast_id');
    }
}
