<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

interface RouterOsClientInterface
{
    /**
     * @param  array<string, string>  $where
     * @return array<int, array<string, string>>
     */
    public function print(string $path, array $where = []): array;

    /**
     * @param  array<string, string>  $attributes
     * @return array<int|string, mixed>
     */
    public function add(string $path, array $attributes): array;

    /**
     * @param  array<string, string>  $attributes
     * @return array<int|string, mixed>
     */
    public function set(string $path, string $id, array $attributes): array;

    /**
     * @return array<int|string, mixed>
     */
    public function remove(string $path, string $id): array;

    /**
     * @param  array<string, string>  $params
     * @return array<int, array<string, string>>
     */
    public function command(string $path, array $params = []): array;

    public function exportRsc(): string;

    public function close(): void;
}
