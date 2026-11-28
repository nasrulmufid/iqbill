<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\Notification\TelegramNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SystemBackupService
{
    public const ARCHIVE_FORMAT = 'iqbill-backup-v1';

    public const CIPHER = 'aes-256-gcm';

    public const KDF = 'pbkdf2-sha256';

    public const PBKDF2_ITERATIONS = 210_000;

    public const MIN_PBKDF2_ITERATIONS = 100_000;

    public const MAX_PBKDF2_ITERATIONS = 1_000_000;

    public const MIN_PASSPHRASE_LENGTH = 12;

    public const MAX_ARCHIVE_BYTES = 52_428_800; // 50 MB

    public const MAX_DECOMPRESSED_BYTES = 134_217_728; // 128 MB

    public const AUDIT_ACTION_CREATED = 'system_backup.created';

    public const AUDIT_ACTION_DOWNLOADED = 'system_backup.downloaded';

    public const AUDIT_ACTION_RESTORED = 'system_backup.restored';

    public const AUDIT_ACTION_DELETED = 'system_backup.deleted';

    public const AUDIT_ACTION_SCHEDULE_UPDATED = 'system_backup.schedule_updated';

    public const AUDIT_ACTION_TELEGRAM_SENT = 'system_backup.telegram_sent';

    public const AUDIT_ACTION_TELEGRAM_FAILED = 'system_backup.telegram_failed';

    /**
     * Supported automated backup schedule presets.
     *
     * @var list<string>
     */
    public const SCHEDULES = [
        'daily',
        'weekly',
        'monthly',
        'every_6_hours',
        'every_12_hours',
    ];

    /**
     * Application configuration tables (settings & WhatsApp templates).
     *
     * @var list<string>
     */
    public const CONFIG_TABLES = [
        'company_settings',
        'notification_templates',
    ];

    /**
     * Full operational database tables ordered from parent to child for FK-safe restore.
     * Deliberately excludes host/runtime tables (migrations, sessions, cache, jobs, system_backups, mikrotik_backups).
     *
     * @var list<string>
     */
    public const FULL_TABLES = [
        'company_settings',
        'notification_templates',
        'users',
        'mikrotik_routers',
        'packages',
        'mikrotik_router_package',
        'customers',
        'subscriptions',
        'invoices',
        'payments',
        'payment_webhook_logs',
        'mikrotik_provisioning_logs',
        'pppoe_usage_logs',
        'whatsapp_broadcasts',
        'whatsapp_notification_logs',
        'tickets',
        'ticket_replies',
        'audit_logs',
    ];

    /**
     * Columns encrypted with Laravel's APP_KEY (`encrypted` cast).
     * Decrypted inside the AES-256-GCM backup archive and automatically re-encrypted
     * with the target server's APP_KEY during restore for cross-server portability.
     *
     * @var array<string, list<string>>
     */
    public const ENCRYPTED_COLUMNS = [
        'company_settings' => [
            'sumopod_api_key_encrypted',
            'sumopod_signing_secret_encrypted',
            'sumopod_webhook_token_encrypted',
            'wa_api_token_encrypted',
            'wa_basic_auth_password_encrypted',
            'telegram_bot_token_encrypted',
            'google_maps_api_key_encrypted',
            'auto_backup_passphrase_encrypted',
        ],
        'mikrotik_routers' => [
            'password_encrypted',
        ],
        'subscriptions' => [
            'service_password_encrypted',
        ],
    ];

    public function createBackup(
        string $scope,
        string $passphrase,
        ?User $actor,
        ?string $ipAddress = null,
        ?string $notes = null,
        string $triggerSource = 'manual'
    ): SystemBackup {
        if (! in_array($scope, ['full', 'config_only'], true)) {
            throw new RuntimeException("Invalid backup scope '{$scope}'.");
        }

        if (! in_array($triggerSource, ['manual', 'scheduled'], true)) {
            throw new RuntimeException("Invalid backup trigger source '{$triggerSource}'.");
        }

        $this->assertValidPassphrase($passphrase);

        $tableList = $scope === 'full' ? self::FULL_TABLES : self::CONFIG_TABLES;
        $tablesData = [];
        $tableCounts = [];
        $totalRecords = 0;

        foreach ($tableList as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = $this->exportTableRows($table);
            $tablesData[$table] = $rows;
            $count = count($rows);
            $tableCounts[$table] = $count;
            $totalRecords += $count;
        }

        $appConfigSnapshot = $this->collectSafeAppConfigSnapshot();

        $dataBlock = [
            'app_config' => $appConfigSnapshot,
            'tables' => $tablesData,
        ];

        $dataJson = json_encode($dataBlock, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $payloadSha256 = hash('sha256', $dataJson);

        $cleanNotes = $notes !== null ? trim($notes) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        $payload = [
            'manifest' => [
                'format' => self::ARCHIVE_FORMAT,
                'scope' => $scope,
                'created_at' => now('Asia/Jakarta')->toIso8601String(),
                'app_name' => (string) config('app.name', 'IQ Bill'),
                'app_version' => (string) app()->version(),
                'php_version' => PHP_VERSION,
                'db_driver' => (string) DB::getDriverName(),
                'tables_count' => count($tablesData),
                'records_count' => $totalRecords,
                'table_counts' => $tableCounts,
                'notes' => $cleanNotes,
            ],
            'payload_sha256' => $payloadSha256,
            'data' => $dataBlock,
        ];

        $encryptedArchive = $this->encryptArchivePayload($payload, $passphrase);
        $archiveSha256 = hash('sha256', $encryptedArchive);

        $scopeSlug = $scope === 'full' ? 'full' : 'config';
        $timestamp = now('Asia/Jakarta')->format('Ymd-His');
        $suffix = Str::lower(Str::random(8));
        $filename = sprintf('iqbill-%s-%s-%s.iqb', $scopeSlug, $timestamp, $suffix);
        $relativePath = SystemBackup::STORAGE_PREFIX.$filename;

        $written = Storage::disk('local')->put($relativePath, $encryptedArchive);
        if (! $written) {
            throw new RuntimeException('Failed to write encrypted backup archive to local private storage.');
        }

        $backup = SystemBackup::query()->create([
            'scope' => $scope,
            'trigger_source' => $triggerSource,
            'filename' => $filename,
            'file_path' => $relativePath,
            'file_size_bytes' => strlen($encryptedArchive),
            'checksum_sha256' => $archiveSha256,
            'encryption_cipher' => self::CIPHER,
            'tables_count' => count($tablesData),
            'records_count' => $totalRecords,
            'app_version' => (string) app()->version(),
            'notes' => $cleanNotes,
            'created_by_user_id' => $actor?->id,
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => self::AUDIT_ACTION_CREATED,
            'auditable_type' => SystemBackup::class,
            'auditable_id' => $backup->id,
            'new_values' => [
                'scope' => $scope,
                'trigger_source' => $triggerSource,
                'filename' => $filename,
                'tables_count' => $backup->tables_count,
                'records_count' => $backup->records_count,
                'file_size_bytes' => $backup->file_size_bytes,
                'checksum_sha256' => $archiveSha256,
                'notes' => $cleanNotes,
            ],
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);

        return $backup;
    }

    /**
     * Evaluate whether the configured automated backup schedule is due at the given WIB timestamp.
     * Prevents duplicate execution within the same schedule slot.
     */
    public function shouldRunScheduledBackup(CompanySetting $settings, ?CarbonImmutable $nowWib = null): bool
    {
        if (! $settings->auto_backup_enabled) {
            return false;
        }

        $passphrase = (string) ($settings->auto_backup_passphrase_encrypted ?? '');
        if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            return false;
        }

        $now = ($nowWib ?? CarbonImmutable::now('Asia/Jakarta'))->setTimezone('Asia/Jakarta');
        $lastRun = $settings->auto_backup_last_run_at
            ? CarbonImmutable::instance($settings->auto_backup_last_run_at)->setTimezone('Asia/Jakarta')
            : null;

        $schedule = (string) ($settings->auto_backup_schedule ?: 'daily');
        $targetTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $settings->auto_backup_time) === 1
            ? (string) $settings->auto_backup_time
            : '02:00';

        return match ($schedule) {
            'every_6_hours' => $now->minute === 0
                && ($now->hour % 6 === 0)
                && ($lastRun === null || $lastRun->format('Y-m-d H') !== $now->format('Y-m-d H')),

            'every_12_hours' => $now->minute === 0
                && ($now->hour % 12 === 0)
                && ($lastRun === null || $lastRun->format('Y-m-d H') !== $now->format('Y-m-d H')),

            'weekly' => (int) $now->dayOfWeek === (int) $settings->auto_backup_day_of_week
                && $now->format('H:i') === $targetTime
                && ($lastRun === null || $lastRun->toDateString() !== $now->toDateString()),

            'monthly' => (int) $now->day === max(1, min(28, (int) $settings->auto_backup_day_of_month))
                && $now->format('H:i') === $targetTime
                && ($lastRun === null || $lastRun->format('Y-m') !== $now->format('Y-m')),

            default => $now->format('H:i') === $targetTime
                && ($lastRun === null || $lastRun->toDateString() !== $now->toDateString()),
        };
    }

    /**
     * Execute automated backup (encrypting with the configured passphrase), optionally deliver to Telegram,
     * and prune expired scheduled local archives according to retention policy.
     *
     * @return array{
     *     ran: bool,
     *     reason: string,
     *     backup: ?SystemBackup,
     *     telegram_attempted: bool,
     *     telegram_ok: bool,
     *     telegram_message: ?string,
     *     pruned_count: int
     * }
     */
    public function runAutoBackup(
        ?User $actor = null,
        ?string $ipAddress = null,
        bool $force = false,
        ?CarbonImmutable $nowWib = null
    ): array {
        $lock = Cache::lock('system_auto_backup_lock', 600);
        if (! $lock->get()) {
            return [
                'ran' => false,
                'reason' => 'locked',
                'backup' => null,
                'telegram_attempted' => false,
                'telegram_ok' => false,
                'telegram_message' => 'Another auto-backup process is currently running.',
                'pruned_count' => 0,
            ];
        }

        try {
            $settings = CompanySetting::current()->fresh();

            if (! $force && ! $this->shouldRunScheduledBackup($settings, $nowWib)) {
                return [
                    'ran' => false,
                    'reason' => 'not_due',
                    'backup' => null,
                    'telegram_attempted' => false,
                    'telegram_ok' => false,
                    'telegram_message' => null,
                    'pruned_count' => 0,
                ];
            }

            $passphrase = (string) ($settings->auto_backup_passphrase_encrypted ?? '');
            if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
                $error = 'Auto-backup encryption passphrase is not configured (minimum 12 characters required).';
                $settings->forceFill([
                    'auto_backup_last_run_at' => now(),
                    'auto_backup_last_status' => 'failed',
                    'auto_backup_last_error' => $error,
                ])->save();

                throw new RuntimeException($error);
            }

            $scope = in_array($settings->auto_backup_scope, ['full', 'config_only'], true)
                ? (string) $settings->auto_backup_scope
                : 'full';

            $scheduleLabel = str_replace('_', ' ', (string) ($settings->auto_backup_schedule ?: 'daily'));
            $notes = $force
                ? "Auto backup ({$scheduleLabel} · manual trigger)"
                : "Scheduled auto backup ({$scheduleLabel})";

            try {
                $backup = $this->createBackup(
                    scope: $scope,
                    passphrase: $passphrase,
                    actor: $actor,
                    ipAddress: $ipAddress,
                    notes: $notes,
                    triggerSource: 'scheduled'
                );
            } catch (Throwable $e) {
                $settings->forceFill([
                    'auto_backup_last_run_at' => now(),
                    'auto_backup_last_status' => 'failed',
                    'auto_backup_last_error' => mb_substr($e->getMessage(), 0, 500),
                ])->save();

                throw $e;
            }

            $telegramAttempted = false;
            $telegramOk = false;
            $telegramMessage = null;

            if ($settings->auto_backup_send_telegram) {
                $telegramAttempted = true;
                $tgResult = $this->sendBackupToTelegram($backup, $settings, $actor, $ipAddress);
                $telegramOk = $tgResult['ok'];
                $telegramMessage = $tgResult['message'];
            }

            $retention = max(1, min(90, (int) ($settings->auto_backup_retention_count ?: 7)));
            $prunedCount = $this->pruneOldScheduledBackups($retention);

            $status = (! $telegramAttempted || $telegramOk) ? 'success' : 'partial_telegram_failed';

            $settings->forceFill([
                'auto_backup_last_run_at' => now(),
                'auto_backup_last_status' => $status,
                'auto_backup_last_error' => $status === 'success' ? null : mb_substr((string) $telegramMessage, 0, 500),
            ])->save();

            return [
                'ran' => true,
                'reason' => 'completed',
                'backup' => $backup->fresh(),
                'telegram_attempted' => $telegramAttempted,
                'telegram_ok' => $telegramOk,
                'telegram_message' => $telegramMessage,
                'pruned_count' => $prunedCount,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Send an existing encrypted backup archive (.iqb) to the configured Telegram Bot.
     * NEVER includes the encryption passphrase in the message caption.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendBackupToTelegram(
        SystemBackup $backup,
        ?CompanySetting $settings = null,
        ?User $actor = null,
        ?string $ipAddress = null
    ): array {
        $settings = $settings ?? CompanySetting::current();
        $chatId = $settings->resolvedAutoBackupTelegramChatId();

        if (empty($settings->telegram_bot_token_encrypted) || $chatId === null) {
            $message = 'Telegram Bot token or backup destination Chat ID is not configured.';

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => self::AUDIT_ACTION_TELEGRAM_FAILED,
                'auditable_type' => SystemBackup::class,
                'auditable_id' => $backup->id,
                'new_values' => [
                    'filename' => $backup->filename,
                    'error' => $message,
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return ['ok' => false, 'message' => $message];
        }

        $raw = $this->readAndVerifyStoredArchive($backup);
        $scopeLabel = $backup->isFullBackup() ? 'Full Database + App Config' : 'App Configuration Only';
        $sizeKb = number_format($backup->file_size_bytes / 1024, 1);
        $companyName = e((string) ($settings->company_name ?: config('app.name', 'IQ Bill')));
        $checksumShort = substr($backup->checksum_sha256, 0, 16);

        $caption = implode("\n", [
            "<b>[{$companyName}] Encrypted Auto Backup</b>",
            'Scope: <b>'.e($scopeLabel).'</b>',
            'File: <code>'.e($backup->filename).'</code>',
            "Coverage: {$backup->tables_count} tables · ".number_format($backup->records_count)." records ({$sizeKb} KB)",
            'SHA-256: <code>'.e($checksumShort).'...</code>',
            'Time: '.now('Asia/Jakarta')->format('d M Y H:i').' WIB',
            'Cipher: AES-256-GCM (passphrase never transmitted)',
        ]);

        $telegram = app(TelegramNotificationService::class);
        $result = $telegram->sendDocument(
            filename: $backup->filename,
            rawBytes: $raw,
            htmlCaption: $caption,
            chatIdOverride: $chatId
        );

        if ($result['ok']) {
            $backup->forceFill(['telegram_sent_at' => now()])->save();

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => self::AUDIT_ACTION_TELEGRAM_SENT,
                'auditable_type' => SystemBackup::class,
                'auditable_id' => $backup->id,
                'new_values' => [
                    'filename' => $backup->filename,
                    'scope' => $backup->scope,
                    'chat_id' => $chatId,
                    'checksum_sha256' => $backup->checksum_sha256,
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        } else {
            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => self::AUDIT_ACTION_TELEGRAM_FAILED,
                'auditable_type' => SystemBackup::class,
                'auditable_id' => $backup->id,
                'new_values' => [
                    'filename' => $backup->filename,
                    'chat_id' => $chatId,
                    'error' => mb_substr($result['message'], 0, 400),
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Prune oldest scheduled backups beyond the configured retention count.
     * Manual backups created by Super Admin are NEVER pruned automatically.
     */
    public function pruneOldScheduledBackups(int $keepCount): int
    {
        $keepCount = max(1, $keepCount);

        $excess = SystemBackup::query()
            ->where('trigger_source', 'scheduled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keepCount)
            ->take(500)
            ->get();

        $pruned = 0;

        foreach ($excess as $oldBackup) {
            if ($oldBackup->hasValidStoragePath() && Storage::disk('local')->exists($oldBackup->file_path)) {
                Storage::disk('local')->delete($oldBackup->file_path);
            }

            $oldBackup->delete();
            $pruned++;
        }

        return $pruned;
    }

    public function readStoredBackupForDownload(
        SystemBackup $backup,
        ?User $actor,
        ?string $ipAddress = null
    ): string {
        $raw = $this->readAndVerifyStoredArchive($backup);

        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => self::AUDIT_ACTION_DOWNLOADED,
            'auditable_type' => SystemBackup::class,
            'auditable_id' => $backup->id,
            'new_values' => [
                'scope' => $backup->scope,
                'filename' => $backup->filename,
                'checksum_sha256' => $backup->checksum_sha256,
            ],
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);

        return $raw;
    }

    public function deleteBackup(
        SystemBackup $backup,
        ?User $actor,
        ?string $ipAddress = null
    ): void {
        if ($backup->hasValidStoragePath() && Storage::disk('local')->exists($backup->file_path)) {
            Storage::disk('local')->delete($backup->file_path);
        }

        $backupId = $backup->id;
        $snapshot = [
            'scope' => $backup->scope,
            'filename' => $backup->filename,
            'checksum_sha256' => $backup->checksum_sha256,
        ];

        $backup->delete();

        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => self::AUDIT_ACTION_DELETED,
            'auditable_type' => SystemBackup::class,
            'auditable_id' => $backupId,
            'old_values' => $snapshot,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    /**
     * Restore from a locally stored encrypted backup archive after verifying its SHA-256 checksum on disk.
     *
     * @return array{
     *     restore_mode: string,
     *     archive_scope: string,
     *     tables_restored: int,
     *     records_restored: int,
     *     source: string
     * }
     */
    public function restoreFromStoredBackup(
        SystemBackup $backup,
        string $passphrase,
        string $restoreMode,
        ?User $actor,
        ?string $ipAddress = null
    ): array {
        $raw = $this->readAndVerifyStoredArchive($backup);

        return $this->restoreFromRawArchive(
            rawArchive: $raw,
            passphrase: $passphrase,
            restoreMode: $restoreMode,
            actor: $actor,
            ipAddress: $ipAddress,
            sourceLabel: "stored:{$backup->filename}",
            auditableId: $backup->id
        );
    }

    /**
     * Decrypt, verify, and atomically restore database tables and/or application configuration from an encrypted archive.
     *
     * @return array{
     *     restore_mode: string,
     *     archive_scope: string,
     *     tables_restored: int,
     *     records_restored: int,
     *     source: string
     * }
     */
    public function restoreFromRawArchive(
        string $rawArchive,
        string $passphrase,
        string $restoreMode,
        ?User $actor,
        ?string $ipAddress = null,
        string $sourceLabel = 'uploaded_file',
        int $auditableId = 0
    ): array {
        if (! in_array($restoreMode, ['full', 'config_only'], true)) {
            throw new RuntimeException("Invalid restore mode '{$restoreMode}'.");
        }

        $payload = $this->decryptArchivePayload($rawArchive, $passphrase);
        $manifest = $payload['manifest'];
        $archiveScope = (string) ($manifest['scope'] ?? '');

        if (! in_array($archiveScope, ['full', 'config_only'], true)) {
            throw new RuntimeException('Backup manifest contains an unrecognized backup scope.');
        }

        if ($restoreMode === 'full' && $archiveScope !== 'full') {
            throw new RuntimeException(
                'Cannot perform a full database restore from a configuration-only backup. Choose Configuration Only restore mode instead.'
            );
        }

        $archiveTables = $payload['data']['tables'] ?? null;
        if (! is_array($archiveTables)) {
            throw new RuntimeException('Backup payload is missing table data.');
        }

        $allowedTables = $restoreMode === 'full' ? self::FULL_TABLES : self::CONFIG_TABLES;

        if ($restoreMode === 'full') {
            $this->assertContainsActiveSuperAdmin($archiveTables['users'] ?? []);
        }

        // Pre-prepare and validate all rows outside the write transaction (re-encrypting secrets with local APP_KEY)
        $preparedTables = [];
        $totalRecords = 0;

        foreach ($allowedTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! array_key_exists($table, $archiveTables)) {
                // On config_only restore, require present tables; on full restore, empty missing optional tables safely
                $preparedTables[$table] = [];

                continue;
            }

            $rawRows = $archiveTables[$table];
            if (! is_array($rawRows)) {
                throw new RuntimeException("Invalid row collection for table '{$table}'.");
            }

            $targetColumns = array_flip(Schema::getColumnListing($table));
            $preparedRows = [];

            foreach ($rawRows as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException("Invalid row payload for table '{$table}'.");
                }

                $filtered = [];
                foreach ($row as $col => $val) {
                    if (! is_string($col) || ! isset($targetColumns[$col])) {
                        continue;
                    }

                    $filtered[$col] = $this->restoreColumnValue($table, $col, $val);
                }

                if ($filtered !== []) {
                    $preparedRows[] = $filtered;
                }
            }

            $preparedTables[$table] = $preparedRows;
            $totalRecords += count($preparedRows);
        }

        // Execute atomic restore within a single transaction using DELETE (never TRUNCATE, which auto-commits in MySQL)
        DB::transaction(function () use ($preparedTables): void {
            $tablesToRestore = array_keys($preparedTables);

            Schema::disableForeignKeyConstraints();

            try {
                foreach (array_reverse($tablesToRestore) as $table) {
                    DB::table($table)->delete();
                }

                foreach ($tablesToRestore as $table) {
                    $rows = $preparedTables[$table];
                    if ($rows === []) {
                        continue;
                    }

                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });

        Cache::flush();

        // Record audit log after restore so the restore event is preserved in the restored database
        $actorId = ($actor !== null && User::query()->whereKey($actor->id)->exists())
            ? $actor->id
            : null;

        AuditLog::query()->create([
            'user_id' => $actorId,
            'action' => self::AUDIT_ACTION_RESTORED,
            'auditable_type' => SystemBackup::class,
            'auditable_id' => $auditableId,
            'new_values' => [
                'restore_mode' => $restoreMode,
                'archive_scope' => $archiveScope,
                'source' => $sourceLabel,
                'tables_restored' => count($preparedTables),
                'records_restored' => $totalRecords,
                'archive_created_at' => $manifest['created_at'] ?? null,
                'actor_email' => $actor?->email,
            ],
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);

        return [
            'restore_mode' => $restoreMode,
            'archive_scope' => $archiveScope,
            'tables_restored' => count($preparedTables),
            'records_restored' => $totalRecords,
            'source' => $sourceLabel,
        ];
    }

    /**
     * Encrypt and authenticate a backup payload using AES-256-GCM + PBKDF2-HMAC-SHA256.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encryptArchivePayload(array $payload, string $passphrase): string
    {
        $this->assertValidPassphrase($passphrase);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 6);
        if ($compressed === false) {
            throw new RuntimeException('Failed to compress backup payload.');
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $iterations = self::PBKDF2_ITERATIONS;
        $saltB64 = base64_encode($salt);

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $aad = $this->buildEnvelopeAad(self::ARCHIVE_FORMAT, self::CIPHER, self::KDF, $iterations, $saltB64);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $compressed,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Failed to encrypt backup archive.');
        }

        $envelope = [
            'format' => self::ARCHIVE_FORMAT,
            'cipher' => self::CIPHER,
            'kdf' => self::KDF,
            'iterations' => $iterations,
            'salt' => $saltB64,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    /**
     * Decrypt, authenticate, decompress, and verify checksum of an encrypted backup archive.
     *
     * @return array{manifest: array<string, mixed>, payload_sha256: string, data: array<string, mixed>}
     */
    public function decryptArchivePayload(string $rawArchive, string $passphrase): array
    {
        if (strlen($rawArchive) === 0 || strlen($rawArchive) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Backup archive is empty or exceeds the maximum allowed size (50 MB).');
        }

        try {
            $envelope = json_decode($rawArchive, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Invalid backup file format. Expected an encrypted IQ Bill (.iqb) archive.');
        }

        if (! is_array($envelope)) {
            throw new RuntimeException('Invalid backup archive envelope.');
        }

        $format = $envelope['format'] ?? null;
        $cipher = $envelope['cipher'] ?? null;
        $kdf = $envelope['kdf'] ?? null;
        $iterations = $envelope['iterations'] ?? null;
        $saltB64 = $envelope['salt'] ?? null;
        $ivB64 = $envelope['iv'] ?? null;
        $tagB64 = $envelope['tag'] ?? null;
        $ciphertextB64 = $envelope['ciphertext'] ?? null;

        if (
            $format !== self::ARCHIVE_FORMAT
            || $cipher !== self::CIPHER
            || $kdf !== self::KDF
            || ! is_int($iterations)
            || $iterations < self::MIN_PBKDF2_ITERATIONS
            || $iterations > self::MAX_PBKDF2_ITERATIONS
            || ! is_string($saltB64)
            || ! is_string($ivB64)
            || ! is_string($tagB64)
            || ! is_string($ciphertextB64)
        ) {
            throw new RuntimeException('Unsupported or malformed backup encryption envelope.');
        }

        $salt = base64_decode($saltB64, true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ciphertext = base64_decode($ciphertextB64, true);

        if (
            $salt === false || strlen($salt) !== 16
            || $iv === false || strlen($iv) !== 12
            || $tag === false || strlen($tag) !== 16
            || $ciphertext === false || strlen($ciphertext) === 0
        ) {
            throw new RuntimeException('Corrupted cryptographic parameters in backup archive.');
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $aad = $this->buildEnvelopeAad($format, $cipher, $kdf, $iterations, $saltB64);

        $compressed = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }

        if ($compressed === false) {
            throw new RuntimeException('Invalid backup passphrase or corrupted/tampered backup archive.');
        }

        $json = @gzdecode($compressed, self::MAX_DECOMPRESSED_BYTES);
        if ($json === false) {
            throw new RuntimeException('Failed to decompress backup payload or payload exceeds maximum safe size.');
        }

        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Backup payload JSON is corrupted.');
        }

        if (
            ! is_array($payload)
            || ! isset($payload['manifest'], $payload['payload_sha256'], $payload['data'])
            || ! is_array($payload['manifest'])
            || ! is_string($payload['payload_sha256'])
            || ! is_array($payload['data'])
        ) {
            throw new RuntimeException('Backup payload structure is incomplete.');
        }

        $expectedHash = hash('sha256', json_encode($payload['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (! hash_equals($expectedHash, $payload['payload_sha256'])) {
            throw new RuntimeException('Backup payload integrity checksum mismatch.');
        }

        return $payload;
    }

    private function readAndVerifyStoredArchive(SystemBackup $backup): string
    {
        if (! $backup->hasValidStoragePath()) {
            throw new RuntimeException('Invalid backup storage path.');
        }

        if (! Storage::disk('local')->exists($backup->file_path)) {
            throw new RuntimeException('Backup file is missing from server storage.');
        }

        $raw = (string) Storage::disk('local')->get($backup->file_path);

        if (! hash_equals($backup->checksum_sha256, hash('sha256', $raw))) {
            throw new RuntimeException('Stored backup file failed SHA-256 integrity verification (file may have been altered or corrupted).');
        }

        return $raw;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportTableRows(string $table): array
    {
        $encryptedCols = array_flip(self::ENCRYPTED_COLUMNS[$table] ?? []);
        $rows = [];

        foreach (DB::table($table)->cursor() as $rowObj) {
            $row = (array) $rowObj;

            foreach ($encryptedCols as $col => $_) {
                if (! array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '') {
                    continue;
                }

                $rawValue = (string) $row[$col];

                try {
                    $decrypted = Crypt::decryptString($rawValue);
                    $row[$col] = [
                        '__iqbill_encrypted_secret__' => true,
                        'plaintext' => $decrypted,
                    ];
                } catch (DecryptException) {
                    $row[$col] = [
                        '__iqbill_encrypted_secret__' => false,
                        'raw' => $rawValue,
                    ];
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function restoreColumnValue(string $table, string $column, mixed $value): mixed
    {
        $encryptedCols = self::ENCRYPTED_COLUMNS[$table] ?? [];

        if (in_array($column, $encryptedCols, true) && is_array($value)) {
            if (($value['__iqbill_encrypted_secret__'] ?? null) === true) {
                $plaintext = (string) ($value['plaintext'] ?? '');

                return $plaintext === '' ? null : Crypt::encryptString($plaintext);
            }

            if (array_key_exists('raw', $value)) {
                return $value['raw'];
            }
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /**
     * Collect non-secret runtime application config metadata (excludes host/infra secrets like APP_KEY, DB_PASSWORD, REDIS_PASSWORD).
     *
     * @return array<string, mixed>
     */
    private function collectSafeAppConfigSnapshot(): array
    {
        return [
            'app_name' => (string) config('app.name', 'IQ Bill'),
            'app_env' => (string) config('app.env', 'production'),
            'app_timezone' => (string) config('app.timezone', 'Asia/Jakarta'),
            'app_locale' => (string) config('app.locale', 'en'),
            'app_url' => (string) config('app.url', ''),
            'session_driver' => (string) config('session.driver', 'file'),
            'queue_connection' => (string) config('queue.default', 'sync'),
            'cache_store' => (string) config('cache.default', 'file'),
        ];
    }

    /**
     * Prevent accidental lockout by ensuring restored users table contains at least one active Super Admin.
     */
    private function assertContainsActiveSuperAdmin(mixed $usersRows): void
    {
        if (! is_array($usersRows) || $usersRows === []) {
            throw new RuntimeException('Full backup archive must contain at least one active Super Admin user.');
        }

        foreach ($usersRows as $user) {
            if (! is_array($user)) {
                continue;
            }

            $role = (string) ($user['role'] ?? '');
            $isActive = (bool) ($user['is_active'] ?? false);

            if ($role === UserRole::SuperAdmin->value && $isActive) {
                return;
            }
        }

        throw new RuntimeException('Restore aborted for safety: backup does not contain any active Super Admin account.');
    }

    private function assertValidPassphrase(string $passphrase): void
    {
        if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new RuntimeException('Backup passphrase must be at least '.self::MIN_PASSPHRASE_LENGTH.' characters long.');
        }
    }

    private function buildEnvelopeAad(
        string $format,
        string $cipher,
        string $kdf,
        int $iterations,
        string $saltB64
    ): string {
        return "{$format}|{$cipher}|{$kdf}|{$iterations}|{$saltB64}";
    }
}
