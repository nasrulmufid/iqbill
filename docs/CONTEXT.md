# Konteks Arsitektur & Keputusan Desain Sistem (`CONTEXT.md`)

Dokumen ini merangkum gambaran besar arsitektur, batasan domain, dan **Architectural Decision Records (ADR)** yang menjadi pedoman konsistensi teknis di seluruh modul aplikasi **ISP Billing, MikroTik Provisioning & Sumopod Dynamic QRIS Portal**.

---

## 1. Ringkasan Eksekutif Sistem

Aplikasi ini adalah platform terpadu manajemen pelanggan dan penagihan otomatis untuk penyedia layanan internet (ISP / RT-RW Net) yang menggabungkan:
1. **Billing Engine & Customer Portal (Laravel 11 + Livewire 3):** Mengelola siklus tagihan berulang (*single-invoice-per-cycle*), riwayat percobaan pembayaran, dan portal mandiri pelanggan.
2. **Smart Payment Link & Sumopod Dynamic QRIS:** Memungkinkan pelanggan membayar tagihan hanya dengan **1 kali klik tautan di WhatsApp tanpa login** maupun melalui **Customer Portal**, dengan pembaruan sesi otomatis saat kedaluwarsa.
3. **Network Provisioning Engine (MikroTik RouterOS API):** Mengotomatiskan pembuatan akun PPPoE/Hotspot, pemblokiran akses (*auto-isolir*) saat menunggak melewati masa tenggang, dan pemulihan akses instan begitu webhook pelunasan diterima.
4. **Notification Dispatcher (WhatsApp Gateway):** Mengirim tautan bayar abadi (`/bayar/{public_token}`), pengingat jatuh tempo (`H-7`, `H-3`, `H-1`), status isolir, dan kuitansi lunas.

---

## 2. Catatan Keputusan Arsitektur (ADR)

### ADR-001: Model Pelunasan Berbasis *Push Webhook* (Tanpa Polling Status ke Sumopod)
* **Konteks:** Sumopod Managed Payment mengirimkan event HTTP POST webhook (`payment.completed`, `payment.failed`, `payment.expired`) sebagai satu-satunya mekanisme pembaruan status transaksi secara *real-time* dan tidak menyediakan endpoint inquiry polling.
* **Keputusan:** Aplikasi tidak pernah melakukan *polling* status ke server Sumopod. Layar Customer Portal memantau perubahan status pada database lokal (`invoices.status`) setiap $4$ detik sementara backend menerima *push webhook* dari Sumopod dan merespons dalam waktu $< 10$ detik.

### ADR-002: Verifikasi Keaslian Webhook Dual-Mode (`Svix Raw Body` & `X-Webhook-Token`)
* **Konteks:** Sumopod mendukung dua metode autentikasi webhook pada pengaturan proyeknya: tanda tangan kriptografi standar **Svix** (`svix-id`, `svix-timestamp`, `svix-signature` dengan rahasia `whsec_...`) atau header token statis `X-Webhook-Token` (`whtok_...`).
* **Keputusan:** Middleware `VerifySumopodWebhook` mendukung kedua mekanisme tersebut dan menghitung HMAC-SHA256 menggunakan **raw HTTP request body** (`$request->getContent()`) setelah mendekode Base64 kunci rahasia `whsec_`, disertai perlindungan *replay window* $\pm 300$ detik dan penyimpanan `svix-id` unik untuk *idempotency*.

### ADR-003: Pemisahan `invoice_number` vs `payment_code` (`PAY-...-01`, `-02`) untuk Mengatasi Batasan Kedaluwarsa `order_id` Sumopod
* **Konteks:** Pada Sumopod, setelah masa berlaku `expires_at` ($24$ jam) habis, transaksi tersebut kedaluwarsa dan Sumopod menolak permintaan pembuatan pembayaran baru yang menggunakan `order_id` yang sama.
* **Keputusan:** Sistem mempertahankan **tepat 1 invoice per siklus tagihan** (`INV-202611-000123`) namun memisahkan kode transaksi gateway ke tabel `payments` dengan akhiran urutan percobaan (`PAY-202611-000123-01`, `PAY-202611-000123-02`, dst.) sebagai `order_id` Sumopod. Seluruh percobaan terhubung ke satu `invoice_id` induk.

