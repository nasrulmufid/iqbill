<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\PaymentWebhookLog;
use App\Services\Payment\SumopodWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySumopodWebhook
{
    public function __construct(
        private readonly SumopodWebhookVerifier $verifier
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $settings = CompanySetting::current();

        $result = $this->verifier->verify(
            $request,
            $settings->sumopod_webhook_verification_mode,
            $settings->sumopod_signing_secret_encrypted,
            $settings->sumopod_webhook_token_encrypted
        );

        if (!$result['valid']) {
            $rawBody = $request->getContent();
            $rawLength = strlen($rawBody);
            $decoded = $rawLength <= 65536 ? json_decode($rawBody, true) : null;

            $extractString = static function (mixed $value, int $maxLength): ?string {
                if (!is_scalar($value)) {
                    return null;
                }
                $str = trim((string) $value);

                return $str === '' ? null : mb_substr($str, 0, $maxLength);
            };

            $externalRef = is_array($decoded) ? $extractString(data_get($decoded, 'data.payment_id'), 100) : null;
            $paymentCode = is_array($decoded) ? $extractString(data_get($decoded, 'data.order_id'), 60) : null;
            $eventType = (is_array($decoded) ? $extractString(data_get($decoded, 'event_type'), 60) : null) ?? 'unknown';

            $loggedPayload = [
                'truncated' => $rawLength > 8192,
                'byte_length' => $rawLength,
                'preview' => substr($rawBody, 0, 8192),
            ];

            PaymentWebhookLog::query()->create([
                'svix_id' => null,
                'external_reference' => $externalRef,
                'payment_code' => $paymentCode,
                'event_type' => $eventType,
                'verification_method' => $result['method'],
                'signature_valid' => false,
                'http_status_returned' => 401,
                'processing_status' => 'rejected_invalid_auth',
                'error_message' => $result['reason'],
                'headers_json' => [
                    'svix-id' => $extractString($request->header('svix-id'), 120),
                    'svix-timestamp' => $extractString($request->header('svix-timestamp'), 40),
                    'user-agent' => $extractString($request->userAgent(), 200),
                ],
                'payload_json' => $loggedPayload,
                'processed_at' => now(),
                'created_at' => now(),
            ]);

            return response()->json([
                'received' => false,
                'error' => 'invalid_webhook_signature',
            ], 401);
        }

        $request->attributes->set('webhook_verification_method', $result['method']);

        return $next($request);
    }
}
