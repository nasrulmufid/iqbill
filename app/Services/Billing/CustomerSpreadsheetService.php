<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Throwable;
use ZipArchive;

class CustomerSpreadsheetService
{
    private const MAX_IMPORT_ROWS = 5000;

    private const MAX_ZIP_ENTRIES = 100;

    private const MAX_ZIP_UNCOMPRESSED_BYTES = 33554432; // 32 MiB

    private const MAX_ZIP_COMPRESSION_RATIO = 100;

    public static function neutralizeSpreadsheetCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^[\t\r\n ]*[=+\-@]|^[\t\r]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    public function exportXlsxBinary(): string
    {
        $rows = [[
            'Customer Code',
            'Full Name',
            'WhatsApp',
            'Email',
            'Tax / ID Number',
            'Installation Address',
            'Package',
            'Router',
            'PPPoE Username',
            'Isolir Day',
            'Auto Isolate',
            'Service Status',
        ]];

        $customers = Customer::query()
            ->with(['subscriptions.package', 'subscriptions.mikrotikRouter'])
            ->orderBy('id')
            ->get();

        foreach ($customers as $customer) {
            $sub = $customer->subscriptions->first();
            $rows[] = array_map(
                [self::class, 'neutralizeSpreadsheetCell'],
                [
                    $customer->customer_code,
                    $customer->full_name,
                    $customer->phone_whatsapp,
                    $customer->email ?? '',
                    $customer->nik_npwp ?? '',
                    $customer->installation_address,
                    $sub?->package?->code ?? '',
                    $sub?->mikrotikRouter?->name ?? '',
                    $sub?->service_username ?? '',
                    $sub?->isolir_day ?? '',
                    $sub ? ($sub->auto_isolate ? 'YES' : 'NO') : '',
                    $sub?->status->value ?? '-',
                ]
            );
        }

        return SimpleXLSXGen::fromArray($rows)->__toString();
    }

    public function templateXlsxBinary(): string
    {
        $rows = [
            [
                'customer_code',
                'full_name',
                'phone_whatsapp',
                'email',
                'installation_address',
                'package_code',
                'router_name',
                'service_username',
                'service_password',
                'billing_day',
                'isolir_day',
                'auto_isolate',
            ],
            [
                'CUST-20260099',
                'Example Customer',
                '6281234567890',
                'customer@example.com',
                'Jl. Sudirman No. 12',
                Package::query()->value('code') ?? 'HOME-20M',
                MikrotikRouter::query()->value('name') ?? 'NAS-CORE-01',
                'example.pppoe',
                'secret123',
                '10',
                '10',
                '1',
            ],
        ];

        return SimpleXLSXGen::fromArray($rows)->__toString();
    }

    /**
     * @return array{imported: int, updated: int, errors: array<int, string>}
     */
    public function importFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath() ?: $file->getPathname();
        $rows = $ext === 'csv'
            ? $this->parseCsv($path)
            : $this->parseXlsx($path);

        if (count($rows) < 2) {
            return ['imported' => 0, 'updated' => 0, 'errors' => ['File is empty or contains header row only.']];
        }

        if (count($rows) - 1 > self::MAX_IMPORT_ROWS) {
            throw new \RuntimeException('Import file exceeds the maximum allowed limit of ' . self::MAX_IMPORT_ROWS . ' rows.');
        }

