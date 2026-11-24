<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Exceptions\MikrotikException;
use App\Models\MikrotikRouter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Ssh\Ssh;
use Throwable;

/**
 * RouterOS v7 REST API Client (/rest/...).
 * Completely avoids binary !empty protocol quirks on RouterOS 7.
 */
class RouterOs7RestClient implements RouterOsClientInterface
{
    public function __construct(
        private readonly MikrotikRouter $router,
        private readonly int $timeout = 8
    ) {
    }

    public function print(string $path, array $where = []): array
    {
        $cleanPath = ltrim(rtrim($path, '/'), '/');

        if ($where === []) {
            return $this->request('get', "/{$cleanPath}");
        }

        $query = [];
        foreach ($where as $key => $value) {
            $query[] = "{$key}={$value}";
        }

        return $this->request('post', "/{$cleanPath}/print", [
            '.query' => $query,
        ]);
    }

    public function add(string $path, array $attributes): array
    {
        $cleanPath = ltrim(rtrim($path, '/'), '/');

        return $this->request('post', "/{$cleanPath}/add", $attributes);
    }

    public function set(string $path, string $id, array $attributes): array
    {
        $cleanPath = ltrim(rtrim($path, '/'), '/');

        return $this->request('post', "/{$cleanPath}/set", array_merge(['.id' => $id], $attributes));
    }

    public function remove(string $path, string $id): array
    {
        $cleanPath = ltrim(rtrim($path, '/'), '/');

        return $this->request('post', "/{$cleanPath}/remove", ['.id' => $id]);
    }

    public function command(string $path, array $params = []): array
    {
        $cleanPath = ltrim($path, '/');

        return $this->request('post', "/{$cleanPath}", $params);
    }

    public function exportRsc(): string
    {
        $this->assertSafeEndpoint();

        $filename = 'billing-rsc-' . now()->format('YmdHis');
        $exportedFileId = null;

        try {
            $this->command('/export', ['file' => $filename]);
            usleep(400000);
            $files = $this->print('/file', ['name' => "{$filename}.rsc"]);

            if (!empty($files[0]['.id'])) {
                $exportedFileId = (string) $files[0]['.id'];
                $detail = $this->http()
                    ->get($this->baseUrl() . '/file/' . urlencode($exportedFileId))
                    ->json();

                $contents = is_array($detail) ? ($detail['contents'] ?? null) : null;
                if (is_string($contents) && $contents !== '') {
                    return $contents;
                }
            }
        } catch (Throwable) {
            // Continue to optional SSH fallback if enabled
        } finally {
            if ($exportedFileId !== null) {
                try {
                    $this->remove('/file', $exportedFileId);
                } catch (Throwable) {
                    // Best-effort cleanup of temporary router export file
                }
            }
        }

        if (!config('services.mikrotik.allow_ssh_fallback', false)) {
            throw new MikrotikException('Failed to retrieve .rsc configuration contents via RouterOS 7 REST API (SSH fallback is disabled).');
        }

        try {
            $process = Ssh::create($this->router->username, $this->router->host, $this->router->ssh_port ?: 22)
                ->disableStrictHostKeyChecking()
                ->execute('/export');

            $output = trim($process->getOutput());
            if ($output !== '') {
                return $output;
            }
        } catch (Throwable $e) {
            throw new MikrotikException('Failed to export RouterOS 7 .rsc via REST or SSH: ' . $e->getMessage(), 0, $e);
        }

        throw new MikrotikException('Failed to retrieve .rsc configuration contents from RouterOS 7.');
    }

    public function close(): void
    {
        // Stateless HTTP REST client
    }

    private function assertSafeEndpoint(): void
    {
        if (!MikrotikRouter::isValidHost((string) $this->router->host)) {
            throw new MikrotikException('Router host is invalid or blocked by security policy.', 0, null, isConnectionError: false);
        }

        if (!MikrotikRouter::isValidUsername((string) $this->router->username)) {
            throw new MikrotikException('Router username contains disallowed characters.', 0, null, isConnectionError: false);
        }
    }

    private function baseUrl(): string
    {
        $this->assertSafeEndpoint();

        $scheme = $this->router->use_ssl ? 'https' : 'http';
        $port = $this->router->rest_port ?: ($this->router->use_ssl ? 443 : 80);
        $defaultPort = $this->router->use_ssl ? 443 : 80;
        $portSuffix = $port === $defaultPort ? '' : ":{$port}";

        return "{$scheme}://{$this->router->host}{$portSuffix}/rest";
    }

    private function http(): PendingRequest
    {
        $http = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->router->username, (string) $this->router->password_encrypted);

        $verifyTls = (bool) config('services.mikrotik.verify_tls', true);
        $caBundle = config('services.mikrotik.ca_bundle');

        if (!$verifyTls) {
            $http = $http->withoutVerifying();
        } elseif (is_string($caBundle) && $caBundle !== '') {
            $http = $http->withOptions(['verify' => $caBundle]);
        }

        return $http;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    private function request(string $method, string $endpoint, array $payload = []): array
    {
        $url = $this->baseUrl() . $endpoint;

        try {
            $response = $method === 'get'
                ? $this->http()->get($url)
                : $this->http()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new MikrotikException("Failed to connect to RouterOS 7 REST API ({$url}): {$e->getMessage()}", 0, $e);
        }

        if (!$response->successful()) {
            $detail = $response->json('detail') ?? $response->json('message') ?? $response->body();

            throw new MikrotikException(
                sprintf('RouterOS 7 REST API HTTP %d: %s', $response->status(), (string) $detail),
                $response->status(),
                null,
                isConnectionError: false
            );
        }

        $json = $response->json();
        if (!is_array($json) || $json === []) {
            return [];
        }

        // Wrap single object responses (e.g. /system/identity or /system/resource) into a list
        if (array_is_list($json)) {
            return $json;
        }

        return [$json];
    }
}
