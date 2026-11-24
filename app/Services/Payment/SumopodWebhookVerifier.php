<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Http\Request;

final class SumopodWebhookVerifier
{
    private const TOLERANCE_SECONDS = 300;

    /**
     * Verify Sumopod webhook authenticity via Svix HMAC-SHA256 signature or static X-Webhook-Token.
     *
     * @return array{valid: bool, method: string, reason: ?string}
     */
    public function verify(
        Request $request,
        string $mode,
        ?string $signingSecret,
        ?string $webhookToken
    ): array {
        $rawBody = $request->getContent();
        $svixId = $request->header('svix-id');
        $svixTimestamp = $request->header('svix-timestamp');
        $svixSignature = $request->header('svix-signature');
        $headerToken = $request->header('X-Webhook-Token');

        $hasSigningSecret = !empty($signingSecret);

        if ($mode === 'signature' || ($mode === 'auto' && $hasSigningSecret)) {
            return $this->verifySvixSignature(
                $rawBody,
                $svixId,
                $svixTimestamp,
                $svixSignature,
                $signingSecret
            );
        }

        if ($mode === 'token' || ($mode === 'auto' && $headerToken !== null)) {
            return $this->verifyStaticToken($headerToken, $webhookToken);
        }

        return [
            'valid' => false,
            'method' => 'none',
            'reason' => 'Missing webhook authentication headers.',
        ];
    }

    private function verifySvixSignature(
        string $rawBody,
        ?string $svixId,
        ?string $svixTimestamp,
        ?string $svixSignature,
        ?string $signingSecret
    ): array {
        if (empty($svixId) || empty($svixTimestamp) || empty($svixSignature) || empty($signingSecret)) {
            return ['valid' => false, 'method' => 'svix_hmac', 'reason' => 'Incomplete Svix headers or secret.'];
        }

        if (!ctype_digit($svixTimestamp) || abs(time() - (int) $svixTimestamp) > self::TOLERANCE_SECONDS) {
            return ['valid' => false, 'method' => 'svix_hmac', 'reason' => 'Timestamp outside tolerance window.'];
        }

        $cleanSecret = str_starts_with($signingSecret, 'whsec_')
            ? substr($signingSecret, 6)
            : $signingSecret;

        $secretBytes = base64_decode($cleanSecret, true);
        if ($secretBytes === false || $secretBytes === '') {
            return ['valid' => false, 'method' => 'svix_hmac', 'reason' => 'Invalid base64 signing secret.'];
        }

        $signedContent = "{$svixId}.{$svixTimestamp}.{$rawBody}";
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

        foreach (explode(' ', trim($svixSignature)) as $versionedSig) {
            $parts = explode(',', $versionedSig, 2);
            if (count($parts) === 2 && $parts[0] === 'v1' && hash_equals($expectedSignature, $parts[1])) {
                return ['valid' => true, 'method' => 'svix_hmac', 'reason' => null];
            }
        }

        return ['valid' => false, 'method' => 'svix_hmac', 'reason' => 'HMAC signature mismatch.'];
    }

    private function verifyStaticToken(?string $headerToken, ?string $configuredToken): array
    {
        if (empty($headerToken) || empty($configuredToken)) {
            return ['valid' => false, 'method' => 'webhook_token', 'reason' => 'Missing webhook token.'];
        }

        if (!hash_equals($configuredToken, $headerToken)) {
            return ['valid' => false, 'method' => 'webhook_token', 'reason' => 'Webhook token mismatch.'];
        }

        return ['valid' => true, 'method' => 'webhook_token', 'reason' => null];
    }
}
