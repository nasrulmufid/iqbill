<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\MikrotikBackup;
use App\Models\MikrotikRouter;
use App\Models\PppoeUsageLog;
use App\Services\Mikrotik\MikrotikBackupService;
use App\Services\Mikrotik\PppoeUsageSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class MikrotikToolsController extends Controller
{
    public function backups(): View
    {
        return view('admin.mikrotik.backups', [
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
            'backups' => MikrotikBackup::query()->with(['mikrotikRouter', 'createdBy'])->latest('id')->paginate(20),
        ]);
    }

    public function createBackup(Request $request, MikrotikBackupService $backupService): RedirectResponse
    {
        $data = $request->validate([
            'mikrotik_router_id' => ['required', 'exists:mikrotik_routers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $router = MikrotikRouter::query()
            ->where('is_active', true)
            ->findOrFail($data['mikrotik_router_id']);

        try {
            $backup = $backupService->createRscBackup($router, $request->user(), $data['notes'] ?? null);

            return back()->with('success', "Backup .rsc ({$backup->filename}) created successfully.");
        } catch (Throwable $e) {
            return back()->with('error', 'Failed to create MikroTik backup: ' . $e->getMessage());
        }
    }

    public function downloadBackup(MikrotikBackup $backup, MikrotikBackupService $backupService): Response|RedirectResponse
    {
        if (!$this->isSafeBackupPath((string) $backup->file_path) || !Storage::disk('local')->exists($backup->file_path)) {
            return back()->with('error', 'Backup file not found in storage.');
        }

        $raw = (string) Storage::disk('local')->get($backup->file_path);
        $content = $backupService->readBackupContents($raw);
        $safeFilename = basename(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $backup->filename) ?: 'backup.rsc');

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroyBackup(MikrotikBackup $backup): RedirectResponse
    {
        if ($this->isSafeBackupPath((string) $backup->file_path) && Storage::disk('local')->exists($backup->file_path)) {
            Storage::disk('local')->delete($backup->file_path);
        }

        $backup->delete();

        return back()->with('success', 'Backup deleted successfully.');
    }

    private function isSafeBackupPath(string $path): bool
    {
        return str_starts_with($path, 'mikrotik-backups/')
            && !str_contains($path, '..')
            && !str_contains($path, '\\');
    }

    public function pppoeUsage(Request $request): View
    {
        $date = $request->string('date')->toString() ?: now('Asia/Jakarta')->toDateString();

        $dailySummary = PppoeUsageLog::query()
            ->with(['subscription.customer', 'mikrotikRouter'])
            ->whereDate('recorded_date', $date)
            ->selectRaw('subscription_id, mikrotik_router_id, SUM(rx_bytes_delta) as total_rx, SUM(tx_bytes_delta) as total_tx, MAX(uptime_seconds) as max_uptime, MAX(recorded_at) as last_recorded_at')
            ->groupBy('subscription_id', 'mikrotik_router_id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.mikrotik.usage', [
            'dailySummary' => $dailySummary,
            'date' => $date,
            'usageTrackingEnabled' => CompanySetting::current()->usage_tracking_enabled,
        ]);
    }

    public function syncUsageNow(PppoeUsageSyncService $syncService): RedirectResponse
    {
        $result = $syncService->syncAll();

        if ($result['skipped']) {
            return back()->with('error', 'Usage tracking (usage_tracking_enabled) is currently disabled.');
        }

        return back()->with('success', "Usage sync completed ({$result['synced']} session(s) recorded).");
    }
}
