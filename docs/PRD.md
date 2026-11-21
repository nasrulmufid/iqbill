# Product Requirements Document (`PRD.md`)

**Nama Produk:** ISP Billing, MikroTik Provisioning & Sumopod Dynamic QRIS Portal  
**Versi Dokumen:** 2.0 (Arsitektur Smart Payment Link & Attempt-Suffixed QRIS)  
**Target Stack:** Laravel 11, Livewire 3, Tailwind CSS, MySQL 8.0+, Redis, MikroTik RouterOS API, Sumopod Managed Payment

---

## 1. Latar Belakang & Tujuan Produk

Penyedia layanan internet (ISP / RT-RW Net) membutuhkan sistem penagihan otomatis yang meminimalkan friksi pembayaran pelanggan sekaligus mengotomatiskan kendali jaringan pada router MikroTik.

### Masalah Utama yang Diselesaikan:
1. **Friksi Login Pelanggan:** Pelanggan sering menunda pembayaran karena lupa kata sandi portal saat menerima pesan tagihan di WhatsApp. Pelanggan harus dapat membayar hanya dengan **1 kali klik tautan di WhatsApp tanpa login**, maupun melalui **Customer Portal**.
2. **Batasan Kedaluwarsa `order_id` Sumopod:** Pada Sumopod Managed Payment, setiap transaksi QRIS memiliki masa berlaku `expires_at` ($24$ jam). Setelah melewati `expires_at`, Sumopod menolak pembuatan ulang transaksi menggunakan `order_id` yang sama.
3. **Keterlambatan Pemulihan Isolir:** Pelanggan yang terisolir membutuhkan pembukaan blokir jaringan secara instan dalam hitungan detik setelah memindai QRIS tanpa menunggu verifikasi manual admin.

---

## 2. Peran Pengguna (*User Personas*)

| Peran (`role`) | Tanggung Jawab & Hak Akses Utama |
| :--- | :--- |
| **`super_admin`** | Mengelola konfigurasi ISP, kredensial API Sumopod, router MikroTik, akun staf, laporan keuangan penuh, dan audit log. |
| **`finance`** | Memantau invoice, mencatat pembayaran tunai loket, dan mengunduh laporan rekonsiliasi bruto, potongan biaya QRIS, dan pendapatan bersih. |
| **`noc`** | Mengelola perangkat NAS MikroTik, memicu aktivasi awal PPPoE/Hotspot, serta melakukan isolir/pemulihan teknis dan inspeksi log jaringan. |
| **`customer`** | Mengakses Customer Portal untuk melihat status layanan, riwayat tagihan, kode QRIS aktif, dan tombol pembayaran langsung ke Sumopod (serta dapat membayar tanpa login via tautan WhatsApp). |

---

## 3. Kebutuhan Fungsional (*Functional Requirements*)

### FR-01: Manajemen Pelanggan, Paket & Langganan
* Sistem mengelola data identitas pelanggan (`customers`), katalog paket internet beserta pemetaan profil bandwidth MikroTik (`packages`), dan kontrak langganan per titik instalasi (`subscriptions`).
* Setiap langganan memiliki tanggal siklus penagihan bulanan (`billing_day` $1 \dots 28$) dan status operasional (`pending_installation`, `active`, `isolated`, `suspended`, `terminated`).

### FR-02: Otomatisasi Provisioning & Auto-Isolir MikroTik
* **Aktivasi:** Pembuatan akun `/ppp secret` atau `/ip hotspot user` otomatis saat layanan diaktifkan.
* **Auto-Isolir:** Scheduler harian memblokir akses internet pelanggan yang melewati `due_date + grace_period_days` dengan memasukkan IP pelanggan ke `/ip firewall address-list` (`ISOLIR`) dan memutus sesi aktif.
* **Auto-Restore:** Begitu pelunasan dikonfirmasi oleh webhook Sumopod (atau pembayaran tunai admin), sistem menghapus IP dari `ISOLIR` dan mereset sesi aktif agar internet langsung pulih.

### FR-03: Model Penagihan *Single-Invoice-Per-Cycle*
* Sistem menerbitkan **tepat 1 invoice per periode tagihan** (`INV-YYYYMM-XXXXXX`) pada `H-7` sebelum `next_billing_date`.
* Invoice tidak perlu diterbitkan ulang menjadi nomor invoice baru ketika QRIS kedaluwarsa; nomor invoice tetap sama hingga lunas atau dibatalkan admin.
* Setiap invoice dibekali `public_token` acak kriptografis ($32$ karakter) untuk akses pembayaran cepat tanpa login.

