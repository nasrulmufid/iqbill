<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SystemBackupController extends Controller
{
    public function index(): View
    {
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
            ])
            ->latest('created_at')
            ->limit(15)
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