### ADR-004: Smart Payment Link Tanpa Login (`GET /bayar/{public_token}`) dengan *On-Demand Auto-Renewal*
* **Konteks:** Menempelkan URL mentah Sumopod (`https://pay.sumopod.com/...`) ke pesan WhatsApp H-7 menyebabkan tautan mati setelah $24$ jam, sementara mewajibkan pelanggan login ke portal menciptakan friksi pembayaran.
* **Keputusan:** Pesan WhatsApp selalu menyematkan rute publik `GET /bayar/{public_token}`. Saat diklik, backend memeriksa apakah sesi pembayaran aktif masih berlaku (`now < qris_expires_at - 2m`); jika ya, langsung melakukan *HTTP 302 Redirect* ke `payment_url`. Jika sudah kedaluwarsa, backend (dilindungi *Redis Atomic Lock*) otomatis menerbitkan sesi `PAY-...-02` baru ke Sumopod dan langsung mengarahkan pelanggan ke `payment_url` baru tanpa perlu login.

### ADR-005: Prioritas Pelunasan *Late Webhook* di Atas Sesi Pengganti
* **Konteks:** Pelanggan berpotensi memindai QRIS sesi `-01` pada detik-detik terakhir tepat setelah sesi `-02` dibuat.
* **Keputusan:** `PaymentSettlementService` tetap menerima webhook `payment.completed` untuk sesi `-01`, melunasi invoice induknya, dan otomatis membatalkan (`status = 'cancelled'`) sesi `-02` yang masih `pending`.

### ADR-006: Pencatatan Akuntansi Bruto, Biaya Gateway & Neto (`amount`, `gateway_fee`, `net_amount`)
* **Konteks:** Transaksi QRIS Sumopod mengenakan biaya transaksi ($0.7\% + \text{Rp } 300$) dan mengirimkan rincian `amount`, `fee`, dan `net_amount`.
* **Keputusan:** Tabel `payments` menyimpan ketiga komponen nominal tersebut secara eksplisit untuk rekonsiliasi saldo Wallet Sumopod dan laporan keuangan ISP.

---

## 3. Peta Referensi Cepat Kontrak Sumopod

| Komponen Sumopod | Nama Field / Header di Sumopod | Kolom / Komponen di Aplikasi Laravel |
| :--- | :--- | :--- |
| **Endpoint Buat Pembayaran** | `POST /api/v1/payments` | `SumopodGatewayService::createPaymentAttempt()` |
| **Header Autentikasi Outbound** | `X-Api-Key` | `company_settings.sumopod_api_key_encrypted` |
| **ID Transaksi Gateway** | `payment_id` (respons & `data.payment_id`) | `payments.external_reference` |
| **ID Referensi Merchant** | `order_id` (request, respons & `data.order_id`) | `payments.payment_code` (`PAY-202611-000123-01`) |
| **Payload QRIS Mentah** | `payment_code` | `payments.qris_string` |
| **Tautan Checkout Hosted** | `payment_link_url` | `payments.payment_url` |
| **Waktu Kedaluwarsa QRIS** | `expires_at` | `payments.qris_expires_at` |
| **Biaya & Dana Bersih** | `fee` & `net_amount` | `payments.gateway_fee` & `payments.net_amount` |
| **Jenis Event Webhook** | `event_type` (`payment.completed`, `payment.failed`, `payment.expired`) | `payment_webhook_logs.event_type` |
| **Header Svix Webhook** | `svix-id`, `svix-timestamp`, `svix-signature` (`whsec_...`) | `SumopodWebhookVerifier::verifySvixSignature()` & `payment_webhook_logs.svix_id` |
| **Header Token Alternatif** | `X-Webhook-Token` (`whtok_...`) | `SumopodWebhookVerifier::verifyStaticToken()` |