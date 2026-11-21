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
        $filename = 'billing-rsc-' . now()->format('YmdHis');

        try {
            $this->command('/export', ['file' => $filename]);
            usleep(400000);
            $files = $this->print('/file', ['name' => "{$filename}.rsc"]);

            if (!empty($files[0]['.id'])) {
                $detail = $this->http()
                    ->get($this->baseUrl() . '/file/' . urlencode($files[0]['.id']))
                    ->json();

                $contents = is_array($detail) ? ($detail['contents'] ?? null) : null;
                if (is_string($contents) && $contents !== '') {
                    $this->remove('/file', $files[0]['.id']);

                    return $contents;
                }
            }
        } catch (Throwable) {
            // Continue fallback to SSH
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

    private function baseUrl(): string
    {
        $scheme = $this->router->use_ssl ? 'https' : 'http';
        $port = $this->router->rest_port ?: ($this->router->use_ssl ? 443 : 80);
        $defaultPort = $this->router->use_ssl ? 443 : 80;
        $portSuffix = $port === $defaultPort ? '' : ":{$port}";

        return "{$scheme}://{$this->router->host}{$portSuffix}/rest";
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withoutVerifying()
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->router->username, (string) $this->router->password_encrypted);
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
