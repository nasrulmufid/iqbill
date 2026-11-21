<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Notification\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly Customer $customer,
        public readonly string $templateType,
        public readonly ?Invoice $invoice = null
    ) {
    }

    public function handle(WhatsAppService $whatsApp): void
    {
        $whatsApp->sendTemplate($this->customer, $this->templateType, $this->invoice);
    }
}
