<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\CompanySetting;
use App\Models\Customer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * REST API integration for aldinokemal/go-whatsapp-web-multidevice (GOWA v7 & v8+).
 */
class GowaClient
{
    private const MAX_QR_IMAGE_BYTES = 2 * 1024 * 1024;

    private const ALLOWED_QR_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

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

            if ($response->status() === 404) {
                $ensured = $this->ensureDeviceRegistered($settings);
                if ($ensured['ok'] && !($ensured['not_found'] ?? false)) {
                    return ['ok' => true, 'devices' => $ensured['devices'], 'error' => null];
                }

                Log::warning('GOWA getDevices 404 fallback failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                    'ensure_error' => $ensured['error'] ?? null,
                ]);

                return [
                    'ok' => false,
                    'devices' => [],
                    'error' => $ensured['error'] ?? 'Go-WhatsApp gateway returned HTTP 404.',
                ];
            }

            if (!$response->successful()) {
                Log::warning('GOWA getDevices failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['ok' => false, 'devices' => [], 'error' => 'Go-WhatsApp gateway returned HTTP ' . $response->status() . '.'];
            }

            $json = $response->json();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];

            return ['ok' => true, 'devices' => $this->normalizeDevices($results), 'error' => null];
        } catch (Throwable $e) {
            Log::warning('GOWA getDevices exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'devices' => [], 'error' => 'Unable to reach Go-WhatsApp gateway.'];
        }
    }

    /**
     * Ensure the configured (or default "iqbill") device slot exists on GOWA v8+ (/devices).
     *
     * @return array{ok: bool, device_id: ?string, created: bool, devices: array<int, array<string, mixed>>, error: ?string, not_found?: bool}
     */
    public function ensureDeviceRegistered(?CompanySetting $settings = null): array
    {
        $settings ??= CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return [
                'ok' => false,
                'device_id' => null,
                'created' => false,
                'devices' => [],
                'error' => 'Go-WhatsApp Base URL is not set.',
            ];
        }

        $configuredId = trim((string) ($settings->wa_device_id ?? ''));

        try {
            $listResp = $this->http($settings, false)
                ->acceptJson()
                ->get($this->endpoint($settings, '/devices'));

            if ($listResp->status() === 404) {
                // Legacy GOWA v7 servers do not expose /devices
                return [
                    'ok' => true,
                    'not_found' => true,
                    'device_id' => $configuredId !== '' ? $configuredId : null,
                    'created' => false,
                    'devices' => [],
                    'error' => null,
                ];
            }

            if (!$listResp->successful()) {
                Log::warning('GOWA ensureDeviceRegistered list failed', [
                    'status' => $listResp->status(),
                    'body' => mb_substr($listResp->body(), 0, 500),
                ]);

                return [
                    'ok' => false,
                    'device_id' => $configuredId !== '' ? $configuredId : null,
                    'created' => false,
                    'devices' => [],
                    'error' => 'Go-WhatsApp gateway returned HTTP ' . $listResp->status() . '.',
                ];
            }

            $listJson = $listResp->json();
            $devices = $this->normalizeDevices(is_array($listJson['results'] ?? null) ? $listJson['results'] : []);

            $targetId = $configuredId;
            if ($targetId !== '') {
                foreach ($devices as $dev) {
                    $candidates = array_filter([
                        isset($dev['device_id']) ? (string) $dev['device_id'] : null,
                        isset($dev['id']) ? (string) $dev['id'] : null,
                        isset($dev['device']) ? (string) $dev['device'] : null,
                    ], static fn (?string $v): bool => $v !== null && $v !== '');

                    if (in_array($targetId, $candidates, true)) {
                        return [
                            'ok' => true,
                            'device_id' => $targetId,
                            'created' => false,
                            'devices' => $devices,
                            'error' => null,
                        ];
                    }
                }
            } elseif ($devices !== []) {
                $firstId = (string) ($devices[0]['device_id'] ?? $devices[0]['id'] ?? $devices[0]['device'] ?? '');

                return [
                    'ok' => true,
                    'device_id' => $firstId !== '' ? $firstId : null,
                    'created' => false,
                    'devices' => $devices,
                    'error' => null,
                ];
            } else {
                $targetId = 'iqbill';
            }

            $createResp = $this->http($settings, false)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($settings, '/devices'), [
                    'device_id' => $targetId,
                ]);

            if ($createResp->successful() || $createResp->status() === 409) {
                $refreshedResp = $this->http($settings, false)
                    ->acceptJson()
                    ->get($this->endpoint($settings, '/devices'));

                if ($refreshedResp->successful()) {
                    $refreshedJson = $refreshedResp->json();
                    $devices = $this->normalizeDevices(
                        is_array($refreshedJson['results'] ?? null) ? $refreshedJson['results'] : []
                    );
                }

                $found = false;
                foreach ($devices as $dev) {
                    if (($dev['device_id'] ?? $dev['id'] ?? null) === $targetId) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $devices[] = [
                        'device_id' => $targetId,
                        'id' => $targetId,
                        'push_name' => $targetId,
                        'status' => 'disconnected',
                    ];
                }

                return [
                    'ok' => true,
                    'device_id' => $targetId,
                    'created' => $createResp->successful(),
                    'devices' => $devices,
                    'error' => null,
                ];
            }

            Log::warning('GOWA ensureDeviceRegistered create failed', [
                'status' => $createResp->status(),
                'body' => mb_substr($createResp->body(), 0, 500),
            ]);

            return [
                'ok' => false,
                'device_id' => $targetId,
                'created' => false,
                'devices' => $devices,
                'error' => 'Go-WhatsApp gateway returned HTTP ' . $createResp->status() . '.',
            ];
        } catch (Throwable $e) {
            Log::warning('GOWA ensureDeviceRegistered exception', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'device_id' => $configuredId !== '' ? $configuredId : null,
                'created' => false,
                'devices' => [],
                'error' => 'Unable to reach Go-WhatsApp gateway.',
            ];
        }
    }

    /**
     * Fetch the login QR Code from GOWA securely through the backend (supports v7 & v8+).
     *
     * @return array{ok: bool, qr_data_uri: ?string, qr_duration: int, message: ?string, error: ?string}
     */
    public function getLoginQr(): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'qr_data_uri' => null, 'qr_duration' => 30, 'message' => null, 'error' => 'Go-WhatsApp Base URL is not set.'];
        }

        $activeDeviceId = trim((string) ($settings->wa_device_id ?? '')) ?: null;

        try {
            $response = $this->http($settings)->get($this->endpoint($settings, '/app/login'));

            if ($response->status() === 404) {
                $ensured = $this->ensureDeviceRegistered($settings);
                if (!$ensured['ok'] || ($ensured['not_found'] ?? false)) {
                    Log::warning('GOWA getLoginQr 404 fallback failed', [
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 500),
                        'ensure_error' => $ensured['error'] ?? null,
                    ]);

                    return [
                        'ok' => false,
                        'qr_data_uri' => null,
                        'qr_duration' => 30,
                        'message' => null,
                        'error' => $ensured['error'] ?? 'Go-WhatsApp gateway returned HTTP 404.',
                    ];
                }

                $activeDeviceId = $ensured['device_id'] ?: $activeDeviceId;
                $response = $this->httpForDevice($settings, $activeDeviceId)
                    ->get($this->endpoint($settings, '/app/login'));

                if ($response->status() === 404 && !empty($activeDeviceId)) {
                    $response = $this->httpForDevice($settings, $activeDeviceId)
                        ->get($this->endpoint($settings, '/devices/' . rawurlencode($activeDeviceId) . '/login'));
                }
            }

            if (!$response->successful()) {
                Log::warning('GOWA getLoginQr failed', [
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

            return $this->parseLoginQrResponse($settings, $response, $activeDeviceId);
        } catch (Throwable $e) {
            Log::warning('GOWA getLoginQr exception', ['error' => $e->getMessage()]);

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

        $cleanPhone = (string) preg_replace('/[^0-9]/', '', $phone);

        try {
            $response = $this->http($settings)->acceptJson()->get(
                $this->endpoint($settings, '/app/login-with-code'),
                ['phone' => $cleanPhone]
            );

            if ($response->status() === 404) {
                $ensured = $this->ensureDeviceRegistered($settings);
                if (!$ensured['ok'] || ($ensured['not_found'] ?? false)) {
                    Log::warning('GOWA loginWithCode 404 fallback failed', [
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 500),
                        'ensure_error' => $ensured['error'] ?? null,
                    ]);

                    return [
                        'ok' => false,
                        'code' => null,
                        'message' => (string) ($ensured['error'] ?? 'Go-WhatsApp gateway returned HTTP 404.'),
                    ];
                }

                $deviceId = $ensured['device_id'];
                $response = $this->httpForDevice($settings, $deviceId)->acceptJson()->get(
                    $this->endpoint($settings, '/app/login-with-code'),
                    ['phone' => $cleanPhone]
                );

                if ($response->status() === 404 && !empty($deviceId)) {
                    $response = $this->httpForDevice($settings, $deviceId)
                        ->acceptJson()
                        ->asJson()
                        ->post(
                            $this->endpoint($settings, '/devices/' . rawurlencode($deviceId) . '/login/code'),
                            ['phone' => $cleanPhone]
                        );
                }
            }

            $json = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'code' => $json['results']['pair_code'] ?? $json['results']['code'] ?? null,
                'message' => (string) ($json['message'] ?? ('Go-WhatsApp gateway returned HTTP ' . $response->status() . '.')),
            ];
        } catch (Throwable $e) {
            Log::warning('GOWA loginWithCode exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'code' => null, 'message' => 'Unable to reach Go-WhatsApp gateway.'];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function reconnect(): array
    {
        return $this->deviceAction('/app/reconnect', 'reconnect');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function logout(): array
    {
        return $this->deviceAction('/app/logout', 'logout');
    }

    /**
     * Execute a session action trying legacy GOWA v7 GET endpoints first,
     * falling back to GOWA v8+ POST /devices/{device_id}/{action}.
     *
     * @return array{ok: bool, message: string}
     */
    private function deviceAction(string $legacyPath, string $v8Action): array
    {
        $settings = CompanySetting::current();
        if (empty($settings->wa_gateway_url)) {
            return ['ok' => false, 'message' => 'Go-WhatsApp Base URL is not set.'];
        }

        try {
            $response = $this->http($settings)->acceptJson()->get($this->endpoint($settings, $legacyPath));

            if ($response->status() === 404) {
                $deviceId = trim((string) ($settings->wa_device_id ?? ''));
                if ($deviceId === '') {
                    $ensured = $this->ensureDeviceRegistered($settings);
                    $deviceId = trim((string) ($ensured['device_id'] ?? ''));
                }

                if ($deviceId !== '') {
                    $response = $this->httpForDevice($settings, $deviceId)
                        ->acceptJson()
                        ->post($this->endpoint($settings, '/devices/' . rawurlencode($deviceId) . '/' . $v8Action));
                }
            }

            $json = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'message' => (string) ($json['message'] ?? 'HTTP ' . $response->status()),
            ];
        } catch (Throwable $e) {
            Log::warning('GOWA deviceAction exception', [
                'action' => $v8Action,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Unable to reach Go-WhatsApp gateway.'];
        }
    }

    /**
     * Parse an image or JSON response from GOWA QR endpoints into a data URI payload.
     *
     * @return array{ok: bool, qr_data_uri: ?string, qr_duration: int, message: ?string, error: ?string}
     */
    private function parseLoginQrResponse(CompanySetting $settings, Response $response, ?string $deviceId = null): array
    {
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
                $imgResp = $this->httpForDevice($settings, $deviceId)->get($resolvedQrUrl);
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
    }

    /**
     * Normalize GOWA v7 and v8 device entries into consistent associative arrays.
     *
     * @param  array<int|string, mixed>  $results
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDevices(array $results): array
    {
        $normalized = [];

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $deviceId = $item['device_id'] ?? $item['id'] ?? $item['device'] ?? $item['jid'] ?? null;
            $pushName = $item['push_name'] ?? $item['display_name'] ?? $item['name'] ?? null;
            $status = $item['status'] ?? $item['state'] ?? null;

            if ($deviceId !== null && !isset($item['device_id'])) {
                $item['device_id'] = $deviceId;
            }
            if ($pushName !== null && !isset($item['push_name'])) {
                $item['push_name'] = $pushName;
            }
            if ($status !== null && !isset($item['status'])) {
                $item['status'] = $status;
            }

            $normalized[] = $item;
        }

        return $normalized;
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

    private function http(CompanySetting $settings, bool $includeDeviceHeader = true): PendingRequest
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

        if ($includeDeviceHeader && !empty($settings->wa_device_id)) {
            $request = $request->withHeaders(['X-Device-Id' => $settings->wa_device_id]);
        }

        return $request;
    }

    private function httpForDevice(CompanySetting $settings, ?string $deviceId): PendingRequest
    {
        $request = $this->http($settings, false);

        if ($deviceId !== null && $deviceId !== '') {
            $request = $request->withHeaders(['X-Device-Id' => $deviceId]);
        }

        return $request;
    }
}
