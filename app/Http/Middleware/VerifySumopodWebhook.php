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
            $payload = json_decode($request->getContent(), true);
            if (!is_array($payload)) {
                $payload = ['raw' => $request->getContent()];
            }

            PaymentWebhookLog::query()->create([
                'svix_id' => null,
                'external_reference' => data_get($payload, 'data.payment_id'),
                'payment_code' => data_get($payload, 'data.order_id'),
                'event_type' => (string) data_get($payload, 'event_type', 'unknown'),
                'verification_method' => $result['method'],
                'signature_valid' => false,
                'http_status_returned' => 401,
                'processing_status' => 'rejected_invalid_auth',
                'error_message' => $result['reason'],
                'headers_json' => [
                    'svix-id' => $request->header('svix-id'),
                    'svix-timestamp' => $request->header('svix-timestamp'),
                    'user-agent' => $request->userAgent(),
                ],
                'payload_json' => $payload,
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
