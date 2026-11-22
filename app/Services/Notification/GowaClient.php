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

    private const MAX_QR_IMAGE_BYTES = 2 * 1024 * 1024;

    private const ALLOWED_QR_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

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
                \Illuminate\Support\Facades\Log::warning('GOWA getDevices failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['ok' => false, 'devices' => [], 'error' => 'Go-WhatsApp gateway returned HTTP ' . $response->status() . '.'];
            }

            $json = $response->json();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];

            return ['ok' => true, 'devices' => $results, 'error' => null];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('GOWA getDevices exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'devices' => [], 'error' => 'Unable to reach Go-WhatsApp gateway.'];
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
                \Illuminate\Support\Facades\Log::warning('GOWA getLoginQr failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return [
                    'ok' => false,
                    'qr_data_uri' => null,
                    'qr_duration' => 30,
                    'message' => null,
                    'error' => 'Go-WhatsApp gateway returned HTTP ' . $response->status() . '.',
                ];
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $mime = trim(explode(';', $contentType)[0]);
            if (in_array($mime, self::ALLOWED_QR_MIMES, true)) {
                $body = $response->body();
                if (strlen($body) > self::MAX_QR_IMAGE_BYTES) {
                    return [
                        'ok' => false,
                        'qr_data_uri' => null,
                        'qr_duration' => 30,
                        'message' => null,
                        'error' => 'QR code image exceeds maximum allowed size.',
                    ];
                }

                return [
                    'ok' => true,
                    'qr_data_uri' => 'data:' . $mime . ';base64,' . base64_encode($body),
                    'qr_duration' => 30,
                    'message' => 'Please scan this QR code using the WhatsApp mobile app.',
                    'error' => null,
                ];
            }

            $json = $response->json();
            $qrLink = $json['results']['qr_link'] ?? null;
            $duration = (int) ($json['results']['qr_duration'] ?? 30);
            $qrDataUri = null;

            if (is_string($qrLink) && trim($qrLink) !== '') {
                $resolvedQrUrl = $this->resolveAssetUrl($settings, trim($qrLink));
                if ($resolvedQrUrl !== null) {
                    $imgResp = $this->http($settings)->get($resolvedQrUrl);
                    $imgMime = trim(explode(';', strtolower((string) $imgResp->header('Content-Type')))[0]);
                    $imgBody = $imgResp->body();

                    if (
                        $imgResp->successful()
                        && in_array($imgMime, self::ALLOWED_QR_MIMES, true)
                        && strlen($imgBody) > 0
                        && strlen($imgBody) <= self::MAX_QR_IMAGE_BYTES
                    ) {
                        $qrDataUri = 'data:' . $imgMime . ';base64,' . base64_encode($imgBody);
                    }
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
            \Illuminate\Support\Facades\Log::warning('GOWA getLoginQr exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'qr_data_uri' => null, 'qr_duration' => 30, 'message' => null, 'error' => 'Unable to load QR code from Go-WhatsApp gateway.'];
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
     * to the configured internal gateway origin (e.g. http://gowa:3000 in Docker),
     * while rejecting any third-party or credential-bearing URLs.
     */
    public function resolveAssetUrl(CompanySetting $settings, string $url): ?string
    {
        $base = rtrim((string) $settings->wa_gateway_url, '/');
        if ($base === '' || $url === '' || str_starts_with($url, '//') || preg_match('/[\r\n]/', $url) === 1) {
            return null;
        }

        $baseParsed = parse_url($base);
        if (!is_array($baseParsed) || empty($baseParsed['scheme']) || empty($baseParsed['host'])) {
            return null;
        }

        $baseScheme = strtolower((string) $baseParsed['scheme']);
        if (!in_array($baseScheme, ['http', 'https'], true)) {
            return null;
        }

        $baseHost = strtolower((string) $baseParsed['host']);
        $basePort = (int) ($baseParsed['port'] ?? ($baseScheme === 'https' ? 443 : 80));

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        $path = '/' . ltrim((string) ($parsed['path'] ?? '/'), '/');
        $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)) {
            return $base . $path . $query;
        }

        $urlPort = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($scheme === $baseScheme && $host === $baseHost && $urlPort === $basePort) {
            return $base . $path . $query;
        }

        return null;
    }

    private function http(CompanySetting $settings): PendingRequest
    {
        $request = Http::timeout(10)->withOptions(['allow_redirects' => false]);

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