### FR-04: Integrasi Sumopod Dynamic QRIS & Pemisahan `payment_code`
* Untuk mengatasi batasan Sumopod yang mengunci `order_id` setelah `expires_at` ($24$ jam) habis, sistem memisahkan `invoices.invoice_number` dari kode transaksi yang dikirim ke Sumopod (`payments.payment_code`).
* Setiap percobaan pembuatan pembayaran menghasilkan `payment_code` unik berakhiran urutan (*attempt suffix*), misalnya `PAY-202611-000123-01`, `PAY-202611-000123-02`, dan seterusnya, yang seluruhnya terikat pada `invoice_id` induk yang sama.
* Waktu kedaluwarsa `payments.qris_expires_at` disinkronkan langsung dari field `expires_at` respons API Sumopod (`Asia/Jakarta`).

### FR-05: Smart Payment Link Tanpa Login (`/bayar/{public_token}`) & Customer Portal
* **Via WhatsApp (Tanpa Login):** Pesan WhatsApp memuat tautan abadi `https://<domain-isp>/bayar/{public_token}`. Saat diklik:
  * Jika invoice sudah `paid`, tampilkan halaman status publik **"Tagihan Sudah Lunas"** (dengan penyamaran data pribadi).
  * Jika masih ada sesi pembayaran Sumopod yang aktif (`now < qris_expires_at`), server langsung melakukan *HTTP 302 Redirect* ke `payment_link_url` Sumopod.
  * Jika sesi pembayaran sebelumnya sudah kedaluwarsa (`now >= qris_expires_at`) atau belum dibuat, server otomatis membuat sesi `payment_code` baru (`-02`, `-03`) ke API Sumopod dan langsung melakukan *HTTP 302 Redirect* ke `payment_link_url` baru tersebut.
* **Via Customer Portal (Dengan Login):** Pelanggan yang login ke portal dapat melihat rincian invoice, memindai gambar QRIS (`qris_string`) secara langsung, atau menekan tombol **"Bayar via Sumopod"** yang membuka `payment_link_url` aktif (dan otomatis memperbarui sesi jika yang lama telah melewati `expires_at`).

### FR-06: Pemrosesan *Push Webhook* Real-Time & *Late Webhook*
* Sumopod tidak menyediakan API polling status transaksi; pelunasan diproses melalui `POST /api/webhooks/sumopod` dengan verifikasi **Svix HMAC-SHA256** atau **`X-Webhook-Token`**.
* Jika pelanggan membayar sesi lama (`-01`) pada detik terakhir tepat setelah sesi pengganti (`-02`) dibuat, sistem tetap melunasi invoice induknya dan otomatis membatalkan sesi pengganti (`-02`).

### FR-07: Notifikasi WhatsApp Terjadwal
* Pengiriman otomatis via antrean (*Queue*) untuk event: `invoice_created` (`H-7`), `reminder_h3`, `reminder_h1`, `service_isolated`, `payment_receipt`, dan `service_restored`.

### FR-08: Laporan Keuangan & Rekonsiliasi Gateway
* Mencatat dan menampilkan nominal bruto (`amount`), potongan biaya transaksi QRIS Sumopod (`gateway_fee`, sebesar $0.7\% + \text{Rp } 300$), dan dana bersih diterima (`net_amount`).

---

## 4. Kebutuhan Non-Fungsional (*Non-Functional Requirements*)

1. **SLA Webhook:** Endpoint `/api/webhooks/sumopod` wajib merespons HTTP `2xx` dalam waktu $< 10$ detik dengan delegasi tugas MikroTik dan WhatsApp ke Redis Queue.
2. **Proteksi Konkurensi & Anti-Spam:** Pembuatan ulang sesi QRIS pada `/bayar/{public_token}` wajib dilindungi *Redis Atomic Lock* dan *rate limiter* agar klik ganda pelanggan tidak memicu duplikasi transaksi ke API Sumopod.
3. **Keamanan Kredensial:** Seluruh API key, signing secret (`whsec_...`), token webhook (`whtok_...`), dan kata sandi MikroTik wajib dienkripsi *at-rest* (`AES-256`).
4. **Zona Waktu:** Seluruh tampilan tanggal dan jadwal penagihan menggunakan `Asia/Jakarta` (`UTC+7`).