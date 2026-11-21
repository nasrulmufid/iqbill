<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\CompanySetting;
use App\Models\Customer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * REST API integration for aldinokemal/go-whatsapp-web-multidevice (GOWA).
 */
class GowaClient
{
    public function sendMessage(string $phone, string $message): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return [
                'success' => false,
                'response' => 'Go-WhatsApp Base URL is not configured.',
            ];
        }

        $normalizedPhone = Customer::normalizeWhatsappPhone($phone) ?: $phone;

        try {
            $response = $this->http($settings)
                ->asJson()
                ->post($this->endpoint($settings, '/send/message'), [
                    'phone' => $normalizedPhone,
                    'message' => $message,
                ]);

            return [
                'success' => $response->successful(),
                'response' => sprintf('HTTP %d: %s', $response->status(), $response->body()),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'response' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, devices: array<int, array<string, mixed>>, error: ?string}
     */
    public function getDevices(): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'devices' => [], 'error' => 'Go-WhatsApp Base URL is not set.'];
        }

        try {
            $response = $this->http($settings)->acceptJson()->get($this->endpoint($settings, '/app/devices'));
            if (!$response->successful()) {
                return ['ok' => false, 'devices' => [], 'error' => 'HTTP ' . $response->status() . ': ' . $response->body()];
            }

            $json = $response->json();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];

            return ['ok' => true, 'devices' => $results, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'devices' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch the login QR Code from GOWA /app/login securely through the backend.
     *
     * @return array{ok: bool, qr_data_uri: ?string, qr_duration: int, message: ?string, error: ?string}
     */
    public function getLoginQr(): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'qr_data_uri' => null, 'qr_duration' => 30, 'message' => null, 'error' => 'Go-WhatsApp Base URL is not set.'];
        }

        try {
            $response = $this->http($settings)->get($this->endpoint($settings, '/app/login'));
            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'qr_data_uri' => null,
                    'qr_duration' => 30,
                    'message' => null,
                    'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                ];
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if (str_contains($contentType, 'image/')) {
                $mime = explode(';', $contentType)[0];

                return [
                    'ok' => true,
                    'qr_data_uri' => 'data:' . $mime . ';base64,' . base64_encode($response->body()),
                    'qr_duration' => 30,
                    'message' => 'Please scan this QR code using the WhatsApp mobile app.',
                    'error' => null,
                ];
            }

            $json = $response->json();
            $qrLink = $json['results']['qr_link'] ?? null;
            $duration = (int) ($json['results']['qr_duration'] ?? 30);
            $qrDataUri = null;

            if (is_string($qrLink) && $qrLink !== '') {
                $resolvedQrUrl = $this->resolveAssetUrl($settings, $qrLink);
                $imgResp = $this->http($settings)->get($resolvedQrUrl);
                if ($imgResp->successful()) {
                    $imgMime = explode(';', strtolower((string) ($imgResp->header('Content-Type') ?: 'image/png')))[0];
                    $qrDataUri = 'data:' . $imgMime . ';base64,' . base64_encode($imgResp->body());
                }
            }

            return [
                'ok' => true,
                'qr_data_uri' => $qrDataUri,
                'qr_duration' => $duration,
                'message' => (string) ($json['message'] ?? 'Please scan this QR code using the WhatsApp mobile app.'),
                'error' => $qrDataUri === null ? 'Unable to load QR code image from the GOWA server.' : null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'qr_data_uri' => null, 'qr_duration' => 30, 'message' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, code: ?string, message: string}
     */
    public function loginWithCode(string $phone): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'code' => null, 'message' => 'Go-WhatsApp Base URL is not set.'];
        }

        try {
            $response = $this->http($settings)->acceptJson()->get(
                $this->endpoint($settings, '/app/login-with-code'),
                ['phone' => preg_replace('/[^0-9]/', '', $phone)]
            );
            $json = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'code' => $json['results']['pair_code'] ?? $json['results']['code'] ?? null,
                'message' => (string) ($json['message'] ?? $response->body()),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function reconnect(): array
    {
        return $this->simpleGet('/app/reconnect');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function logout(): array
    {
        return $this->simpleGet('/app/logout');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function simpleGet(string $path): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'message' => 'Go-WhatsApp Base URL is not set.'];
        }

        try {
            $response = $this->http($settings)->acceptJson()->get($this->endpoint($settings, $path));
            $json = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'message' => (string) ($json['message'] ?? 'HTTP ' . $response->status()),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function endpoint(CompanySetting $settings, string $path): string
    {
        return rtrim((string) $settings->wa_gateway_url, '/') . $path;
    }

    /**
     * Rewrite relative paths or loopback URLs returned by GOWA (e.g. localhost:3000)
     * to the configured internal gateway origin (e.g. http://gowa:3000 in Docker).
     */
    private function resolveAssetUrl(CompanySetting $settings, string $url): string
    {
        $base = rtrim((string) $settings->wa_gateway_url, '/');
        if ($base === '') {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return $url;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            $path = (string) ($parsed['path'] ?? '/');
            $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';

            return $base . $path . $query;
        }

        return $url;
    }

    private function http(CompanySetting $settings): PendingRequest
    {
        $request = Http::timeout(10);

        if (!empty($settings->wa_basic_auth_user)) {
            $request = $request->withBasicAuth(
                $settings->wa_basic_auth_user,
                (string) $settings->wa_basic_auth_password_encrypted
            );
        } elseif (!empty($settings->wa_api_token_encrypted)) {
            $request = $request->withToken($settings->wa_api_token_encrypted);
        }

        if (!empty($settings->wa_device_id)) {
            $request = $request->withHeaders(['X-Device-Id' => $settings->wa_device_id]);
        }

        return $request;
    }
}
