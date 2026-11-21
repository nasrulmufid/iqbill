<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\SumopodException;
use App\Models\CompanySetting;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SumopodGatewayService
{
    /**
     * Create a dynamic QRIS payment session on Sumopod.
     *
     * @return array{
     *     payment_id: string,
     *     order_id: string,
     *     amount: int|float,
     *     fee?: int|float,
     *     net_amount?: int|float,
     *     currency: string,
     *     payment_method_type_code: string,
     *     payment_code: string,
     *     payment_link_url: string,
     *     expires_at: string,
     *     status: string
     * }
     */
    public function createQrisPayment(string $paymentCode, Invoice $invoice): array
    {
        $settings = CompanySetting::current();
        $apiKey = $settings->sumopod_api_key_encrypted;

        if (empty($apiKey)) {
            throw new SumopodException('Sumopod API key is not configured.');
        }

        $baseUrl = rtrim($settings->sumopodBaseUrl(), '/');

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                ])
                ->post("{$baseUrl}/payments", [
                    'order_id' => $paymentCode,
                    'amount' => (int) round((float) $invoice->total_amount),
                    'currency' => 'IDR',
                    'payment_method_type_code' => 'QRIS',
                ]);
        } catch (ConnectionException $e) {
            throw new SumopodException('Failed to connect to Sumopod API: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new SumopodException(
                sprintf('Sumopod API returned HTTP %d: %s', $response->status(), $response->body())
            );
        }

        $data = $response->json();

        if (
            !is_array($data)
            || empty($data['payment_id'])
            || empty($data['payment_code'])
            || empty($data['payment_link_url'])
            || empty($data['expires_at'])
        ) {
            throw new SumopodException('Sumopod API response is missing required payment fields.');
        }

        return $data;
    }
}
