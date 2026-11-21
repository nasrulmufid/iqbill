<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappBroadcast extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'target_segment',
        'segment_filters',
        'template_key',
        'message_body',
        'dispatch_mode',
        'delay_seconds',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'segment_filters' => 'array',
            'delay_seconds' => 'integer',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WhatsappNotificationLog::class, 'broadcast_id');
    }

    public function processedCount(): int
    {
        return $this->sent_count + $this->failed_count;
    }

    public function progressPercent(): int
    {
        if ($this->total_recipients <= 0) {
            return 100;
        }

        return min(100, (int) round(($this->processedCount() / $this->total_recipients) * 100));
    }
}
