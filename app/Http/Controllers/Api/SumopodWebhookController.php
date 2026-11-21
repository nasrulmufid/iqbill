<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookLog;
use App\Services\Payment\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SumopodWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $verificationMethod = (string) $request->attributes->get('webhook_verification_method', 'none');

        if (!is_array($payload)) {
            PaymentWebhookLog::query()->create([
                'svix_id' => $request->header('svix-id') ?: null,
                'event_type' => 'invalid_json',
                'verification_method' => $verificationMethod,
                'signature_valid' => true,
                'http_status_returned' => 422,
                'processing_status' => 'failed',
                'error_message' => 'Malformed JSON payload.',
                'payload_json' => ['raw' => $request->getContent()],
                'processed_at' => now(),
                'created_at' => now(),
            ]);

            return response()->json([
                'received' => false,
                'error' => 'invalid_payload',
            ], 422);
        }

        $result = $this->settlementService->handle($request, $payload, $verificationMethod);

        return response()->json($result['body'], $result['http_status']);
    }
}
