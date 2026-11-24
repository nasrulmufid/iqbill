<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    public const DEFAULT_SUMOPOD_SANDBOX_BASE_URL = 'https://api-pay-sandbox.sumopod.com/api/v1/payments';

    public const DEFAULT_SUMOPOD_LIVE_BASE_URL = 'https://api-pay.sumopod.com/api/v1/payments';

    protected $fillable = [
        'company_name',
        'company_address',
        'company_phone',
        'invoice_prefix',
        'payment_prefix',
        'default_due_days',
        'grace_period_days',
        'pregenerate_qris_on_invoice',
        'usage_tracking_enabled',
        'sumopod_environment',
        'sumopod_sandbox_base_url',
        'sumopod_live_base_url',
        'sumopod_api_key_encrypted',
        'sumopod_webhook_verification_mode',
        'sumopod_signing_secret_encrypted',
        'sumopod_webhook_token_encrypted',
        'sumopod_fee_bearer',
        'wa_gateway_url',
        'wa_api_token_encrypted',
        'wa_basic_auth_user',
        'wa_basic_auth_password_encrypted',
        'wa_device_id',
        'telegram_enabled',
        'telegram_bot_token_encrypted',
        'telegram_chat_id',
        'telegram_notify_payment',
        'telegram_notify_error',
        'google_maps_api_key_encrypted',
    ];

    protected $hidden = [
        'sumopod_api_key_encrypted',
        'sumopod_signing_secret_encrypted',
        'sumopod_webhook_token_encrypted',
        'wa_api_token_encrypted',
        'wa_basic_auth_password_encrypted',
        'telegram_bot_token_encrypted',
        'google_maps_api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'default_due_days' => 'integer',
            'grace_period_days' => 'integer',
            'pregenerate_qris_on_invoice' => 'boolean',
            'usage_tracking_enabled' => 'boolean',
            'sumopod_api_key_encrypted' => 'encrypted',
            'sumopod_signing_secret_encrypted' => 'encrypted',
            'sumopod_webhook_token_encrypted' => 'encrypted',
            'wa_api_token_encrypted' => 'encrypted',
            'wa_basic_auth_password_encrypted' => 'encrypted',
            'telegram_enabled' => 'boolean',
            'telegram_bot_token_encrypted' => 'encrypted',
            'telegram_notify_payment' => 'boolean',
            'telegram_notify_error' => 'boolean',
            'google_maps_api_key_encrypted' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        $defaults = [
            'company_name' => config('app.name', 'IQ Bill'),
            'invoice_prefix' => 'INV',
            'payment_prefix' => 'PAY',
            'default_due_days' => 7,
            'grace_period_days' => 3,
            'pregenerate_qris_on_invoice' => true,
            'sumopod_environment' => 'sandbox',
            'sumopod_sandbox_base_url' => self::DEFAULT_SUMOPOD_SANDBOX_BASE_URL,
            'sumopod_live_base_url' => self::DEFAULT_SUMOPOD_LIVE_BASE_URL,
            'sumopod_webhook_verification_mode' => 'auto',
            'sumopod_fee_bearer' => 'merchant',
        ];

        $gowaUrl = trim((string) config('services.gowa.url', ''));
        if ($gowaUrl !== '') {
            $defaults['wa_gateway_url'] = rtrim($gowaUrl, '/');
            $gowaUser = trim((string) config('services.gowa.basic_auth_user', ''));
            $gowaPass = trim((string) config('services.gowa.basic_auth_password', ''));
            $gowaDevice = trim((string) config('services.gowa.device_id', ''));

            if ($gowaUser !== '') {
                $defaults['wa_basic_auth_user'] = $gowaUser;
            }
            if ($gowaPass !== '') {
                $defaults['wa_basic_auth_password_encrypted'] = $gowaPass;
            }
            if ($gowaDevice !== '') {
                $defaults['wa_device_id'] = $gowaDevice;
            }
        }

        return static::query()->firstOrCreate(['id' => 1], $defaults);
    }

    public function sumopodSandboxBaseUrl(): string
    {
        $configured = rtrim(trim((string) ($this->sumopod_sandbox_base_url ?? '')), '/');

        return $configured !== '' ? $configured : self::DEFAULT_SUMOPOD_SANDBOX_BASE_URL;
    }

    public function sumopodLiveBaseUrl(): string
    {
        $configured = rtrim(trim((string) ($this->sumopod_live_base_url ?? '')), '/');

        return $configured !== '' ? $configured : self::DEFAULT_SUMOPOD_LIVE_BASE_URL;
    }

    public function sumopodBaseUrl(): string
    {
        return $this->sumopod_environment === 'live'
            ? $this->sumopodLiveBaseUrl()
            : $this->sumopodSandboxBaseUrl();
    }

    public function sumopodPaymentsEndpoint(): string
    {
        $url = rtrim($this->sumopodBaseUrl(), '/');

        return preg_match('#/payments$#i', $url) === 1
            ? $url
            : "{$url}/payments";
    }
}
