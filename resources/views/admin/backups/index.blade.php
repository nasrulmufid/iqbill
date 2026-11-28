@extends('layouts.app', ['title' => 'System Backup & Restore'])

@php
    $formContext = old('form_context', '');
    $isCreateError = $errors->any() && $formContext === 'create_backup';
    $isScheduleError = $errors->any() && $formContext === 'auto_backup_schedule';
    $isRestoreUploadError = $errors->any() && $formContext === 'restore_upload';
    $isRestoreStoredError = $errors->any() && $formContext === 'restore_stored';

    $initialRestoreTarget = null;
    if ($isRestoreStoredError && filled(old('restore_backup_id'))) {
        $failedBackup = $backups->getCollection()->firstWhere('id', (int) old('restore_backup_id'));
        if ($failedBackup !== null) {
            $initialRestoreTarget = [
                'id' => $failedBackup->id,
                'filename' => $failedBackup->filename,
                'scope' => $failedBackup->scope,
                'checksum_short' => substr($failedBackup->checksum_sha256, 0, 16),
                'restore_mode' => old('restore_mode', $failedBackup->scope === 'full' ? 'full' : 'config_only'),
            ];
        }
    }

    $formatBytes = static function (int $bytes): string {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    };

    $scheduleOptions = [
        'daily' => 'Daily (Once a day)',
        'weekly' => 'Weekly (Selected day)',
        'monthly' => 'Monthly (Selected date)',
        'every_12_hours' => 'Every 12 Hours (00:00 & 12:00 WIB)',
        'every_6_hours' => 'Every 6 Hours (00:00, 06:00, 12:00, 18:00 WIB)',
    ];

    $weekDays = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    $initialAutoEnabled = $isScheduleError ? (bool) old('auto_backup_enabled', false) : (bool) $settings->auto_backup_enabled;
    $initialAutoScope = $isScheduleError ? (string) old('auto_backup_scope', $settings->auto_backup_scope ?: 'full') : (string) ($settings->auto_backup_scope ?: 'full');
    $initialAutoSchedule = $isScheduleError ? (string) old('auto_backup_schedule', $settings->auto_backup_schedule ?: 'daily') : (string) ($settings->auto_backup_schedule ?: 'daily');
    $initialAutoTime = $isScheduleError ? (string) old('auto_backup_time', $settings->auto_backup_time ?: '02:00') : (string) ($settings->auto_backup_time ?: '02:00');
    $initialAutoDayOfWeek = $isScheduleError ? (int) old('auto_backup_day_of_week', $settings->auto_backup_day_of_week ?? 0) : (int) ($settings->auto_backup_day_of_week ?? 0);
    $initialAutoDayOfMonth = $isScheduleError ? (int) old('auto_backup_day_of_month', $settings->auto_backup_day_of_month ?? 1) : (int) ($settings->auto_backup_day_of_month ?? 1);
    $initialAutoRetention = $isScheduleError ? (int) old('auto_backup_retention_count', $settings->auto_backup_retention_count ?? 7) : (int) ($settings->auto_backup_retention_count ?? 7);
    $initialAutoSendTelegram = $isScheduleError ? (bool) old('auto_backup_send_telegram', false) : (bool) $settings->auto_backup_send_telegram;
    $initialAutoTelegramChatId = $isScheduleError ? (string) old('auto_backup_telegram_chat_id', $settings->auto_backup_telegram_chat_id ?? '') : (string) ($settings->auto_backup_telegram_chat_id ?? '');
@endphp

