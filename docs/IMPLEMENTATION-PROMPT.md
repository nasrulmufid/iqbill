# Panduan & Prompt Implementasi Bertahap (`IMPLEMENTATION-PROMPT.md`)

Gunakan dokumen ini sebagai cetak biru eksekusi bagi tim developer maupun *coding agent* untuk membangun aplikasi **ISP Billing, MikroTik Provisioning & Sumopod Dynamic QRIS Portal** secara terstruktur dalam $6$ fase.

---

## Stack Teknologi Target
* **Backend Framework:** Laravel 11.x (PHP 8.3+, `strict_types=1`)
* **Frontend & Interaktivitas:** Livewire 3 + Alpine.js + Tailwind CSS (`simplesoftwareio/simple-qrcode` untuk rendering SVG QRIS dari `qris_string`)
* **Database & Cache/Lock:** MySQL 8.0+ & Redis (untuk Queue Worker & Atomic Lock `Cache::lock`)
* **MikroTik Client:** `evilfreelancer/routeros-api-php`
* **Testing Framework:** Pest PHP

---

## Fase 1: Fondasi Database, Model & Enkripsi Pengaturan
**Instruksi Implementasi:**
1. Buat seluruh migrasi database sesuai spesifikasi `DATABASE.md` (`users`, `company_settings`, `mikrotik_routers`, `packages`, `customers`, `subscriptions`, `invoices`, `payments`, `payment_webhook_logs`, `mikrotik_provisioning_logs`, `whatsapp_notification_logs`, `audit_logs`).
2. Pastikan struktur *Single-Invoice-Per-Cycle* dan *Attempt-Suffixed Payments* diterapkan:
   * `invoices`: memiliki `invoice_number` (`UNIQUE`), `public_token` (`VARCHAR(64) UNIQUE`), `payment_attempt_count` (`SMALLINT UNSIGNED DEFAULT 0`), serta `UNIQUE(subscription_id, billing_period_start)`.
   * `payments`: memiliki `invoice_id`, `attempt_number`, `payment_code` (`UNIQUE`, format `PAY-YYYYMM-XXXXXX-01`), `external_reference` (`payment_id` Sumopod), `qris_string` (`payment_code` Sumopod), `payment_url` (`payment_link_url` Sumopod), `qris_expires_at` (`expires_at` Sumopod), `gateway_fee`, `net_amount`, dan `status` (`pending`, `paid`, `failed`, `expired`, `cancelled`).
   * `payment_webhook_logs`: memiliki `svix_id` (`UNIQUE`), `external_reference`, `payment_code`, `event_type`, `verification_method`, dan `processing_status`.
3. Terapkan `encrypted` cast pada model Eloquent untuk seluruh kolom kredensial sensitif dan buat seeder awal (`CompanySettingSeeder`, `AdminUserSeeder`, `PackageSeeder`).

---

## Fase 2: Sumopod Gateway Service, Session Manager & Webhook Verifier
**Instruksi Implementasi:**
1. Buat `App\Services\Payment\SumopodGatewayService`:
   * Menentukan base URL secara dinamis dari `company_settings.sumopod_environment` (`https://api-pay-sandbox.sumopod.com/api/v1` atau `https://api-pay.sumopod.com/api/v1`).
   * Mengirim `POST /api/v1/payments` dengan header `X-Api-Key` dan payload `{"order_id": $paymentCode, "amount": (int) $invoice->total_amount, "currency": "IDR", "payment_method_type_code": "QRIS"}`.
2. Buat `App\Services\Payment\SumopodPaymentSessionService`:
   * Method `getOrCreateActiveSession(Invoice $invoice): Payment`:
     * Mencari `Payment` milik `$invoice` dengan `status = 'pending'` dan `qris_expires_at > now()->addMinutes(2)`. Jika ditemukan, langsung kembalikan record tersebut.
     * Jika tidak ada (atau sudah kedaluwarsa), gunakan `Cache::lock("reissue_payment:{$invoice->id}", 10)->block(5, ...)` untuk:
       1. Memeriksa ulang apakah proses paralel baru saja membuat sesi aktif.
       2. Menandai seluruh sesi `pending` lama pada `$invoice` sebagai `expired`.
       3. Menaikkan `$invoice->payment_attempt_count` ($+1$) dan menyusun `payment_code` baru (`PAY-202611-000123-02`).
       4. Memanggil `SumopodGatewayService` untuk membuat transaksi baru di Sumopod.
       5. Menyimpan `payment_id` ke `external_reference`, `payment_code` ke `qris_string`, `payment_link_url` ke `payment_url`, dan mengonversi `expires_at` dari respons Sumopod ke `Asia/Jakarta` untuk disimpan ke `payments.qris_expires_at`.
3. Buat `App\Services\Payment\SumopodWebhookVerifier` sesuai `SECURITY.md` (mendukung Svix HMAC-SHA256 terhadap `$request->getContent()` dan `X-Webhook-Token`).

---

