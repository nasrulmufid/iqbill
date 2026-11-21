# Arsitektur Keamanan, Kriptografi & Verifikasi Webhook (`SECURITY.md`)

Dokumen ini menetapkan standar keamanan aplikasi untuk melindungi transaksi finansial, mencegah pemalsuan notifikasi pelunasan (*webhook spoofing*), mengamankan rute publik **Smart Payment Link (`/bayar/{public_token}`)** dari penyalahgunaan, dan menjaga kerahasiaan kredensial infrastruktur jaringan MikroTik.

---

## 1. Verifikasi Autentikasi Webhook Sumopod (Dual-Mode)

Endpoint `POST /api/webhooks/sumopod` merupakan titik kritis karena pelunasan otomatis membuka blokir isolir layanan internet pelanggan. Setiap request masuk wajib melewati middleware `VerifySumopodWebhook` sebelum menyentuh logika bisnis.

### 1.1. Metode Utama: Svix HMAC-SHA256 (`whsec_...`)
Apabila request menyertakan header `svix-id`, `svix-timestamp`, dan `svix-signature`:
1. **Validasi Jendela Waktu (*Anti-Replay Window*):**
   * Pastikan selisih waktu antara server saat ini dan `svix-timestamp` tidak melebihi $300$ detik ($|\text{now}() - \text{timestamp}| \le 300$). Tolak dengan `401 Unauthorized` jika kedaluwarsa.
2. **Dekode Kunci Rahasia (`whsec_`):**
   * Ambil nilai `company_settings.sumopod_signing_secret_encrypted`, dekripsi, lalu hapus awalan `whsec_`.
   * Lakukan `base64_decode()` terhadap sisa string untuk memperoleh *raw binary key* HMAC.
3. **Konstruksi *Signed Payload* dari Raw HTTP Body:**
   * Gunakan isi body HTTP mentah persis seperti yang diterima oleh PHP (`$request->getContent()`), **bukan** hasil serialisasi ulang array `json_encode($request->all())` yang berisiko mengubah spasi atau urutan properti JSON.
   * Susun string tanda tangan dengan format mutlak:
     $$\text{signed\_content} = \text{svix\_id} + \text{"."} + \text{svix\_timestamp} + \text{"."} + \text{raw\_body}$$
4. **Komparasi *Constant-Time* (`hash_equals`):**
   * Hitung `base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true))`.
   * Pecah header `svix-signature` berdasarkan spasi (karena Svix dapat mengirim lebih dari satu tanda tangan saat rotasi kunci, misalnya `v1,<sig1> v1,<sig2>`).
   * Cocokkan setiap tanda tangan berawalan `v1,` menggunakan `hash_equals()` untuk mencegah *timing attack*.

### 1.2. Metode Alternatif: Static Webhook Token (`X-Webhook-Token`)
Apabila admin mengonfigurasi mode `token` (atau mode `auto` ketika header `X-Webhook-Token` dikirim oleh Sumopod):
1. Ambil nilai header `X-Webhook-Token`.
2. Bandingkan dengan nilai terdekripsi dari `company_settings.sumopod_webhook_token_encrypted` (`whtok_...`) menggunakan `hash_equals()`.

### 1.3. Implementasi Referensi `SumopodWebhookVerifier`
```php
<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Http\Request;

final class SumopodWebhookVerifier
{
    private const TOLERANCE_SECONDS = 300;

    /**
     * Memverifikasi keaslian webhook Sumopod melalui Svix HMAC-SHA256 atau X-Webhook-Token.
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

        if ($mode === 'signature' || ($mode === 'auto' && $svixSignature !== null)) {
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
        if ($secretBytes === false) {
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

    private function verifyStaticToken(?string $headerToken, ?string $configuredToken): array {
        if (empty($headerToken) || empty($configuredToken)) {
            return ['valid' => false, 'method' => 'webhook_token', 'reason' => 'Missing webhook token.'];
        }

        if (!hash_equals($configuredToken, $headerToken)) {
            return ['valid' => false, 'method' => 'webhook_token', 'reason' => 'Webhook token mismatch.'];
        }

        return ['valid' => true, 'method' => 'webhook_token', 'reason' => null];
    }
}
```

---

## 2. Keamanan Smart Payment Link Publik (`/bayar/{public_token}`)

Karena endpoint `GET /bayar/{public_token}` dapat diakses tanpa login dari pesan WhatsApp, empat lapisan pengamanan wajib diterapkan:

1. **Entropi Token Tinggi (`192-bit`):**
   * Nilai `invoices.public_token` dibuat menggunakan `Str::random(32)` (kombinasi alfanumerik acak kriptografis) dan tidak pernah menggunakan nomor invoice berurutan sehingga mustahil ditebak melalui enumerasi URL.
2. **Penyamaran Data Pribadi (*Privacy Masking*):**
   * Halaman publik `public.payment-paid` (yang tampil jika link diklik setelah tagihan lunas) **dilarang** menampilkan NIK/NPWP, alamat lengkap instalasi, nomor WhatsApp, maupun username/password PPPoE. Nama pelanggan wajib disamarkan (misalnya `B*** S******`).
3. **Proteksi *Race Condition* & Spam API (`Redis Atomic Lock`):**
   * Pembuatan sesi pembayaran baru ke Sumopod dibungkus dalam `Cache::lock("reissue_payment:{$invoice->id}", 10)->block(5, ...)` agar klik berulang kali secara cepat tidak memicu pembuatan puluhan `payment_code` ke server Sumopod.
4. **Rate Limiting Per IP & Per Token:**
   * Rute `/bayar/{public_token}` dibatasi maksimal $20$ request per menit per IP (`throttle:20,1`) untuk mencegah serangan *denial-of-wallet* / pemborosan kuota API.

---

## 3. Idempotency & Perlindungan Konkurensi Database

1. **Idempotency Tingkat Protokol (`svix-id`):**
   * Kolom `payment_webhook_logs.svix_id` memiliki constraint `UNIQUE`. Pengiriman ulang webhook oleh Sumopod dikenali secara instan dan direspons `200 OK` tanpa memicu aksi ganda.
2. **Penguncian Baris Pesimistis (`lockForUpdate`):**
   * Seluruh perubahan status pada `payments` dan `invoices` di dalam `PaymentSettlementService` menggunakan `SELECT ... FOR UPDATE` dalam `DB::transaction()`.
3. **Validasi Nominal Pembayaran:**
   * `PaymentSettlementService` memastikan `(int) $payload['data']['amount'] === (int) round($payment->amount)` sebelum menandai invoice lunas.

---

## 4. Enkripsi Kredensial & Kontrol Akses (RBAC)

* **Enkripsi At-Rest (AES-256-CBC via Laravel `Crypt`):**
  * Melindungi `sumopod_api_key_encrypted`, `sumopod_signing_secret_encrypted`, `sumopod_webhook_token_encrypted`, `wa_api_token_encrypted`, `mikrotik_routers.password_encrypted`, dan `subscriptions.service_password_encrypted`.
* **Masking Antarmuka Admin:**
  * Input kredensial hanya menampilkan $4$ karakter terakhir (`whsec_••••••••••••••••9a2f`) dan tidak pernah mengirimkan rahasia lama kembali ke DOM HTML.
* **Isolasi Data Customer Portal:**
  * Seluruh aksi pada `/portal/invoices/{invoice}/*` dilindungi `InvoicePolicy` yang memverifikasi `$user->customer?->id === $invoice->customer_id`.