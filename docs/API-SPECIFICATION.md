# Spesifikasi API Internal & Kontrak Integrasi Sumopod (`API-SPECIFICATION.md`)

Dokumen ini menetapkan kontrak komunikasi HTTP antara aplikasi Laravel dengan **Sumopod Managed Payment API**, spesifikasi *inbound webhook*, spesifikasi rute publik **Smart Payment Link (`/bayar/{public_token}`)**, serta endpoint internal untuk Customer Portal dan Backoffice Admin.

---

## 1. Integrasi Eksternal: Sumopod Managed Payment API

### 1.1. Base URL & Autentikasi
* **Sandbox Base URL:** `https://api-pay-sandbox.sumopod.com/api/v1`
* **Production Base URL:** `https://api-pay.sumopod.com/api/v1`
* **Header Wajib pada Setiap Request Outbound:**
  ```http
  Content-Type: application/json
  Accept: application/json
  X-Api-Key: <DECRYPTED_SUMOPOD_API_KEY>
  ```

---

### 1.2. Create Dynamic QRIS Payment (`POST /api/v1/payments`)

Dipanggil oleh `SumopodGatewayService::createPaymentAttempt(Invoice $invoice)` setiap kali invoice membutuhkan sesi pembayaran baru (`PAY-202611-000123-01`, `-02`, dst.).

#### Request Payload
```json
{
  "order_id": "PAY-202611-000123-02",
  "amount": 150000,
  "currency": "IDR",
  "payment_method_type_code": "QRIS"
}
```

| Field | Tipe | Wajib | Keterangan |
| :--- | :--- | :--- | :--- |
| `order_id` | `string` | Ya | Kode sesi unik `payments.payment_code` (menggunakan akhiran `-01`, `-02` agar tidak ditolak Sumopod setelah sesi lama expired) |
| `amount` | `integer` | Ya | Nominal tagihan bulat dalam Rupiah (IDR) |
| `currency` | `string` | Ya | Nilai tetap `"IDR"` |
| `payment_method_type_code` | `string` | Ya | Nilai tetap `"QRIS"` |

#### Respons Sukses (`200 OK` / `201 Created`)
```json
{
  "payment_id": "pay_8b3d9f12a4c1",
  "order_id": "PAY-202611-000123-02",
  "amount": 150000,
  "fee": 1350,
  "net_amount": 148650,
  "currency": "IDR",
  "payment_method_type_code": "QRIS",
  "payment_code": "00020101021226610016ID.CO.SHOPEE.WWW...",
  "payment_link_url": "https://pay.sumopod.com/p/pay_8b3d9f12a4c1",
  "expires_at": "2026-11-16T01:30:00.000Z",
  "status": "pending"
}
```

#### Pemetaan Respons ke Database Lokal (`payments`):
* `payment_id` $\rightarrow$ `payments.external_reference`
* `order_id` $\rightarrow$ `payments.payment_code`
* `payment_code` $\rightarrow$ `payments.qris_string`
* `payment_link_url` $\rightarrow$ `payments.payment_url`
* `fee` $\rightarrow$ `payments.gateway_fee`
* `net_amount` $\rightarrow$ `payments.net_amount`
* `expires_at` $\rightarrow$ dikonversi ke zona `Asia/Jakarta` dan disimpan ke `payments.qris_expires_at`.

---

## 2. Inbound Webhook Endpoint dari Sumopod

### 2.1. `POST /api/webhooks/sumopod`
Endpoint publik (dikecualikan dari CSRF) untuk menerima perubahan status transaksi secara *real-time* dari Sumopod.

* **Batas Waktu Respons (SLA):** Server **wajib merespons HTTP `2xx` dalam waktu $< 10$ detik**.
* **Header Autentikasi (Dual-Mode):**
  * **Mode Svix Signature:** `svix-id`, `svix-timestamp`, `svix-signature` (`whsec_...`)
  * **Mode Static Token:** `X-Webhook-Token` (`whtok_...`)

#### Struktur Payload Webhook Sumopod
```json
{
  "event_type": "payment.completed",
  "data": {
    "payment_id": "pay_8b3d9f12a4c1",
    "order_id": "PAY-202611-000123-02",
    "amount": 150000,
    "fee": 1350,
    "net_amount": 148650,
    "status": "completed",
    "paid_at": "2026-11-15T01:45:12.000Z",
    "settled_at": "2026-11-17T01:45:12.000Z"
  }
}
```

