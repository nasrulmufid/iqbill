<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\NotificationTemplate;
use App\Services\Notification\TelegramNotificationService;
use App\Services\Notification\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = CompanySetting::current();

        return view('admin.settings.payment', [
            'settings' => $settings,
            'maskedApiKey' => $this->maskSecret($settings->sumopod_api_key_encrypted),
            'maskedSigningSecret' => $this->maskSecret($settings->sumopod_signing_secret_encrypted),
            'maskedWebhookToken' => $this->maskSecret($settings->sumopod_webhook_token_encrypted),
            'maskedTelegramToken' => $this->maskSecret($settings->telegram_bot_token_encrypted),
            'maskedGoogleMapsKey' => $this->maskSecret($settings->google_maps_api_key_encrypted),
            'webhookUrl' => url('/api/webhooks/sumopod'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'sumopod_environment' => ['required', 'in:sandbox,live'],
            'sumopod_sandbox_base_url' => ['nullable', 'url', 'max:255'],
            'sumopod_live_base_url' => ['nullable', 'url', 'max:255'],
            'sumopod_webhook_verification_mode' => ['required', 'in:auto,signature,token'],
            'sumopod_fee_bearer' => ['required', 'in:merchant,customer'],
            'default_due_days' => ['required', 'integer', 'min:1', 'max:30'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:30'],
            'sumopod_api_key' => ['nullable', 'string'],
            'sumopod_signing_secret' => ['nullable', 'string'],
            'sumopod_webhook_token' => ['nullable', 'string'],
            'telegram_chat_id' => ['nullable', 'string', 'max:60'],
            'telegram_bot_token' => ['nullable', 'string'],
            'google_maps_api_key' => ['nullable', 'string'],
        ]);

        $settings = CompanySetting::current();

        $updates = [
            'company_name' => $validated['company_name'],
            'sumopod_environment' => $validated['sumopod_environment'],
            'sumopod_webhook_verification_mode' => $validated['sumopod_webhook_verification_mode'],
            'sumopod_fee_bearer' => $validated['sumopod_fee_bearer'],
            'default_due_days' => $validated['default_due_days'],
            'grace_period_days' => $validated['grace_period_days'],
            'pregenerate_qris_on_invoice' => $request->boolean('pregenerate_qris_on_invoice'),
            'usage_tracking_enabled' => $request->boolean('usage_tracking_enabled'),
            'telegram_enabled' => $request->boolean('telegram_enabled'),
            'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
            'telegram_notify_payment' => $request->boolean('telegram_notify_payment'),
            'telegram_notify_error' => $request->boolean('telegram_notify_error'),
        ];

        if ($request->has('sumopod_sandbox_base_url')) {
            $sandboxUrl = rtrim(trim((string) ($validated['sumopod_sandbox_base_url'] ?? '')), '/');
            $updates['sumopod_sandbox_base_url'] = $sandboxUrl !== ''
                ? $sandboxUrl
                : CompanySetting::DEFAULT_SUMOPOD_SANDBOX_BASE_URL;
        }
        if ($request->has('sumopod_live_base_url')) {
            $liveUrl = rtrim(trim((string) ($validated['sumopod_live_base_url'] ?? '')), '/');
            $updates['sumopod_live_base_url'] = $liveUrl !== ''
                ? $liveUrl
                : CompanySetting::DEFAULT_SUMOPOD_LIVE_BASE_URL;
        }

        if (!empty($validated['sumopod_api_key'])) {
            $updates['sumopod_api_key_encrypted'] = $validated['sumopod_api_key'];
        }
        if (!empty($validated['sumopod_signing_secret'])) {
            $updates['sumopod_signing_secret_encrypted'] = $validated['sumopod_signing_secret'];
        }
        if (!empty($validated['sumopod_webhook_token'])) {
            $updates['sumopod_webhook_token_encrypted'] = $validated['sumopod_webhook_token'];
        }
        if (!empty($validated['telegram_bot_token'])) {
            $updates['telegram_bot_token_encrypted'] = $validated['telegram_bot_token'];
        }
        if (!empty($validated['google_maps_api_key'])) {
            $updates['google_maps_api_key_encrypted'] = $validated['google_maps_api_key'];
        }

        $settings->update($updates);

        return back()->with('success', 'Sumopod, Usage Tracking, Telegram & Google Maps settings saved successfully.');
    }

    public function testConnection(): JsonResponse
    {
        $settings = CompanySetting::current();

        return response()->json([
            'environment' => $settings->sumopod_environment,
            'base_url' => $settings->sumopodPaymentsEndpoint(),
            'sandbox_base_url' => $settings->sumopodSandboxBaseUrl(),
            'live_base_url' => $settings->sumopodLiveBaseUrl(),
            'api_key_configured' => !empty($settings->sumopod_api_key_encrypted),
            'signing_secret_configured' => !empty($settings->sumopod_signing_secret_encrypted),
            'webhook_token_configured' => !empty($settings->sumopod_webhook_token_encrypted),
        ]);
    }

    public function testTelegram(TelegramNotificationService $telegram): RedirectResponse
    {
        $result = $telegram->testConnection();

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    public function whatsappTemplates(): View
    {
        if (NotificationTemplate::query()->count() === 0) {
            foreach (NotificationTemplate::defaults() as $item) {
                NotificationTemplate::query()->firstOrCreate(['key' => $item['key']], $item + ['is_active' => true]);
            }
        }

        $templates = NotificationTemplate::query()->orderBy('id')->get();
        $previews = $templates->mapWithKeys(fn (NotificationTemplate $t) => [
            $t->id => WhatsAppService::previewSampleHtml($t->body_template),
        ]);

        return view('admin.whatsapp.templates', [
            'templates' => $templates,
            'previews' => $previews,
            'sampleVars' => WhatsAppService::sampleVariables(),
            'placeholders' => WhatsAppService::availablePlaceholders(),
        ]);
    }

    public function updateWhatsappTemplate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'body_template' => ['required', 'string', 'max:2000'],
        ]);

        $template->update([
            'label' => $data['label'],
            'body_template' => $data['body_template'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', "Template '{$template->label}' updated successfully.");
    }

    private function maskSecret(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', 12) . substr($value, -4);
    }
}