@section('content')
<div class="space-y-6"
     x-data="{
        createOpen: {{ $isCreateError ? 'true' : 'false' }},
        uploadRestoreOpen: {{ $isRestoreUploadError ? 'true' : 'false' }},
        restoreTarget: {{ Js::from($initialRestoreTarget) }},
        createScope: @js($isCreateError ? old('scope', 'full') : 'full'),
        createPassphrase: '',
        createPassphraseConfirm: '',
        showPassphrase: false,
        passphraseCopied: false,
        autoEnabled: {{ $initialAutoEnabled ? 'true' : 'false' }},
        autoSchedule: @js($initialAutoSchedule),
        autoSendTelegram: {{ $initialAutoSendTelegram ? 'true' : 'false' }},
        autoPassphrase: '',
        autoPassphraseConfirm: '',
        showAutoPassphrase: false,
        autoPassphraseCopied: false,
        storedRestoreMode: @js($initialRestoreTarget['restore_mode'] ?? 'full'),
        storedConfirmPhrase: '',
        uploadRestoreMode: @js($isRestoreUploadError ? old('restore_mode', 'full') : 'full'),
        uploadConfirmPhrase: '',
        openRestoreModal(item) {
            this.restoreTarget = item;
            this.storedRestoreMode = item.scope === 'full' ? 'full' : 'config_only';
            this.storedConfirmPhrase = '';
        },
        randomPassphrase() {
            const charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*_-';
            const bytes = new Uint32Array(24);
            window.crypto.getRandomValues(bytes);
            let out = '';
            for (let i = 0; i < bytes.length; i++) {
                out += charset[bytes[i] % charset.length];
            }
            return out;
        },
        generateStrongPassphrase() {
            const key = this.randomPassphrase();
            this.createPassphrase = key;
            this.createPassphraseConfirm = key;
            this.showPassphrase = true;
            this.passphraseCopied = false;
        },
        copyPassphrase() {
            if (!this.createPassphrase) return;
            navigator.clipboard.writeText(this.createPassphrase);
            this.passphraseCopied = true;
            setTimeout(() => { this.passphraseCopied = false; }, 2500);
        },
        generateAutoPassphrase() {
            const key = this.randomPassphrase();
            this.autoPassphrase = key;
            this.autoPassphraseConfirm = key;
            this.showAutoPassphrase = true;
            this.autoPassphraseCopied = false;
        },
        copyAutoPassphrase() {
            if (!this.autoPassphrase) return;
            navigator.clipboard.writeText(this.autoPassphrase);
            this.autoPassphraseCopied = true;
            setTimeout(() => { this.autoPassphraseCopied = false; }, 2500);
        }
     }"
     @keydown.escape.window="createOpen = false; uploadRestoreOpen = false; restoreTarget = null">

    <x-page-header title="System Backup & Restore" subtitle="Create AES-256-GCM encrypted backups of the database and application configuration, schedule automated Telegram delivery, or restore securely.">
        <x-slot:actions>
            <button type="button" @click="uploadRestoreOpen = true; uploadConfirmPhrase = ''"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-amber-50 px-3.5 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Restore from File
            </button>
            <button type="button" @click="createOpen = true"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Create Encrypted Backup
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Security Posture Banner --}}
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-4 text-xs text-indigo-950 flex flex-col sm:flex-row sm:items-start gap-3">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
        </div>
        <div class="space-y-1">
            <div class="font-bold text-indigo-950">End-to-End Encrypted &amp; Cross-Server Portable</div>
            <p class="text-indigo-900/90 leading-relaxed">
                Every archive is compressed and authenticated with <strong>AES-256-GCM + PBKDF2-SHA256 (210,000 iterations)</strong>.
                Encrypted database credentials (MikroTik, PPPoE, Sumopod, WhatsApp, Telegram, Google Maps) are automatically re-encrypted with the target server's <code class="rounded bg-indigo-100 px-1 py-0.5 font-mono text-[11px]">APP_KEY</code> during restore.
                Telegram deliveries upload only the encrypted <code class="rounded bg-indigo-100 px-1 py-0.5 font-mono text-[11px]">.iqb</code> archive and <strong>never</strong> transmit the encryption passphrase.
            </p>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500">Stored Backups</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ $totalBackups }}</div>
            <div class="mt-0.5 text-[11px] text-slate-400">Private local storage (.iqb)</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500">Archive Storage Used</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ $formatBytes($totalStorageBytes) }}</div>
            <div class="mt-0.5 text-[11px] text-slate-400">Gzip compressed + AES-256-GCM</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500">Live Database Scope</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ $activeTableCount }} tables</div>
            <div class="mt-0.5 text-[11px] text-slate-400">{{ number_format($liveRowCount) }} record(s) &middot; {{ $dbDriver }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500">Auto Backup Status</div>
            <div class="mt-1 flex items-center gap-2">
                @if($settings->auto_backup_enabled)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">Enabled</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 ring-1 ring-slate-200">Disabled</span>
                @endif
            </div>
            <div class="mt-1 text-[11px] text-slate-400">
                @if($settings->auto_backup_last_run_at)
                    Last run: {{ $settings->auto_backup_last_run_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB
                @else
                    Never run yet
                @endif
            </div>
        </div>
    </div>

    {{-- Automated Scheduled Backup & Telegram Delivery --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/70">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-bold text-slate-900">Automated Backup Schedule &amp; Telegram Delivery</h3>
                    @if($settings->auto_backup_last_status === 'success')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">Last Run OK</span>
                    @elseif($settings->auto_backup_last_status === 'partial_telegram_failed')
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800 ring-1 ring-amber-200">Saved Locally · Telegram Failed</span>
                    @elseif($settings->auto_backup_last_status === 'failed')
                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200">Last Run Failed</span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    Configure scheduled encrypted backups (Database + App Configuration), encryption password, local retention, and automatic delivery to Telegram Bot.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.system-backups.run-now') }}"
                  onsubmit="return confirm('Run an encrypted auto-backup now using your saved schedule settings?')">
                @csrf
                <button type="submit"
                        @disabled(!$hasAutoBackupPassphrase)
                        class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-indigo-50 px-3.5 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Run Auto Backup Now
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.system-backups.schedule.update') }}" class="p-6 space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="form_context" value="auto_backup_schedule">

            @if($settings->auto_backup_last_error)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                    <strong>Last Auto-Backup Notice:</strong> {{ $settings->auto_backup_last_error }}
                </div>
            @endif

            @if($isScheduleError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    <div class="font-bold text-rose-800">Could not save auto-backup settings:</div>
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            {{-- Enable Toggle + Scope --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 cursor-pointer">
                    <input type="checkbox"
                           name="auto_backup_enabled"
                           value="1"
                           x-model="autoEnabled"
                           class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <div class="text-xs">
                        <div class="font-bold text-slate-900">Enable Automated Scheduled Backup</div>
                        <p class="text-slate-500 mt-0.5">Runs automatically via Laravel Scheduler (WIB) with overlap protection and automatic pruning of expired auto-backups.</p>
                    </div>
                </label>

                <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                    <label for="auto_backup_scope" class="block text-xs font-semibold text-slate-700">Backup Scope</label>
                    <select id="auto_backup_scope" name="auto_backup_scope" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="full" @selected($initialAutoScope === 'full')>Full Database + App Configuration (Recommended)</option>
                        <option value="config_only" @selected($initialAutoScope === 'config_only')>App Configuration Only (Settings &amp; WA Templates)</option>
                    </select>
                </div>
            </div>

            {{-- Schedule & Retention Controls --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="auto_backup_schedule" class="block text-xs font-semibold text-slate-700 mb-1">Schedule Frequency</label>
                    <select id="auto_backup_schedule" name="auto_backup_schedule" x-model="autoSchedule" class="w-full rounded-xl border-slate-300 text-sm">
                        @foreach($scheduleOptions as $val => $label)
                            <option value="{{ $val }}" @selected($initialAutoSchedule === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="['daily', 'weekly', 'monthly'].includes(autoSchedule)">
                    <label for="auto_backup_time" class="block text-xs font-semibold text-slate-700 mb-1">Run Time (WIB, 24h)</label>
                    <input type="time"
                           id="auto_backup_time"
                           name="auto_backup_time"
                           value="{{ $initialAutoTime }}"
                           required
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>

                <div x-show="autoSchedule === 'weekly'" style="display: none;">
                    <label for="auto_backup_day_of_week" class="block text-xs font-semibold text-slate-700 mb-1">Day of Week</label>
                    <select id="auto_backup_day_of_week" name="auto_backup_day_of_week" class="w-full rounded-xl border-slate-300 text-sm">
                        @foreach($weekDays as $dayNum => $dayName)
                            <option value="{{ $dayNum }}" @selected($initialAutoDayOfWeek === $dayNum)>{{ $dayName }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="autoSchedule === 'monthly'" style="display: none;">
                    <label for="auto_backup_day_of_month" class="block text-xs font-semibold text-slate-700 mb-1">Day of Month (1–28)</label>
                    <input type="number"
                           id="auto_backup_day_of_month"
                           name="auto_backup_day_of_month"
                           value="{{ $initialAutoDayOfMonth }}"
                           min="1"
                           max="28"
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>

                <div>
                    <label for="auto_backup_retention_count" class="block text-xs font-semibold text-slate-700 mb-1">Local Retention (Auto Backups)</label>
                    <input type="number"
                           id="auto_backup_retention_count"
                           name="auto_backup_retention_count"
                           value="{{ $initialAutoRetention }}"
                           min="1"
                           max="90"
                           required
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                    <p class="mt-1 text-[11px] text-slate-400">Keeps latest N auto-backups on disk; manual backups are never pruned.</p>
                </div>
            </div>

            {{-- Encryption Passphrase & Telegram Delivery --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 pt-2 border-t border-slate-100">
                {{-- Auto Backup Encryption Passphrase --}}
                <div class="rounded-xl border border-slate-200 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-xs font-bold text-slate-900">Auto-Backup Encryption Password</div>
                        @if($hasAutoBackupPassphrase)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                Configured (Encrypted at Rest)
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800 ring-1 ring-amber-200">
                                Not Set Yet
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <label for="auto_backup_passphrase" class="block text-xs font-medium text-slate-600">
                            {{ $hasAutoBackupPassphrase ? 'New Passphrase (leave blank to keep current)' : 'Set Passphrase (min. 12 chars) *' }}
                        </label>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="generateAutoPassphrase()" class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800">
                                Generate Strong Key
                            </button>
                            <button type="button" x-show="autoPassphrase.length > 0" @click="copyAutoPassphrase()" class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-800" style="display: none;">
                                <span x-text="autoPassphraseCopied ? 'Copied!' : 'Copy'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="relative">
                        <input :type="showAutoPassphrase ? 'text' : 'password'"
                               id="auto_backup_passphrase"
                               name="auto_backup_passphrase"
                               x-model="autoPassphrase"
                               minlength="12"
                               maxlength="128"
                               autocomplete="new-password"
                               placeholder="{{ $hasAutoBackupPassphrase ? '•••••••••••• (unchanged)' : 'Enter strong backup passphrase...' }}"
                               class="w-full rounded-xl border-slate-300 pr-16 text-sm font-mono">
                        <button type="button" @click="showAutoPassphrase = !showAutoPassphrase"
                                class="absolute inset-y-0 right-0 px-3 text-xs font-semibold text-slate-500 hover:text-slate-800"
                                x-text="showAutoPassphrase ? 'Hide' : 'Show'"></button>
                    </div>

                    <input :type="showAutoPassphrase ? 'text' : 'password'"
                           id="auto_backup_passphrase_confirmation"
                           name="auto_backup_passphrase_confirmation"
                           x-model="autoPassphraseConfirm"
                           minlength="12"
                           maxlength="128"
                           autocomplete="new-password"
                           placeholder="Confirm new passphrase"
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">

                    <p class="text-[11px] text-slate-500">
                        Stored encrypted with <code class="font-mono">APP_KEY</code> in the database and <strong>never</strong> shown again or sent to Telegram. Save a copy in your password manager to restore archives on another server.
                    </p>
                </div>

                {{-- Telegram Bot Delivery --}}
                <div class="rounded-xl border border-slate-200 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-xs font-bold text-slate-900">Telegram Bot Delivery</div>
                        @if($telegramBotConfigured)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                Bot Token Ready
                            </span>
                        @else
                            <a href="{{ route('admin.settings.payment.edit') }}"
                               class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100">
                                Configure Bot Token &rarr;
                            </a>
                        @endif
                    </div>

                    <label class="flex items-start gap-2.5 text-xs cursor-pointer">
                        <input type="checkbox"
                               name="auto_backup_send_telegram"
                               value="1"
                               x-model="autoSendTelegram"
                               class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-slate-700">
                            <strong>Send encrypted <code class="font-mono">.iqb</code> file to Telegram Bot</strong> after each auto-backup (up to 49 MB Telegram limit).
                        </span>
                    </label>

                    <div>
                        <label for="auto_backup_telegram_chat_id" class="block text-xs font-medium text-slate-600 mb-1">
                            Dedicated Backup Telegram Chat ID <span class="text-slate-400">(optional, recommended: Super Admin private chat)</span>
                        </label>
                        <input type="text"
                               id="auto_backup_telegram_chat_id"
                               name="auto_backup_telegram_chat_id"
                               value="{{ $initialAutoTelegramChatId }}"
                               placeholder="{{ $settings->telegram_chat_id ? 'Blank = default (' . $settings->telegram_chat_id . ')' : 'e.g. 123456789 or -1001234567890' }}"
                               class="w-full rounded-xl border-slate-300 text-sm font-mono">
                        <p class="mt-1 text-[11px] text-slate-500">
                            Isolates database backups from shared payment notification groups. Leave blank to use the default Chat ID from
                            <a href="{{ route('admin.settings.payment.edit') }}" class="font-semibold text-indigo-600 hover:underline">Settings</a>.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Super Admin Re-auth & Save --}}
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3 pt-4 border-t border-slate-100">
                <div class="w-full sm:max-w-xs">
                    <label for="schedule_current_password" class="block text-xs font-semibold text-slate-700 mb-1">
                        Super Admin Password to Save *
                    </label>
                    <input type="password"
                           id="schedule_current_password"
                           name="current_password"
                           required
                           autocomplete="current-password"
                           placeholder="Re-enter your login password"
                           class="w-full rounded-xl border-slate-300 text-sm">
                </div>
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-semibold text-white hover:bg-slate-800 shadow-sm transition">
                    Save Auto-Backup Schedule
                </button>
            </div>
        </form>
    </div>

    {{-- Stored Backups Table --}}
    <div class="space-y-4">
        <h3 class="text-base font-bold text-slate-900">Stored Encrypted Backups</h3>
        @if($backups->isEmpty())
            <x-empty-state title="No system backups yet" description="Create an encrypted snapshot of your database and application configuration, or restore directly from an existing .iqb file.">
                <x-slot:action>
                    <button type="button" @click="createOpen = true"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        + Create Encrypted Backup
                    </button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Archive &amp; Integrity</th>
                                <th class="px-5 py-3">Scope &amp; Coverage</th>
                                <th class="px-5 py-3">Size</th>
                                <th class="px-5 py-3">Created (WIB)</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($backups as $backup)
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="px-5 py-3.5">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="font-mono text-xs font-semibold text-slate-900">{{ $backup->filename }}</span>
                                            @if($backup->isScheduled())
                                                <span class="inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700 ring-1 ring-violet-200">Auto</span>
                                            @endif
                                            @if($backup->telegram_sent_at)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200"
                                                      title="Sent to Telegram at {{ $backup->telegram_sent_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB">
                                                    Telegram Sent
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-slate-500 font-mono mt-0.5">
                                            SHA-256: {{ substr($backup->checksum_sha256, 0, 16) }}&hellip;
                                        </div>
                                        @if($backup->notes)
                                            <div class="text-xs text-slate-600 mt-0.5">{{ $backup->notes }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-xs">
                                        @if($backup->isFullBackup())
                                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                                Full DB + Config
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200">
                                                Config Only
                                            </span>
                                        @endif
                                        <div class="text-slate-500 mt-1">
                                            {{ $backup->tables_count }} table(s) &middot; {{ number_format($backup->records_count) }} record(s)
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs font-mono whitespace-nowrap">
                                        {{ $formatBytes($backup->file_size_bytes) }}
                                    </td>
                                    <td class="px-5 py-3.5 text-xs whitespace-nowrap">
                                        <div class="font-medium text-slate-800">{{ $backup->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</div>
                                        <div class="text-slate-500">{{ $backup->createdBy?->name ?? 'Scheduler' }}</div>
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                        @if($telegramBotConfigured && $resolvedBackupChatId)
                                            <form method="POST" action="{{ route('admin.system-backups.send-telegram', $backup) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded-lg bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 hover:bg-sky-100">
                                                    Send Telegram
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('admin.system-backups.download', $backup) }}"
                                           class="inline-flex items-center rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">
                                            Download
                                        </a>
                                        <button type="button"
                                                @click="openRestoreModal({{ Js::from([
                                                    'id' => $backup->id,
                                                    'filename' => $backup->filename,
                                                    'scope' => $backup->scope,
                                                    'checksum_short' => substr($backup->checksum_sha256, 0, 16),
                                                ]) }})"
                                                class="inline-flex items-center rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100">
                                            Restore
                                        </button>
                                        <form method="POST" action="{{ route('admin.system-backups.destroy', $backup) }}"
                                              class="inline"
                                              onsubmit="return confirm('Permanently delete encrypted backup {{ $backup->filename }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $backups->links() }}
        @endif
    </div>

    {{-- Backup & Restore Audit Log --}}
    @if($auditLogs->isNotEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="px-6 py-4 border-b border-slate-100 font-semibold text-slate-900">
                Recent Backup &amp; Restore Audit Log
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Timestamp (WIB)</th>
                            <th class="px-6 py-3">Event</th>
                            <th class="px-6 py-3">Details</th>
                            <th class="px-6 py-3">Actor &amp; IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @foreach($auditLogs as $log)
                            @php
                                $eventLabel = match ($log->action) {
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_CREATED => ['Backup Created', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_DOWNLOADED => ['Downloaded', 'bg-sky-50 text-sky-700 ring-sky-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_RESTORED => ['Restored', 'bg-amber-50 text-amber-800 ring-amber-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_DELETED => ['Deleted', 'bg-rose-50 text-rose-700 ring-rose-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_SCHEDULE_UPDATED => ['Schedule Updated', 'bg-indigo-50 text-indigo-700 ring-indigo-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_TELEGRAM_SENT => ['Sent to Telegram', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
                                    \App\Services\Backup\SystemBackupService::AUDIT_ACTION_TELEGRAM_FAILED => ['Telegram Failed', 'bg-rose-50 text-rose-700 ring-rose-200'],
                                    default => [$log->action, 'bg-slate-100 text-slate-600 ring-slate-200'],
                                };
                                $vals = $log->new_values ?: ($log->old_values ?: []);
                            @endphp
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap font-mono text-slate-500">
                                    {{ $log->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold ring-1 {{ $eventLabel[1] }}">
                                        {{ $eventLabel[0] }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-slate-600">
                                    @if($log->action === \App\Services\Backup\SystemBackupService::AUDIT_ACTION_RESTORED)
                                        Mode: <strong class="text-slate-800">{{ $vals['restore_mode'] ?? '-' }}</strong>
                                        &middot; Source: <span class="font-mono">{{ $vals['source'] ?? '-' }}</span>
                                        &middot; {{ $vals['tables_restored'] ?? 0 }} table(s), {{ $vals['records_restored'] ?? 0 }} record(s)
                                    @elseif($log->action === \App\Services\Backup\SystemBackupService::AUDIT_ACTION_SCHEDULE_UPDATED)
                                        <strong>{{ !empty($vals['auto_backup_enabled']) ? 'Enabled' : 'Disabled' }}</strong>
                                        &middot; {{ $vals['auto_backup_scope'] ?? 'full' }}
                                        &middot; {{ $vals['auto_backup_schedule'] ?? 'daily' }} ({{ $vals['auto_backup_time'] ?? '02:00' }} WIB)
                                        &middot; Telegram: {{ !empty($vals['auto_backup_send_telegram']) ? 'ON' : 'OFF' }}
                                        @if(!empty($vals['passphrase_changed']))
                                            &middot; <span class="font-semibold text-indigo-700">Passphrase Rotated</span>
                                        @endif
                                    @elseif($log->action === \App\Services\Backup\SystemBackupService::AUDIT_ACTION_TELEGRAM_FAILED)
                                        <span class="font-mono font-semibold text-slate-700">{{ $vals['filename'] ?? '-' }}</span>
                                        &middot; <span class="text-rose-700">{{ $vals['error'] ?? 'Delivery failed' }}</span>
                                    @else
                                        <span class="font-mono font-semibold text-slate-700">{{ $vals['filename'] ?? '-' }}</span>
                                        @if(!empty($vals['scope']))
                                            &middot; {{ $vals['scope'] }}
                                        @endif
                                        @if(!empty($vals['notes']))
                                            &middot; {{ $vals['notes'] }}
                                        @endif
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700">
                                    <div class="font-medium">{{ $log->user?->name ?? ($vals['actor_email'] ?? 'Scheduler') }}</div>
                                    <div class="font-mono text-[11px] text-slate-400">{{ $log->ip_address ?? '-' }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Modal 1: Create Encrypted Backup --}}
    <div x-show="createOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="createOpen = false">
            <div class="flex items-start justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Create Encrypted Backup</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Archive is encrypted with AES-256-GCM before being saved to private storage.</p>
                </div>
                <button type="button" @click="createOpen = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($isCreateError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.system-backups.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="form_context" value="create_backup">

                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-700">Backup Scope</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label :class="createScope === 'full' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:bg-slate-50'"
                               class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer transition">
                            <input type="radio" name="scope" value="full" x-model="createScope" class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <div class="text-xs">
                                <div class="font-semibold text-slate-900">Full Database + Config</div>
                                <div class="text-slate-500 mt-0.5">All customers, subscriptions, invoices, payments, routers, users, settings &amp; templates.</div>
                            </div>
                        </label>
                        <label :class="createScope === 'config_only' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:bg-slate-50'"
                               class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer transition">
                            <input type="radio" name="scope" value="config_only" x-model="createScope" class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <div class="text-xs">
                                <div class="font-semibold text-slate-900">App Configuration Only</div>
                                <div class="text-slate-500 mt-0.5">Company settings, payment/WA/Telegram keys &amp; WhatsApp templates only.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <label for="backup-passphrase" class="block text-xs font-semibold text-slate-700">Encryption Passphrase (min. 12 chars) *</label>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="generateStrongPassphrase()"
                                    class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800">
                                Generate Strong Key
                            </button>
                            <button type="button" x-show="createPassphrase.length > 0" @click="copyPassphrase()"
                                    class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-800" style="display: none;">
                                <span x-text="passphraseCopied ? 'Copied!' : 'Copy'"></span>
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <input :type="showPassphrase ? 'text' : 'password'"
                               id="backup-passphrase"
                               name="passphrase"
                               x-model="createPassphrase"
                               minlength="12"
                               maxlength="128"
                               required
                               autocomplete="new-password"
                               placeholder="Enter a strong passphrase..."
                               class="w-full rounded-xl border-slate-300 pr-16 text-sm font-mono">
                        <button type="button" @click="showPassphrase = !showPassphrase"
                                class="absolute inset-y-0 right-0 px-3 text-xs font-semibold text-slate-500 hover:text-slate-800"
                                x-text="showPassphrase ? 'Hide' : 'Show'"></button>
                    </div>
                </div>

                <div>
                    <label for="backup-passphrase-confirm" class="block text-xs font-semibold text-slate-700 mb-1">Confirm Passphrase *</label>
                    <input :type="showPassphrase ? 'text' : 'password'"
                           id="backup-passphrase-confirm"
                           name="passphrase_confirmation"
                           x-model="createPassphraseConfirm"
                           minlength="12"
                           maxlength="128"
                           required
                           autocomplete="new-password"
                           placeholder="Re-enter passphrase"
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>

                <div>
                    <label for="backup-notes" class="block text-xs font-semibold text-slate-700 mb-1">Notes (Optional)</label>
                    <input type="text"
                           id="backup-notes"
                           name="notes"
                           value="{{ $isCreateError ? old('notes') : '' }}"
                           maxlength="500"
                           placeholder="e.g. Pre-upgrade snapshot"
                           class="w-full rounded-xl border-slate-300 text-sm">
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                    <strong>Important:</strong> Your passphrase is <strong>never saved</strong> on the server. Without this passphrase, the backup archive cannot be decrypted or restored.
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" @click="createOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-semibold text-white hover:bg-indigo-700 shadow-sm">
                        Encrypt &amp; Save Backup
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal 2: Restore Stored Backup --}}
    <div x-show="restoreTarget" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="restoreTarget = null">
            <div class="flex items-start justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Restore Stored Backup</h3>
                    <template x-if="restoreTarget">
                        <p class="text-xs font-mono text-slate-500 mt-0.5" x-text="restoreTarget.filename + ' · SHA-256 ' + restoreTarget.checksum_short + '...'"></p>
                    </template>
                </div>
                <button type="button" @click="restoreTarget = null" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($isRestoreStoredError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <template x-if="restoreTarget">
                <form method="POST" :action="'{{ url('/admin/system-backups') }}/' + restoreTarget.id + '/restore'" class="space-y-4">
                    @csrf
                    <input type="hidden" name="form_context" value="restore_stored">
                    <input type="hidden" name="restore_backup_id" :value="restoreTarget.id">

                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
                        <strong>Destructive Action:</strong> Restoring will overwrite existing records for the selected scope atomically inside a database transaction. Take a fresh backup first if needed.
                    </div>

                    <div class="space-y-2">
                        <label class="block text-xs font-semibold text-slate-700">Restore Mode</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <label x-show="restoreTarget.scope === 'full'"
                                   :class="storedRestoreMode === 'full' ? 'border-rose-500 bg-rose-50/70 ring-1 ring-rose-500' : 'border-slate-200 bg-white'"
                                   class="flex items-start gap-2 rounded-xl border p-3 cursor-pointer text-xs">
                                <input type="radio" name="restore_mode" value="full" x-model="storedRestoreMode" class="mt-0.5 text-rose-600">
                                <div>
                                    <div class="font-semibold text-slate-900">Full DB + Config</div>
                                    <div class="text-slate-500 mt-0.5">Replace all database records and settings.</div>
                                </div>
                            </label>
                            <label :class="storedRestoreMode === 'config_only' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white'"
                                   class="flex items-start gap-2 rounded-xl border p-3 cursor-pointer text-xs">
                                <input type="radio" name="restore_mode" value="config_only" x-model="storedRestoreMode" class="mt-0.5 text-indigo-600">
                                <div>
                                    <div class="font-semibold text-slate-900">App Configuration Only</div>
                                    <div class="text-slate-500 mt-0.5">Restore only settings &amp; WhatsApp templates.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Backup Encryption Passphrase *</label>
                        <input type="password" name="passphrase" minlength="12" maxlength="128" required autocomplete="off"
                               placeholder="Enter the passphrase used to encrypt this backup"
                               class="w-full rounded-xl border-slate-300 text-sm font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Your Super Admin Login Password *</label>
                        <input type="password" name="current_password" required autocomplete="current-password"
                               placeholder="Re-enter your account password to authorize"
                               class="w-full rounded-xl border-slate-300 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            Type <span class="font-mono font-bold text-rose-700">RESTORE</span> to confirm *
                        </label>
                        <input type="text" name="confirm_phrase" x-model="storedConfirmPhrase" required autocomplete="off"
                               placeholder="RESTORE"
                               class="w-full rounded-xl border-slate-300 text-sm font-mono uppercase">
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                        <button type="button" @click="restoreTarget = null" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit"
                                :disabled="storedConfirmPhrase !== 'RESTORE'"
                                :class="storedConfirmPhrase !== 'RESTORE' ? 'opacity-50 cursor-not-allowed' : 'hover:bg-rose-700'"
                                class="rounded-xl bg-rose-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition">
                            Verify &amp; Restore Now
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>

    {{-- Modal 3: Restore from Uploaded File --}}
    <div x-show="uploadRestoreOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display: none;">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="uploadRestoreOpen = false">
            <div class="flex items-start justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Restore from Encrypted Backup File</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Upload an <code class="font-mono">.iqb</code> backup archive exported from this or another IQ Bill server.</p>
                </div>
                <button type="button" @click="uploadRestoreOpen = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($isRestoreUploadError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.system-backups.restore-upload') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="hidden" name="form_context" value="restore_upload">

                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
                    <strong>Security Check:</strong> The archive is authenticated (AES-256-GCM + SHA-256) and validated against table/column whitelists before any database changes occur.
                </div>

                <div>
                    <label for="restore-upload-file" class="block text-xs font-semibold text-slate-700 mb-1">Encrypted Backup File (.iqb, max 50 MB) *</label>
                    <input type="file"
                           id="restore-upload-file"
                           name="backup_file"
                           accept=".iqb,application/octet-stream,application/json"
                           required
                           class="w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-indigo-700">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-700">Restore Mode</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label :class="uploadRestoreMode === 'full' ? 'border-rose-500 bg-rose-50/70 ring-1 ring-rose-500' : 'border-slate-200 bg-white'"
                               class="flex items-start gap-2 rounded-xl border p-3 cursor-pointer text-xs">
                            <input type="radio" name="restore_mode" value="full" x-model="uploadRestoreMode" class="mt-0.5 text-rose-600">
                            <div>
                                <div class="font-semibold text-slate-900">Full DB + Config</div>
                                <div class="text-slate-500 mt-0.5">Replace all operational database records &amp; config.</div>
                            </div>
                        </label>
                        <label :class="uploadRestoreMode === 'config_only' ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500' : 'border-slate-200 bg-white'"
                               class="flex items-start gap-2 rounded-xl border p-3 cursor-pointer text-xs">
                            <input type="radio" name="restore_mode" value="config_only" x-model="uploadRestoreMode" class="mt-0.5 text-indigo-600">
                            <div>
                                <div class="font-semibold text-slate-900">App Configuration Only</div>
                                <div class="text-slate-500 mt-0.5">Restore only company settings &amp; WA templates.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Backup Encryption Passphrase *</label>
                    <input type="password" name="passphrase" minlength="12" maxlength="128" required autocomplete="off"
                           placeholder="Enter archive decryption passphrase"
                           class="w-full rounded-xl border-slate-300 text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Your Super Admin Login Password *</label>
                    <input type="password" name="current_password" required autocomplete="current-password"
                           placeholder="Re-enter your account password to authorize"
                           class="w-full rounded-xl border-slate-300 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Type <span class="font-mono font-bold text-rose-700">RESTORE</span> to confirm *
                    </label>
                    <input type="text" name="confirm_phrase" x-model="uploadConfirmPhrase" required autocomplete="off"
                           placeholder="RESTORE"
                           class="w-full rounded-xl border-slate-300 text-sm font-mono uppercase">
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" @click="uploadRestoreOpen = false" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit"
                            :disabled="uploadConfirmPhrase !== 'RESTORE'"
                            :class="uploadConfirmPhrase !== 'RESTORE' ? 'opacity-50 cursor-not-allowed' : 'hover:bg-rose-700'"
                            class="rounded-xl bg-rose-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition">
                        Upload &amp; Restore
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
