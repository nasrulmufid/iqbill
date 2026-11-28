<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\Backup\SystemBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SystemBackupController extends Controller
{
    public function index(): View
    {
        $settings = CompanySetting::current();

        $backups = SystemBackup::query()
            ->with('createdBy:id,name,email')
            ->latest('created_at')
            ->paginate(15);

        $auditLogs = AuditLog::query()
            ->with('user:id,name')
            ->whereIn('action', [
                SystemBackupService::AUDIT_ACTION_CREATED,
                SystemBackupService::AUDIT_ACTION_DOWNLOADED,
                SystemBackupService::AUDIT_ACTION_RESTORED,
                SystemBackupService::AUDIT_ACTION_DELETED,
                SystemBackupService::AUDIT_ACTION_SCHEDULE_UPDATED,
                SystemBackupService::AUDIT_ACTION_TELEGRAM_SENT,
                SystemBackupService::AUDIT_ACTION_TELEGRAM_FAILED,
            ])
            ->latest('created_at')
            ->limit(20)
            ->get();

        $activeTableCount = 0;
        $liveRowCount = 0;

        foreach (SystemBackupService::FULL_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $activeTableCount++;
                $liveRowCount += DB::table($table)->count();
            }
        }

        return view('admin.backups.index', [
            'settings' => $settings,
            'hasAutoBackupPassphrase' => mb_strlen((string) ($settings->auto_backup_passphrase_encrypted ?? '')) >= SystemBackupService::MIN_PASSPHRASE_LENGTH,
            'telegramBotConfigured' => ! empty($settings->telegram_bot_token_encrypted),
            'resolvedBackupChatId' => $settings->resolvedAutoBackupTelegramChatId(),
            'backups' => $backups,
            'auditLogs' => $auditLogs,
            'totalBackups' => SystemBackup::query()->count(),
            'totalStorageBytes' => (int) SystemBackup::query()->sum('file_size_bytes'),
            'latestBackup' => SystemBackup::query()->latest('created_at')->first(),
            'activeTableCount' => $activeTableCount,
            'liveRowCount' => $liveRowCount,
            'dbDriver' => strtoupper((string) DB::getDriverName()),
        ]);
    }

    public function store(Request $request, SystemBackupService $backupService): RedirectResponse
    {
        $safeInput = $request->except(['passphrase', 'passphrase_confirmation', 'current_password']);

        try {
            $data = $request->validate([
                'scope' => ['required', 'in:full,config_only'],
                'passphrase' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
                'notes' => ['nullable', 'string', 'max:500'],
            ], [
                'passphrase.min' => 'Encryption passphrase must be at least 12 characters.',
                'passphrase.confirmed' => 'Encryption passphrase confirmation does not match.',
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($safeInput);
        }

        try {
            $backup = $backupService->createBackup(
                scope: $data['scope'],
                passphrase: $data['passphrase'],
                actor: $request->user(),
                ipAddress: $request->ip(),
                notes: $data['notes'] ?? null
            );

            $scopeLabel = $backup->isFullBackup() ? 'Full Database + Config' : 'App Configuration Only';

            return back()->with(
                'success',
                "Encrypted backup created ({$backup->filename} · {$scopeLabel} · {$backup->tables_count} table(s), {$backup->records_count} record(s)). Keep your passphrase safe — it is never stored on the server."
            );
        } catch (Throwable $e) {
            return back()->withInput($safeInput)->with('error', 'Failed to create backup: '.$e->getMessage());
        }
    }

    public function download(
        Request $request,
        SystemBackup $backup,
        SystemBackupService $backupService
    ): Response|RedirectResponse {
        try {
            $contents = $backupService->readStoredBackupForDownload(
                backup: $backup,
                actor: $request->user(),
                ipAddress: $request->ip()
            );
        } catch (Throwable $e) {
            return back()->with('error', 'Unable to download backup: '.$e->getMessage());
        }

        $safeFilename = basename((string) $backup->filename);

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function restoreStored(
        Request $request,
        SystemBackup $backup,
        SystemBackupService $backupService
    ): RedirectResponse {
        $safeInput = $request->except(['passphrase', 'passphrase_confirmation', 'current_password']);

        try {
            $data = $this->validateRestoreRequest($request, requireFile: false);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($safeInput);
        }

        try {
            $result = $backupService->restoreFromStoredBackup(
                backup: $backup,
                passphrase: $data['passphrase'],
                restoreMode: $data['restore_mode'],
                actor: $request->user(),
                ipAddress: $request->ip()
            );
        } catch (Throwable $e) {
            return back()->withInput($safeInput)->with('error', 'Restore failed: '.$e->getMessage());
        }

        return $this->finishRestoreResponse($request, $result);
    }

    public function restoreUpload(
        Request $request,
        SystemBackupService $backupService
    ): RedirectResponse {
        $safeInput = $request->except(['passphrase', 'passphrase_confirmation', 'current_password', 'backup_file']);

        try {
            $data = $this->validateRestoreRequest($request, requireFile: true);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($safeInput);
        }

        $uploadedFile = $request->file('backup_file');
        $rawArchive = $uploadedFile !== null ? (string) $uploadedFile->get() : '';
        $originalName = $uploadedFile !== null
            ? preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $uploadedFile->getClientOriginalName())
            : 'uploaded.iqb';

        try {
            $result = $backupService->restoreFromRawArchive(
                rawArchive: $rawArchive,
                passphrase: $data['passphrase'],
                restoreMode: $data['restore_mode'],
                actor: $request->user(),
                ipAddress: $request->ip(),
                sourceLabel: "upload:{$originalName}"
            );
        } catch (Throwable $e) {
            return back()->withInput($safeInput)->with('error', 'Restore failed: '.$e->getMessage());
        }

        return $this->finishRestoreResponse($request, $result);
    }

    public function updateSchedule(Request $request): RedirectResponse
    {
        $safeInput = $request->except([
            'auto_backup_passphrase',
            'auto_backup_passphrase_confirmation',
            'current_password',
        ]);

        $settings = CompanySetting::current();
        $hasExistingPassphrase = mb_strlen((string) ($settings->auto_backup_passphrase_encrypted ?? '')) >= SystemBackupService::MIN_PASSPHRASE_LENGTH;

        try {
            $data = $request->validate([
                'auto_backup_enabled' => ['nullable', 'boolean'],
                'auto_backup_scope' => ['required', 'in:full,config_only'],
                'auto_backup_schedule' => ['required', Rule::in(SystemBackupService::SCHEDULES)],
                'auto_backup_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'auto_backup_day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
                'auto_backup_day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
                'auto_backup_retention_count' => ['required', 'integer', 'min:1', 'max:90'],
                'auto_backup_passphrase' => ['nullable', 'string', 'min:12', 'max:128', 'confirmed'],
                'auto_backup_send_telegram' => ['nullable', 'boolean'],
                'auto_backup_telegram_chat_id' => ['nullable', 'string', 'max:60', 'regex:/^-?[0-9]+$/'],
                'current_password' => ['required', 'string'],
            ], [
                'auto_backup_time.regex' => 'Backup time must be in 24-hour HH:MM format (e.g. 02:00).',
                'auto_backup_passphrase.min' => 'Auto-backup encryption passphrase must be at least 12 characters.',
                'auto_backup_passphrase.confirmed' => 'Auto-backup encryption passphrase confirmation does not match.',
                'auto_backup_telegram_chat_id.regex' => 'Telegram Chat ID must be a numeric ID (e.g. 123456789 or -1001234567890).',
            ]);

            $actor = $request->user();
            if ($actor === null || ! Hash::check((string) $data['current_password'], (string) $actor->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Your current Super Admin password is incorrect.',
                ]);
            }

            $enabled = $request->boolean('auto_backup_enabled');
            $sendTelegram = $request->boolean('auto_backup_send_telegram');
            $newPassphrase = trim((string) ($data['auto_backup_passphrase'] ?? ''));

            if ($enabled && ! $hasExistingPassphrase && $newPassphrase === '') {
                throw ValidationException::withMessages([
                    'auto_backup_passphrase' => 'Please set an encryption passphrase (min. 12 characters) before enabling Auto Backup.',
                ]);
            }

            $dedicatedChatId = trim((string) ($data['auto_backup_telegram_chat_id'] ?? ''));
            $effectiveChatId = $dedicatedChatId !== '' ? $dedicatedChatId : trim((string) ($settings->telegram_chat_id ?? ''));

            if ($enabled && $sendTelegram) {
                if (empty($settings->telegram_bot_token_encrypted)) {
                    throw ValidationException::withMessages([
                        'auto_backup_send_telegram' => 'Telegram Bot Token is not configured yet. Configure it in Settings first or disable Telegram delivery.',
                    ]);
                }

                if ($effectiveChatId === '') {
                    throw ValidationException::withMessages([
                        'auto_backup_telegram_chat_id' => 'Provide a Backup Telegram Chat ID (or set a default Telegram Chat ID in Settings).',
                    ]);
                }
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($safeInput);
        }

        $oldValues = [
            'auto_backup_enabled' => (bool) $settings->auto_backup_enabled,
            'auto_backup_scope' => $settings->auto_backup_scope,
            'auto_backup_schedule' => $settings->auto_backup_schedule,
            'auto_backup_time' => $settings->auto_backup_time,
            'auto_backup_day_of_week' => (int) $settings->auto_backup_day_of_week,
            'auto_backup_day_of_month' => (int) $settings->auto_backup_day_of_month,
            'auto_backup_retention_count' => (int) $settings->auto_backup_retention_count,
            'auto_backup_send_telegram' => (bool) $settings->auto_backup_send_telegram,
            'auto_backup_telegram_chat_id' => $settings->auto_backup_telegram_chat_id,
        ];

        $updates = [
            'auto_backup_enabled' => $enabled,
            'auto_backup_scope' => $data['auto_backup_scope'],
            'auto_backup_schedule' => $data['auto_backup_schedule'],
            'auto_backup_time' => $data['auto_backup_time'],
            'auto_backup_day_of_week' => (int) $data['auto_backup_day_of_week'],
            'auto_backup_day_of_month' => (int) $data['auto_backup_day_of_month'],
            'auto_backup_retention_count' => (int) $data['auto_backup_retention_count'],
            'auto_backup_send_telegram' => $sendTelegram,
            'auto_backup_telegram_chat_id' => $dedicatedChatId !== '' ? $dedicatedChatId : null,
        ];

        $passphraseChanged = $newPassphrase !== '';
        if ($passphraseChanged) {
            $updates['auto_backup_passphrase_encrypted'] = $newPassphrase;
        }

        $settings->update($updates);

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => SystemBackupService::AUDIT_ACTION_SCHEDULE_UPDATED,
            'auditable_type' => CompanySetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $oldValues,
            'new_values' => array_diff_key($updates, ['auto_backup_passphrase_encrypted' => true]) + [
                'passphrase_changed' => $passphraseChanged,
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $statusLabel = $enabled ? 'ENABLED' : 'DISABLED';
        $note = $passphraseChanged ? ' Encryption passphrase rotated securely.' : '';

        return back()->with('success', "Auto-backup schedule saved ({$statusLabel} · {$data['auto_backup_schedule']} at {$data['auto_backup_time']} WIB).{$note}");
    }

    public function runAutoNow(
        Request $request,
        SystemBackupService $backupService
    ): RedirectResponse {
        try {
            $result = $backupService->runAutoBackup(
                actor: $request->user(),
                ipAddress: $request->ip(),
                force: true
            );
        } catch (Throwable $e) {
            return back()->with('error', 'Auto backup failed: '.$e->getMessage());
        }

        if (! $result['ran']) {
            return back()->with('error', $result['telegram_message'] ?: 'Auto backup could not start right now.');
        }

        $backup = $result['backup'];
        $msg = "Auto backup created ({$backup->filename} · {$backup->tables_count} table(s), {$backup->records_count} record(s)).";

        if ($result['telegram_attempted']) {
            if ($result['telegram_ok']) {
                $msg .= ' Encrypted archive delivered to Telegram Bot.';
            } else {
                return back()->with('error', "{$msg} However, Telegram delivery failed: {$result['telegram_message']}");
            }
        }

        if ($result['pruned_count'] > 0) {
            $msg .= " Pruned {$result['pruned_count']} expired auto-backup file(s).";
        }

        return back()->with('success', $msg);
    }

    public function sendToTelegram(
        Request $request,
        SystemBackup $backup,
        SystemBackupService $backupService
    ): RedirectResponse {
        try {
            $result = $backupService->sendBackupToTelegram(
                backup: $backup,
                actor: $request->user(),
                ipAddress: $request->ip()
            );
        } catch (Throwable $e) {
            return back()->with('error', 'Failed to send backup to Telegram: '.$e->getMessage());
        }

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', "Encrypted archive {$backup->filename} sent to Telegram Bot (passphrase not included).");
    }

    public function destroy(
        Request $request,
        SystemBackup $backup,
        SystemBackupService $backupService
    ): RedirectResponse {
        $filename = $backup->filename;

        $backupService->deleteBackup($backup, $request->user(), $request->ip());

        return back()->with('success', "Backup archive {$filename} deleted successfully.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRestoreRequest(Request $request, bool $requireFile): array
    {
        $rules = [
            'restore_mode' => ['required', 'in:full,config_only'],
            'passphrase' => ['required', 'string', 'min:12', 'max:128'],
            'current_password' => ['required', 'string'],
            'confirm_phrase' => ['required', 'in:RESTORE'],
        ];

        if ($requireFile) {
            $rules['backup_file'] = ['required', 'file', 'max:51200'];
        }

        $data = $request->validate($rules, [
            'passphrase.min' => 'Backup passphrase must be at least 12 characters.',
            'confirm_phrase.in' => 'You must type RESTORE in uppercase to confirm this destructive operation.',
            'backup_file.max' => 'Uploaded backup file may not exceed 50 MB.',
        ]);

        $actor = $request->user();
        if ($actor === null || ! Hash::check((string) $data['current_password'], (string) $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current Super Admin password is incorrect.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array{restore_mode: string, archive_scope: string, tables_restored: int, records_restored: int, source: string}  $result
     */
    private function finishRestoreResponse(Request $request, array $result): RedirectResponse
    {
        $modeLabel = $result['restore_mode'] === 'full' ? 'Full Database + App Config' : 'App Configuration Only';
        $message = "Restore completed ({$modeLabel}): {$result['tables_restored']} table(s), {$result['records_restored']} record(s) restored and re-encrypted cleanly.";

        $actor = $request->user();
        if ($result['restore_mode'] === 'full' && $actor !== null) {
            $restoredUser = User::query()->find($actor->id);
            if ($restoredUser === null || ! $restoredUser->is_active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('success', $message.' Please sign in with a restored Super Admin account.');
            }

            $request->session()->regenerate();
        }

        return redirect()->route('admin.system-backups.index')->with('success', $message);
    }
}
