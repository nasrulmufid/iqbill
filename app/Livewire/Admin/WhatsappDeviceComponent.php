<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\CompanySetting;
use App\Services\Notification\GowaClient;
use Illuminate\View\View;
use Livewire\Component;

class WhatsappDeviceComponent extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $devices = [];

    // Go-WhatsApp (GOWA) server connection settings
    public string $waGatewayUrl = '';

    public string $waDeviceId = '';

    public string $waBasicAuthUser = '';

    public string $waBasicAuthPassword = '';

    public ?string $qrDataUri = null;

    public int $qrDuration = 30;

    public ?string $pairCode = null;

    public string $pairPhone = '';

    public string $testPhone = '';

    public string $testMessage = 'Hello! This is a test message from IQ Bill Go-WhatsApp.';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $settings = CompanySetting::current();
        $this->waGatewayUrl = (string) ($settings->wa_gateway_url ?? '');
        $this->waDeviceId = (string) ($settings->wa_device_id ?? '');
        $this->waBasicAuthUser = (string) ($settings->wa_basic_auth_user ?? '');

        if ($this->waGatewayUrl !== '') {
            $this->refreshDevices();
        }
    }

    public function saveGatewaySettings(): void
    {
        $this->reset(['errorMessage', 'statusMessage']);

        $this->validate([
            'waGatewayUrl' => ['nullable', 'url', 'max:255'],
            'waDeviceId' => ['nullable', 'string', 'max:100'],
            'waBasicAuthUser' => ['nullable', 'string', 'max:100'],
            'waBasicAuthPassword' => ['nullable', 'string', 'max:255'],
        ], [
            'waGatewayUrl.url' => 'Go-WhatsApp Base URL must be a valid URL (e.g. http://gowa:3000 or http://127.0.0.1:3000).',
        ]);

        $settings = CompanySetting::current();
        $updates = [
            'wa_gateway_url' => trim($this->waGatewayUrl) !== '' ? rtrim(trim($this->waGatewayUrl), '/') : null,
            'wa_device_id' => trim($this->waDeviceId) !== '' ? trim($this->waDeviceId) : null,
            'wa_basic_auth_user' => trim($this->waBasicAuthUser) !== '' ? trim($this->waBasicAuthUser) : null,
        ];

        if (trim($this->waBasicAuthPassword) !== '') {
            $updates['wa_basic_auth_password_encrypted'] = trim($this->waBasicAuthPassword);
        }

        $settings->update($updates);
        $this->waBasicAuthPassword = '';

        if (!empty($updates['wa_gateway_url'])) {
            $this->refreshDevices();
            if ($this->errorMessage === null) {
                $this->statusMessage = 'Go-WhatsApp (GOWA) configuration saved and connected.';
            } else {
                $this->statusMessage = 'Go-WhatsApp configuration saved.';
            }
        } else {
            $this->devices = [];
            $this->statusMessage = 'Go-WhatsApp configuration saved successfully.';
        }
    }

    public function refreshDevices(): void
    {
        $this->errorMessage = null;
        $res = app(GowaClient::class)->getDevices();
        $this->devices = $res['devices'];

        if (!$res['ok']) {
            $this->errorMessage = $res['error'];
        }
    }

    public function requestQr(): void
    {
        $this->reset(['errorMessage', 'statusMessage', 'pairCode']);
        $res = app(GowaClient::class)->getLoginQr();

        if ($res['ok'] && !empty($res['qr_data_uri'])) {
            $this->qrDataUri = $res['qr_data_uri'];
            $this->qrDuration = $res['qr_duration'];
            $this->statusMessage = $res['message'];
        } else {
            $this->qrDataUri = null;
            $this->errorMessage = $res['error'] ?? 'Failed to load QR code from GOWA.';
        }
    }

    public function requestPairCode(): void
    {
        $this->validate(['pairPhone' => ['required', 'string', 'min:8']]);
        $this->reset(['errorMessage', 'statusMessage', 'qrDataUri']);

        $res = app(GowaClient::class)->loginWithCode($this->pairPhone);
        if ($res['ok']) {
            $this->pairCode = $res['code'];
            $this->statusMessage = $res['message'];
        } else {
            $this->errorMessage = $res['message'];
        }
    }

    public function reconnect(): void
    {
        $res = app(GowaClient::class)->reconnect();
        $this->statusMessage = $res['ok'] ? $res['message'] : null;
        $this->errorMessage = !$res['ok'] ? $res['message'] : null;
        $this->refreshDevices();
    }

    public function logoutDevice(): void
    {
        $res = app(GowaClient::class)->logout();
        $this->qrDataUri = null;
        $this->statusMessage = $res['ok'] ? $res['message'] : null;
        $this->errorMessage = !$res['ok'] ? $res['message'] : null;
        $this->refreshDevices();
    }

    public function sendTest(): void
    {
        $this->validate([
            'testPhone' => ['required', 'string', 'min:8'],
            'testMessage' => ['required', 'string', 'max:1000'],
        ]);

        $res = app(GowaClient::class)->sendMessage($this->testPhone, $this->testMessage);
        if ($res['success']) {
            $this->statusMessage = 'Test message sent successfully!';
            $this->errorMessage = null;
        } else {
            $this->errorMessage = 'Failed to send test message: ' . $res['response'];
        }
    }

    public function render(): View
    {
        $settings = CompanySetting::current();

        return view('livewire.admin.whatsapp-device-component', [
            'gatewayUrl' => $settings->wa_gateway_url,
            'deviceId' => $settings->wa_device_id,
            'hasBasicAuthPassword' => !empty($settings->wa_basic_auth_password_encrypted),
        ])->layout('layouts.app', ['title' => 'Go-WhatsApp Web Multidevice (GOWA)']);
    }
}
