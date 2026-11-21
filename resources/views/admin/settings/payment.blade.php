@extends('layouts.app', ['title' => 'Payment, Telegram & Maps API Settings'])

@section('content')
<div class="max-w-4xl space-y-6">
    <x-page-header title="System & Integration Settings" subtitle="Configure ISP profile, Sumopod QRIS, PPPoE usage tracking, Admin Telegram Bot, and Google Maps API.">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp.device') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Go-WhatsApp Settings &amp; QR Scan
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
        <div class="text-sm font-medium text-slate-700">Sumopod Webhook Endpoint URL</div>
        <div class="mt-2 flex items-center gap-2">
            <input type="text" readonly value="{{ $webhookUrl }}" class="w-full rounded-xl border-slate-300 bg-slate-50 font-mono text-xs">
            <button type="button" onclick="navigator.clipboard.writeText('{{ $webhookUrl }}'); window.dispatchEvent(new CustomEvent('notify-copy'));"
                    class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Copy</button>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.payment.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Profile & Billing --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">ISP Profile &amp; Billing</h3>
            <div>
                <label class="block text-sm font-medium text-slate-700">ISP Company Name</label>
                <input type="text" name="company_name" value="{{ old('company_name', $settings->company_name) }}" required
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Default Due Days</label>
                    <input type="number" name="default_due_days" value="{{ old('default_due_days', $settings->default_due_days) }}"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Global Isolation Grace Period (Days)</label>
                    <input type="number" name="grace_period_days" value="{{ old('grace_period_days', $settings->grace_period_days) }}"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="pregenerate_qris_on_invoice" value="1" @checked($settings->pregenerate_qris_on_invoice)
                           class="rounded border-slate-300 text-indigo-600">
                    Pre-generate QRIS (-01) when invoice is issued
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="usage_tracking_enabled" value="1" @checked($settings->usage_tracking_enabled)
                           class="rounded border-slate-300 text-indigo-600">
                    Enable PPPoE usage tracking (`usage_tracking_enabled`)
                </label>
            </div>
        </div>

        {{-- Sumopod --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">Sumopod Managed Payment</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Environment</label>
                    <select name="sumopod_environment" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        <option value="sandbox" @selected($settings->sumopod_environment === 'sandbox')>Sandbox</option>
                        <option value="live" @selected($settings->sumopod_environment === 'live')>Live</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Webhook Verification</label>
                    <select name="sumopod_webhook_verification_mode" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        <option value="auto" @selected($settings->sumopod_webhook_verification_mode === 'auto')>Auto</option>
                        <option value="signature" @selected($settings->sumopod_webhook_verification_mode === 'signature')>Svix Signature</option>
                        <option value="token" @selected($settings->sumopod_webhook_verification_mode === 'token')>X-Webhook-Token</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">QRIS Fee Bearer</label>
                    <select name="sumopod_fee_bearer" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                        <option value="merchant" @selected($settings->sumopod_fee_bearer === 'merchant')>Merchant</option>
                        <option value="customer" @selected($settings->sumopod_fee_bearer === 'customer')>Customer</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700">X-Api-Key</label>
                    <input type="password" name="sumopod_api_key" placeholder="{{ $maskedApiKey ?: 'Empty' }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Svix Signing Secret (whsec_...)</label>
                    <input type="password" name="sumopod_signing_secret" placeholder="{{ $maskedSigningSecret ?: 'Empty' }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Webhook Token (whtok_...)</label>
                    <input type="password" name="sumopod_webhook_token" placeholder="{{ $maskedWebhookToken ?: 'Empty' }}" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
            </div>
        </div>

        {{-- Admin Telegram Bot --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-slate-900">Admin Telegram Bot</h3>
                <label class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                    <input type="checkbox" name="telegram_enabled" value="1" @checked($settings->telegram_enabled) class="rounded border-slate-300 text-indigo-600">
                    Enable Telegram Bot
                </label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700">Bot Token (@BotFather)</label>
                    <input type="password" name="telegram_bot_token" placeholder="{{ $maskedTelegramToken ?: '123456:ABC-DEF...' }}"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Admin Chat / Group ID</label>
                    <input type="text" name="telegram_chat_id" value="{{ old('telegram_chat_id', $settings->telegram_chat_id) }}" placeholder="-1001234567890"
                           class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </div>
            </div>
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="telegram_notify_payment" value="1" @checked($settings->telegram_notify_payment) class="rounded border-slate-300 text-indigo-600">
                    Incoming Payment Notifications (QRIS &amp; Cash)
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="telegram_notify_error" value="1" @checked($settings->telegram_notify_error) class="rounded border-slate-300 text-indigo-600">
                    System / Router / Gateway Error Alerts
                </label>
            </div>
        </div>

        {{-- Google Maps API --}}
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
            <h3 class="font-bold text-slate-900">Google Maps JavaScript API</h3>
            <p class="text-xs text-slate-500">Used to pin customer coordinates on the map and auto-fill installation addresses (Maps JavaScript API + Geocoding API).</p>
            <div>
                <label class="block text-xs font-medium text-slate-700">Google Maps API Key</label>
                <input type="password" name="google_maps_api_key" placeholder="{{ $maskedGoogleMapsKey ?: 'AIzaSy...' }}"
                       class="mt-1 w-full rounded-xl border-slate-300 text-sm">
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 shadow-sm">
                Save All Settings
            </button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.settings.telegram.test') }}" class="bg-white rounded-2xl border border-slate-200 p-5 flex items-center justify-between gap-4 shadow-sm">
        @csrf
        <div>
            <div class="text-sm font-semibold text-slate-900">Test Admin Telegram Bot Connection</div>
            <div class="text-xs text-slate-500">Save your settings before running the connection test.</div>
        </div>
        <button type="submit" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Send Test Telegram
        </button>
    </form>
</div>
@endsection
