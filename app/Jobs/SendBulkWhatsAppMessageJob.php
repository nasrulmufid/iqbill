<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WhatsappNotificationLog;
use App\Services\Notification\WhatsappBroadcastService;
use App\Services\Notification\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendBulkWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $logId,
        public readonly int $broadcastId
    ) {
        $this->onQueue('broadcasts');
    }

    public function handle(WhatsAppService $whatsApp, WhatsappBroadcastService $broadcastService): void
    {
        $log = WhatsappNotificationLog::query()->find($this->logId);
        if (!$log || $log->delivery_status === 'sent') {
            return;
        }

        $whatsApp->deliverExistingLog($log, 'bulk_broadcast');
        $broadcastService->recordJobOutcome($this->broadcastId, $log->delivery_status === 'sent');
    }

    public function failed(Throwable $exception): void
    {
        $log = WhatsappNotificationLog::query()->find($this->logId);
        if (!$log || $log->delivery_status !== 'queued') {
            return;
        }

        $log->forceFill([
            'delivery_status' => 'failed',
            'gateway_response' => $exception->getMessage(),
        ])->save();

        app(WhatsappBroadcastService::class)->recordJobOutcome($this->broadcastId, false);
    }
}
