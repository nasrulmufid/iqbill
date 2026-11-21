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
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Throwable;

class CustomerSpreadsheetService
{
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
            $rows[] = [
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
            ];
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
        $rows = $ext === 'csv'
            ? $this->parseCsv($file->getRealPath() ?: $file->getPathname())
            : $this->parseXlsx($file->getRealPath() ?: $file->getPathname());

        if (count($rows) < 2) {
            return ['imported' => 0, 'updated' => 0, 'errors' => ['File is empty or contains header row only.']];
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

            if ($code === '' || $name === '' || $phone === '') {
                $errors[] = "Row {$lineNum}: customer_code, full_name, and phone_whatsapp are required.";
                continue;
            }

            try {
                DB::transaction(function () use ($assoc, $code, $name, $phone, $address, &$imported, &$updated): void {
                    $existing = Customer::query()->where('customer_code', $code)->first();

                    $customer = Customer::query()->updateOrCreate(
                        ['customer_code' => $code],
                        [
                            'full_name' => $name,
                            'phone_whatsapp' => $phone,
                            'email' => ($assoc['email'] ?? '') !== '' ? $assoc['email'] : null,
                            'installation_address' => $address !== '' ? $address : '-',
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

                            Subscription::query()->updateOrCreate(
                                ['service_username' => $pppoeUser],
                                [
                                    'customer_id' => $customer->id,
                                    'package_id' => $package->id,
                                    'mikrotik_router_id' => $router->id,
                                    'service_password_encrypted' => ($assoc['service_password'] ?? '') !== '' ? $assoc['service_password'] : '12345678',
                                    'billing_day' => $billingDay,
                                    'isolir_day' => $isolirDay,
                                    'auto_isolate' => $autoIsolate,
                                    'next_billing_date' => now('Asia/Jakarta')->day(min($billingDay, now('Asia/Jakarta')->daysInMonth))->toDateString(),
                                    'status' => SubscriptionStatus::PendingInstallation,
                                ]
                            );
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
        $xlsx = SimpleXLSX::parse($path);
        if (!$xlsx) {
            throw new \RuntimeException(SimpleXLSX::parseError() ?: 'Failed to read XLSX file.');
        }

        return $xlsx->rows();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($handle = fopen($path, 'rb')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = array_map(static fn ($v) => (string) $v, $data);
            }
            fclose($handle);
        }

        return $rows;
    }
}
