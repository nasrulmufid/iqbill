<?php

declare(strict_types=1);

use App\Models\CompanySetting;
use App\Services\Payment\SumopodWebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('CompanySetting encrypts sensitive credentials at rest in database', function () {
    $setting = CompanySetting::current();
    $setting->update([
        'sumopod_api_key_encrypted' => 'sk_live_very_secret_key',
        'sumopod_signing_secret_encrypted' => 'whsec_secret_123',
    ]);

    $rawRow = DB::table('company_settings')->where('id', $setting->id)->first();

    expect($rawRow->sumopod_api_key_encrypted)->not->toBe('sk_live_very_secret_key')
        ->and($rawRow->sumopod_signing_secret_encrypted)->not->toBe('whsec_secret_123')
        ->and($setting->fresh()->sumopod_api_key_encrypted)->toBe('sk_live_very_secret_key')
        ->and($setting->fresh()->sumopod_signing_secret_encrypted)->toBe('whsec_secret_123');
});

test('SumopodWebhookVerifier supports Svix key rotation and static X-Webhook-Token', function () {
    $verifier = new SumopodWebhookVerifier();
    $rawSecret = base64_encode('test-secret-key');
    $whsec = 'whsec_' . $rawSecret;
    $body = '{"event_type":"payment.completed"}';
    $ts = (string) time();
    $id = 'msg_123';
    $validSig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", base64_decode($rawSecret), true));

    $svixRequest = Request::create('/api/webhooks/sumopod', 'POST', [], [], [], [
        'HTTP_SVIX_ID' => $id,
        'HTTP_SVIX_TIMESTAMP' => $ts,
        'HTTP_SVIX_SIGNATURE' => "v1,wrongsig v1,{$validSig}",
    ], $body);

    $svixResult = $verifier->verify($svixRequest, 'auto', $whsec, null);
    expect($svixResult['valid'])->toBeTrue()
        ->and($svixResult['method'])->toBe('svix_hmac');

    $tokenRequest = Request::create('/api/webhooks/sumopod', 'POST', [], [], [], [
        'HTTP_X_WEBHOOK_TOKEN' => 'whtok_abc123',
    ], $body);

    $tokenResult = $verifier->verify($tokenRequest, 'token', null, 'whtok_abc123');
    expect($tokenResult['valid'])->toBeTrue()
        ->and($tokenResult['method'])->toBe('webhook_token');
});