        $headers = array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $rows[0]
        );

        $imported = 0;
        $updated = 0;
        $errors = [];

        foreach (array_slice($rows, 1) as $idx => $rawRow) {
            $lineNum = $idx + 2;
            $assoc = [];
            foreach ($headers as $colIdx => $colName) {
                if ($colName !== '') {
                    $assoc[$colName] = isset($rawRow[$colIdx]) ? trim((string) $rawRow[$colIdx]) : '';
                }
            }

            $code = $assoc['customer_code'] ?? $assoc['kode_pelanggan'] ?? '';
            $name = $assoc['full_name'] ?? $assoc['nama_lengkap'] ?? '';
            $phone = Customer::normalizeWhatsappPhone($assoc['phone_whatsapp'] ?? $assoc['no_whatsapp'] ?? '');
            $address = $assoc['installation_address'] ?? $assoc['alamat'] ?? '';
            $rawEmail = $assoc['email'] ?? '';

            if ($code === '' || $name === '' || $phone === '') {
                $errors[] = "Row {$lineNum}: customer_code, full_name, and phone_whatsapp are required.";
                continue;
            }

            if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "Row {$lineNum} ({$code}): Invalid email address '{$rawEmail}'.";
                continue;
            }

            try {
                DB::transaction(function () use ($assoc, $code, $name, $phone, $address, $rawEmail, &$imported, &$updated): void {
                    $existing = Customer::query()->where('customer_code', $code)->first();

                    $customer = Customer::query()->updateOrCreate(
                        ['customer_code' => $code],
                        [
                            'full_name' => $name,
                            'phone_whatsapp' => $phone,
                            'email' => $rawEmail !== '' ? $rawEmail : $existing?->email,
                            'installation_address' => $address !== '' ? $address : ($existing?->installation_address ?: '-'),
                        ]
                    );

                    if ($existing) {
                        $updated++;
                    } else {
                        $imported++;
                    }

                    $pkgCode = $assoc['package_code'] ?? '';
                    $routerName = $assoc['router_name'] ?? '';
                    $pppoeUser = $assoc['service_username'] ?? '';

                    if ($pkgCode !== '' && $routerName !== '' && $pppoeUser !== '') {
                        $package = Package::query()->where('code', $pkgCode)->first();
                        $router = MikrotikRouter::query()->where('name', $routerName)->first();

                        if ($package !== null && $router !== null) {
                            if (!$package->supportsRouter((int) $router->id)) {
                                throw new \RuntimeException("Router {$router->name} does not support package {$package->code}.");
                            }

                            $billingDay = max(1, min(28, (int) ($assoc['billing_day'] ?? 1)));
                            $isolirDayRaw = $assoc['isolir_day'] ?? '';
                            $isolirDay = $isolirDayRaw !== '' ? max(1, min(28, (int) $isolirDayRaw)) : null;
                            $autoIsolate = !in_array(strtolower((string) ($assoc['auto_isolate'] ?? '1')), ['0', 'false', 'tidak', 'no'], true);

                            $existingSub = Subscription::query()->where('service_username', $pppoeUser)->first();

                            if ($existingSub !== null) {
                                if ((int) $existingSub->customer_id !== (int) $customer->id) {
                                    throw new \RuntimeException("PPPoE username '{$pppoeUser}' is already assigned to another customer.");
                                }

                                $subUpdates = [
                                    'package_id' => $package->id,
                                    'mikrotik_router_id' => $router->id,
                                    'billing_day' => $billingDay,
                                    'isolir_day' => $isolirDay,
                                    'auto_isolate' => $autoIsolate,
                                ];

                                if (($assoc['service_password'] ?? '') !== '') {
                                    $subUpdates['service_password_encrypted'] = $assoc['service_password'];
                                }

                                $existingSub->update($subUpdates);
                            } else {
                                $generatedPass = Str::password(12, letters: true, numbers: true, symbols: false);

                                Subscription::query()->create([
                                    'customer_id' => $customer->id,
                                    'package_id' => $package->id,
                                    'mikrotik_router_id' => $router->id,
                                    'service_username' => $pppoeUser,
                                    'service_password_encrypted' => ($assoc['service_password'] ?? '') !== '' ? $assoc['service_password'] : $generatedPass,
                                    'billing_day' => $billingDay,
                                    'isolir_day' => $isolirDay,
                                    'auto_isolate' => $autoIsolate,
                                    'next_billing_date' => now('Asia/Jakarta')->day(min($billingDay, now('Asia/Jakarta')->daysInMonth))->toDateString(),
                                    'status' => SubscriptionStatus::PendingInstallation,
                                ]);
                            }
                        }
                    }
                });
            } catch (Throwable $e) {
                $errors[] = "Row {$lineNum} ({$code}): {$e->getMessage()}";
            }
        }

        return compact('imported', 'updated', 'errors');
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseXlsx(string $path): array
    {
        $this->assertSafeXlsxArchive($path);

        $xlsx = SimpleXLSX::parse($path);
        if (!$xlsx) {
            throw new \RuntimeException(SimpleXLSX::parseError() ?: 'Failed to read XLSX file.');
        }

        return $xlsx->rows();
    }

    private function assertSafeXlsxArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Invalid or corrupted XLSX archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new \RuntimeException('XLSX archive contains too many entries.');
            }

            $totalUncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }

                $uncompressed = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $totalUncompressed += $uncompressed;

                if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    throw new \RuntimeException('XLSX archive uncompressed size exceeds allowed safety limit.');
                }

                if ($compressed > 0 && $uncompressed > 65536 && ($uncompressed / $compressed) > self::MAX_ZIP_COMPRESSION_RATIO) {
                    throw new \RuntimeException('XLSX archive compression ratio exceeds allowed safety limit.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($handle = fopen($path, 'rb')) !== false) {
            try {
                while (($data = fgetcsv($handle)) !== false) {
                    $rows[] = array_map(static fn ($v) => (string) $v, $data);
                    if (count($rows) > self::MAX_IMPORT_ROWS + 1) {
                        throw new \RuntimeException('CSV file exceeds the maximum allowed limit of ' . self::MAX_IMPORT_ROWS . ' rows.');
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $rows;
    }
}
