<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Notification\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly string $type,
        public readonly ?int $paymentId = null,
        public readonly ?string $context = null,
        public readonly ?string $errorMessage = null
    ) {
    }

    public static function forPayment(Payment $payment): PendingDispatch
    {
        return self::dispatch('payment', $payment->id);
    }

    public static function forError(string $context, string $errorMessage): PendingDispatch
    {
        return self::dispatch('error', null, $context, $errorMessage);
    }

    public function handle(TelegramNotificationService $telegram): void
    {
        if ($this->type === 'payment' && $this->paymentId !== null) {
            $payment = Payment::query()->find($this->paymentId);
            if ($payment !== null) {
                $telegram->notifyPaymentReceived($payment);
            }

            return;
        }

        if ($this->type === 'error' && $this->context !== null && $this->errorMessage !== null) {
            $telegram->notifyError($this->context, $this->errorMessage);
        }
    }
}