## Fase 3: Smart Payment Link Controller & Engine Pelunasan (`PaymentSettlementService`)
**Instruksi Implementasi:**
1. Buat `App\Http\Controllers\Public\SmartPaymentRedirectController`:
   * Daftarkan rute publik `GET /bayar/{public_token}` pada `routes/web.php` dengan middleware `throttle:20,1`.
   * Jika `invoice.status === 'paid'`, tampilkan view `public.payment-paid` dengan nama pelanggan yang disamarkan.
   * Jika `invoice.status` adalah `unpaid` atau `overdue`, panggil `SumopodPaymentSessionService::getOrCreateActiveSession($invoice)` dan kembalikan `redirect()->away($payment->payment_url, 302)`.
2. Buat `App\Services\Payment\PaymentSettlementService` untuk `POST /api/webhooks/sumopod`:
   * Cek idempotency berdasarkan `svix-id`.
   * Pada `event_type === 'payment.completed'`:
     * Buka `DB::transaction()` dengan `lockForUpdate()` pada `Payment` (dicari via `external_reference` atau `payment_code`) dan `Invoice` induknya.
     * Validasi nominal `data.amount === (int) round($payment->amount)`.
     * Perbarui `payment` (`status = 'paid'`, `gateway_fee = data.fee`, `net_amount = data.net_amount`, `paid_at`, `settled_at`) dan `invoice` (`status = 'paid'`, `paid_at = now()`).
     * **Penanganan *Late Webhook*:** Batalkan (`status = 'cancelled'`) seluruh `Payment` lain pada `invoice_id` yang sama yang masih berstatus `pending`. Jika `payment` yang dibayar sebelumnya sempat ditandai `expired`, catat `processing_status = 'late_settled'`.
     * Jika `subscription.status` adalah `isolated` atau `pending_installation`, ubah menjadi `active`, majukan `next_billing_date` $+1$ bulan, dan dispatch `MikrotikProvisioningJob($subscription, 'restore', 'webhook_paid')`.
     * Dispatch `SendWhatsAppNotificationJob($customer, 'payment_receipt', $invoice)`.

---

## Fase 4: MikroTik RouterOS Provisioning Engine & Scheduler Penagihan
**Instruksi Implementasi:**
1. Buat `App\Services\Mikrotik\MikrotikProvisioningService` (`syncSecret`, `isolate`, `restore`) dengan pencatatan ke `mikrotik_provisioning_logs`.
2. Daftarkan command terjadwal pada `routes/console.php`:
   * `billing:generate-invoices` (Harian `07:00`): Menerbitkan **1 invoice** $7$ hari sebelum `next_billing_date` lengkap dengan `public_token = Str::random(32)`, membuat sesi pembayaran pertama (`-01`) jika `pregenerate_qris_on_invoice = true`, dan mengirim WhatsApp `invoice_created` berisi URL `route('public.pay', $invoice->public_token)`.
   * `billing:send-reminders` (Harian `08:30`): Mengirim pengingat WhatsApp `H-3` dan `H-1` dengan Smart Payment Link `route('public.pay', $invoice->public_token)`.
   * `billing:isolate-overdue` (Harian `01:00`): Mengisolir langganan yang melewati `due_date + grace_period_days` dan mengirim WhatsApp `service_isolated` berisi Smart Payment Link.

---

## Fase 5: Antarmuka Customer Portal & Backoffice Admin
**Instruksi Implementasi:**
1. **Customer Portal (`Livewire\Portal\InvoicePaymentComponent`):**
   * Menampilkan rincian invoice, gambar QRIS SVG dari `qris_string`, hitung mundur menuju `qris_expires_at`, dan tombol utama **"Buka Halaman Pembayaran Sumopod"** (`payment_url`).
   * Menyediakan aksi `renewSession()` ketika hitung mundur kedaluwarsa habis agar pelanggan di portal juga bisa menerbitkan sesi `PAY-...-02` baru dengan satu klik.
   * Menggunakan `wire:poll.4s` yang memeriksa status lokal invoice tanpa memanggil API Sumopod.
2. **Backoffice Admin & Finance:**
   * Menampilkan daftar percobaan pembayaran per invoice (`-01`, `-02`), laporan keuangan bruto/fee/neto, tombol salin Smart Payment Link, serta log webhook Sumopod.

---

## Fase 6: Suite Pengujian Otomatis (Pest PHP)
**Instruksi Implementasi:**
Buat `tests/Feature/Payment/SumopodSmartLinkAndWebhookTest.php` yang menguji:
1. **Smart Link Active Session:** `GET /bayar/{public_token}` langsung melakukan *HTTP 302 Redirect* ke `payment_url` tanpa memanggil API Sumopod jika sesi `-01` masih aktif.
2. **Smart Link Auto-Renewal on Expiry:** Jika sesi `-01` sudah melewati `qris_expires_at`, `GET /bayar/{public_token}` otomatis membuat sesi `-02` (`order_id: PAY-...-02`) ke Sumopod (`Http::fake()`) dan mengarahkan pelanggan ke `payment_link_url` baru.
3. **Smart Link Already Paid:** Jika invoice sudah `paid`, `GET /bayar/{public_token}` menampilkan halaman publik "Tagihan Sudah Lunas" dengan nama disamarkan.
4. **Webhook Signature & Late Settlement:** Menguji validasi Svix HMAC-SHA256 dan skenario di mana pelanggan membayar sesi `-01` setelah sesi `-02` dibuat $\rightarrow$ invoice menjadi `paid`, sesi `-02` otomatis `cancelled`, dan `MikrotikProvisioningJob` dijalankan.