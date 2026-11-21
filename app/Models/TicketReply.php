<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReply extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'is_internal_note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
