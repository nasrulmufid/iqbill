<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\CompanySetting;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramNotificationService
{
    public function isConfigured(): bool
    {
        $settings = CompanySetting::current();

        return $settings->telegram_enabled
            && !empty($settings->telegram_bot_token_encrypted)
            && !empty($settings->telegram_chat_id);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendMessage(string $htmlMessage): array
    {
        $settings = CompanySetting::current();

        if (!$settings->telegram_enabled || empty($settings->telegram_bot_token_encrypted) || empty($settings->telegram_chat_id)) {
            return ['ok' => false, 'message' => 'Telegram Bot is not enabled or configured.'];
        }

        try {
            $url = "https://api.telegram.org/bot{$settings->telegram_bot_token_encrypted}/sendMessage";
            $response = Http::timeout(8)->asJson()->post($url, [
                'chat_id' => $settings->telegram_chat_id,
                'text' => $htmlMessage,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['ok' => false, 'message' => 'Telegram API returned HTTP ' . $response->status() . '.'];
            }

            return ['ok' => true, 'message' => 'Telegram message sent.'];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Telegram sendMessage exception', [
                'error' => $this->redactBotToken($e->getMessage()),
            ]);

            return ['ok' => false, 'message' => 'Failed to reach Telegram API.'];
        }
    }

    /**
     * @return array{ok: bool, bot_username: ?string, message: string}
     */
    public function testConnection(): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->telegram_bot_token_encrypted) || empty($settings->telegram_chat_id)) {
            return ['ok' => false, 'bot_username' => null, 'message' => 'Bot token or Chat ID is not configured.'];
        }

        try {
            $me = Http::timeout(8)
                ->get("https://api.telegram.org/bot{$settings->telegram_bot_token_encrypted}/getMe");

            if (!$me->successful()) {
                \Illuminate\Support\Facades\Log::warning('Telegram getMe failed', [
                    'status' => $me->status(),
                    'body' => mb_substr($me->body(), 0, 500),
                ]);

                return ['ok' => false, 'bot_username' => null, 'message' => 'Invalid bot token or Telegram API rejected request (HTTP ' . $me->status() . ').'];
            }

            $username = $me->json('result.username');
            $sendUrl = "https://api.telegram.org/bot{$settings->telegram_bot_token_encrypted}/sendMessage";
            $send = Http::timeout(8)->asJson()->post($sendUrl, [
                'chat_id' => $settings->telegram_chat_id,
                'text' => '<b>[' . e((string) $settings->company_name) . ']</b> Telegram Bot connection test succeeded.',
                'parse_mode' => 'HTML',
            ]);

            if (!$send->successful()) {
                \Illuminate\Support\Facades\Log::warning('Telegram testConnection sendMessage failed', [
                    'status' => $send->status(),
                    'body' => mb_substr($send->body(), 0, 500),
                ]);
            }

            return [
                'ok' => $send->successful(),
                'bot_username' => is_string($username) ? $username : null,
                'message' => $send->successful()
                    ? "Connected to @{$username} and test message delivered."
                    : 'Failed to send test message to configured chat_id (HTTP ' . $send->status() . ').',
            ];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Telegram testConnection exception', [
                'error' => $this->redactBotToken($e->getMessage()),
            ]);

            return ['ok' => false, 'bot_username' => null, 'message' => 'Failed to reach Telegram API.'];
        }
    }

    public function notifyPaymentReceived(Payment $payment): void
    {
        $settings = CompanySetting::current();
        if (!$settings->telegram_enabled || !$settings->telegram_notify_payment) {
            return;
        }

        $payment->loadMissing(['invoice.customer', 'invoice.subscription.package']);
        $invoice = $payment->invoice;
        $customer = $invoice?->customer;
        $methodLabel = $payment->gateway === 'manual_cash' ? 'Manual Cash' : 'Sumopod QRIS';

        $text = implode("\n", [
            "<b>Payment Received ({$methodLabel})</b>",
            'Invoice: <code>' . e((string) ($invoice?->invoice_number ?? '-')) . '</code>',
            'Payment Code: <code>' . e($payment->payment_code) . '</code>',
            'Customer: ' . e((string) ($customer?->full_name ?? '-')),
            'Package: ' . e((string) ($invoice?->subscription?->package?->name ?? '-')),
            'Amount: <b>Rp ' . number_format((float) $payment->amount, 0, ',', '.') . '</b>',
            'Net: Rp ' . number_format((float) $payment->net_amount, 0, ',', '.'),
            'Time: ' . now('Asia/Jakarta')->format('d M Y H:i') . ' WIB',
        ]);

        $this->sendMessage($text);
    }

    public function notifyError(string $context, string $errorMessage): void
    {
        $settings = CompanySetting::current();
        if (!$settings->telegram_enabled || !$settings->telegram_notify_error) {
            return;
        }

        $normalizedForDedup = (string) preg_replace('/\b[0-9]{8,16}\b/', '<phone>', $errorMessage);
        $dedupKey = 'tg_err:' . md5($context . '|' . $normalizedForDedup);
        if (!Cache::add($dedupKey, 1, 300)) {
            return;
        }

        $truncated = mb_substr($this->redactBotToken($errorMessage), 0, 800);
        $text = implode("\n", [
            '<b>[ERROR ALERT] ' . e((string) $settings->company_name) . '</b>',
            'Source: <code>' . e($context) . '</code>',
            'Message: <pre>' . e($truncated) . '</pre>',
            'Time: ' . now('Asia/Jakarta')->format('d M Y H:i:s') . ' WIB',
        ]);

        $this->sendMessage($text);
    }

    private function redactBotToken(string $message): string
    {
        return (string) preg_replace('#/bot[^/\s]+#i', '/bot[REDACTED]', $message);
    }
}
