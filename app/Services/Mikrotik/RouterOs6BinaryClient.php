<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Exceptions\MikrotikException;
use App\Models\MikrotikRouter;
use Spatie\Ssh\Ssh;
use Throwable;

/**
 * RouterOS v6 Native Binary API Client (stream-based, !empty-safe).
 */
class RouterOs6BinaryClient implements RouterOsClientInterface
{
    /** @var resource|null */
    private $socket = null;

    private bool $connected = false;

    public function __construct(
        private readonly MikrotikRouter $router,
        private readonly int $timeout = 5
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function print(string $path, array $where = []): array
    {
        $words = [rtrim($path, '/') . '/print'];
        foreach ($where as $field => $value) {
            $words[] = "?{$field}={$value}";
        }

        return $this->sendAndParse($words);
    }

    public function add(string $path, array $attributes): array
    {
        $words = [rtrim($path, '/') . '/add'];
        foreach ($attributes as $key => $value) {
            $words[] = "={$key}={$value}";
        }

        return $this->sendAndParse($words);
    }

    public function set(string $path, string $id, array $attributes): array
    {
        $words = [rtrim($path, '/') . '/set', "=.id={$id}"];
        foreach ($attributes as $key => $value) {
            $words[] = "={$key}={$value}";
        }

        return $this->sendAndParse($words);
    }

    public function remove(string $path, string $id): array
    {
        return $this->sendAndParse([
            rtrim($path, '/') . '/remove',
            "=.id={$id}",
        ]);
    }

    public function command(string $path, array $params = []): array
    {
        $words = ['/' . ltrim($path, '/')];
        foreach ($params as $key => $value) {
            $words[] = $value === '' ? "={$key}=" : "={$key}={$value}";
        }

        return $this->sendAndParse($words);
    }

    public function exportRsc(): string
    {
        $filename = 'billing-rsc-' . now()->format('YmdHis');

        try {
            $this->command('/export', ['file' => $filename]);
            // Allow router a short moment to flush the .rsc file to flash/RAM
            usleep(400000);
            $files = $this->print('/file', ['name' => "{$filename}.rsc"]);

            if (!empty($files[0]['contents'])) {
                $contents = (string) $files[0]['contents'];
                if (!empty($files[0]['.id'])) {
                    $this->remove('/file', $files[0]['.id']);
                }

                return $contents;
            }
        } catch (Throwable) {
            // Fall back to SSH /export if file size exceeds /file/print contents limit
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
            throw new MikrotikException('Failed to export .rsc configuration via API or SSH: ' . $e->getMessage(), 0, $e);
        }

        throw new MikrotikException('Router .rsc export output is empty or exceeded the API read limit.');
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, array<string, string>>
     */
    private function sendAndParse(array $words): array
    {
        $this->ensureConnected();
        $this->writeSentence($words);

        return $this->readUntilDone();
    }

    private function ensureConnected(): void
    {
        if ($this->connected && is_resource($this->socket)) {
            return;
        }

        $scheme = $this->router->use_ssl ? 'ssl://' : '';
        $port = $this->router->api_port ?: ($this->router->use_ssl ? 8729 : 8728);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "{$scheme}{$this->router->host}:{$port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            throw new MikrotikException("Failed to connect to MikroTik Binary API ({$this->router->host}:{$port}): {$errstr}");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->authenticate();
        $this->connected = true;
    }

    private function authenticate(): void
    {
        $password = (string) $this->router->password_encrypted;

        $this->writeSentence([
            '/login',
            '=name=' . $this->router->username,
            '=password=' . $password,
        ]);

        $sentences = $this->readRawSentences();
        $first = $sentences[0][0] ?? '';

        if ($first === '!trap' || $first === '!fatal') {
            throw new MikrotikException($this->extractErrorMessage($sentences));
        }

        // Fallback for RouterOS < 6.43 challenge-response login
        if ($first === '!done' && isset($sentences[0][1]) && str_starts_with($sentences[0][1], '=ret=')) {
            $challenge = substr($sentences[0][1], 5);
            $hash = '00' . md5(chr(0) . $password . pack('H*', $challenge));

            $this->writeSentence([
                '/login',
                '=name=' . $this->router->username,
                '=response=' . $hash,
            ]);

            $legacySentences = $this->readRawSentences();
            if (($legacySentences[0][0] ?? '') !== '!done') {
                throw new MikrotikException($this->extractErrorMessage($legacySentences));
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readUntilDone(): array
    {
        $sentences = $this->readRawSentences();
        $rows = [];

        foreach ($sentences as $sentence) {
            $tag = $sentence[0] ?? '';

            if ($tag === '!trap' || $tag === '!fatal') {
                throw new MikrotikException(
                    $this->extractErrorMessage([$sentence]),
                    0,
                    null,
                    isConnectionError: $tag === '!fatal'
                );
            }

            // RouterOS 7 !empty or !done carries no data rows
            if ($tag === '!empty' || $tag === '!done') {
                continue;
            }

            if ($tag === '!re') {
                $row = [];
                foreach (array_slice($sentence, 1) as $word) {
                    if (preg_match('/^[=|.]([^=]+)=(.*)$/s', $word, $m) === 1) {
                        $row[$m[1]] = $m[2];
                    }
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readRawSentences(): array
    {
        $sentences = [];
        $current = [];
        $deadline = time() + $this->timeout;

        while (true) {
            if (time() > $deadline) {
                throw new MikrotikException('RouterOS API read timeout.');
            }

            $word = $this->readWord();
            if ($word === '') {
                if ($current !== []) {
                    $sentences[] = $current;
                    $tag = $current[0] ?? '';
                    $current = [];

                    if (in_array($tag, ['!done', '!fatal', '!empty'], true)) {
                        break;
                    }
                }
                continue;
            }

            $current[] = $word;
        }

        return $sentences;
    }

    /**
     * @param  array<int, array<int, string>>  $sentences
     */
    private function extractErrorMessage(array $sentences): string
    {
        foreach ($sentences as $sentence) {
            foreach ($sentence as $word) {
                if (str_starts_with($word, '=message=')) {
                    return substr($word, 9);
                }
            }
        }

        return 'RouterOS returned an error without a message.';
    }

    /**
     * @param  array<int, string>  $words
     */
    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        $this->writeWord('');
    }

    private function writeWord(string $word): void
    {
        if (!is_resource($this->socket)) {
            throw new MikrotikException('RouterOS socket is not active.');
        }

        $payload = $this->encodeLength(strlen($word)) . $word;
        $total = strlen($payload);
        $written = 0;

        while ($written < $total) {
            $bytes = @fwrite($this->socket, substr($payload, $written));
            if ($bytes === false || $bytes <= 0) {
                throw new MikrotikException('Failed to write data to RouterOS socket.');
            }
            $written += $bytes;
        }
    }

    private function readWord(): string
    {
        $length = $this->decodeLength();
        if ($length <= 0) {
            return '';
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                throw new MikrotikException('RouterOS socket connection closed while reading response.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            $len |= 0x8000;

            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;

            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;

            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }

        return chr(0xF0) . pack('N', $len);
    }

    private function decodeLength(): int
    {
        $first = @fread($this->socket, 1);
        if ($first === false || $first === '') {
            return 0;
        }

        $byte = ord($first);

        if (($byte & 0x80) === 0x00) {
            return $byte;
        }
        if (($byte & 0xC0) === 0x80) {
            return (($byte & ~0xC0) << 8) | ord((string) @fread($this->socket, 1));
        }
        if (($byte & 0xE0) === 0xC0) {
            $rest = (string) @fread($this->socket, 2);

            return (($byte & ~0xE0) << 16) | (ord($rest[0] ?? "\0") << 8) | ord($rest[1] ?? "\0");
        }
        if (($byte & 0xF0) === 0xE0) {
            $rest = (string) @fread($this->socket, 3);

            return (($byte & ~0xF0) << 24) | (ord($rest[0] ?? "\0") << 16) | (ord($rest[1] ?? "\0") << 8) | ord($rest[2] ?? "\0");
        }

        $rest = (string) @fread($this->socket, 4);

        return (int) (unpack('N', $rest)[1] ?? 0);
    }
}