#### Matriks Respons HTTP ke Sumopod:
| Kode HTTP | Kondisi | Body JSON |
| :--- | :--- | :--- |
| `200 OK` | Webhook valid & berhasil diproses (termasuk kasus *late webhook*) | `{"received": true, "status": "processed"}` |
| `200 OK` | Duplikat `svix-id` atau transaksi sudah berstatus `paid` | `{"received": true, "status": "duplicate_ignored"}` |
| `401 Unauthorized` | Signature Svix tidak cocok, timestamp di luar window $\pm 300\text{s}$, atau `X-Webhook-Token` salah | `{"received": false, "error": "invalid_webhook_signature"}` |
| `422 Unprocessable Entity` | JSON tidak valid atau field `event_type` / `data.payment_id` hilang | `{"received": false, "error": "invalid_payload"}` |

---

## 3. Rute Publik Smart Payment Link (Tanpa Login)

### 3.1. `GET /bayar/{public_token}`
Endpoint web publik yang disematkan pada pesan WhatsApp pelanggan. Tidak memerlukan sesi login.

* **Rate Limiting:** `throttle:20,1` per IP address.
* **Alur & Respons HTTP:**
  1. Cari `Invoice` berdasarkan `public_token`. Jika tidak ditemukan atau berstatus `cancelled`, kembalikan `404 Not Found`.
  2. Jika `invoice.status === 'paid'`, kembalikan `200 OK` merender tampilan publik `public.payment-paid` (menampilkan informasi bahwa tagihan telah lunas beserta waktu pelunasan, tanpa menampilkan data pribadi sensitif pelanggan).
  3. Jika `invoice.status` adalah `unpaid` atau `overdue`:
     * Panggil `SumopodPaymentSessionService::getOrCreateActiveSession($invoice)` (dilindungi *Redis Atomic Lock* selama $10$ detik).
     * Lakukan `return redirect()->away($payment->payment_url, 302)` langsung ke halaman pembayaran Sumopod.
     * *Fallback:* Jika API Sumopod sedang mengalami gangguan sementara, render halaman `public.payment-fallback` dengan pesan ramah dan tombol **"Coba Lagi"** yang memuat ulang `/bayar/{public_token}`.

---

## 4. Endpoint Internal Customer Portal & Backoffice Admin

Seluruh endpoint internal dilindungi middleware `auth` dan kebijakan otorisasi peran (`Policy`).

### 4.1. Customer Portal API

| Method | Endpoint | Deskripsi & Kontrak Respons |
| :--- | :--- | :--- |
| `POST` | `/portal/invoices/{invoice}/session` | Mengambil sesi QRIS aktif (`now < qris_expires_at - 2m`) atau otomatis menerbitkan sesi baru (`PAY-...-02`) ke Sumopod. Mengembalikan JSON: `{"payment_code", "amount", "qris_string", "payment_url", "qris_expires_at"}`. |
| `GET` | `/portal/invoices/{invoice}/status` | Memeriksa status lokal `invoices.status` di database (tanpa memanggil API Sumopod) untuk pembaruan layar *real-time* setiap $4$ detik. Mengembalikan: `{"invoice_status": "paid", "paid_at": "2026-11-15 08:45:12", "service_status": "active"}`. |

### 4.2. Admin, Finance & NOC API

| Method | Endpoint | Hak Akses | Deskripsi |
| :--- | :--- | :--- | :--- |
| `POST` | `/admin/mikrotik/{router}/test-connection` | `super_admin`, `noc` | Menguji koneksi socket API RouterOS dan mengembalikan identitas router (`identity`, `version`, `uptime`). |
| `POST` | `/admin/subscriptions/{subscription}/provision` | `super_admin`, `noc` | Memicu tindakan manual (`isolate`, `restore`, `sync_secret`) ke router MikroTik. |
| `POST` | `/admin/invoices/{invoice}/manual-payment` | `super_admin`, `finance` | Mencatat pembayaran tunai loket (`gateway = 'manual_cash'`), melunasi invoice, membatalkan sesi QRIS `pending`, dan memulihkan layanan. |
| `POST` | `/admin/settings/sumopod/test-connection` | `super_admin` | Memvalidasi format `X-Api-Key` serta kelengkapan konfigurasi webhook Sumopod. |