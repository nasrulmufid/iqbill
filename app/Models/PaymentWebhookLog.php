<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'svix_id',
        'external_reference',
        'payment_code',
        'event_type',
        'verification_method',
        'signature_valid',
        'http_status_returned',
        'processing_status',
        'error_message',
        'headers_json',
        'payload_json',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'http_status_returned' => 'integer',
            'headers_json' => 'array',
            'payload_json' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
