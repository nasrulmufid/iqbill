# Spesifikasi Antarmuka & Pengalaman Pengguna (`UI-UX-SPECIFICATION.md`)

Dokumen ini merancang alur interaksi dan spesifikasi antarmuka pengguna (**Tailwind CSS + Livewire 3 + Alpine.js**) untuk tiga permukaan pengalaman pengguna: **Smart Payment Link Publik (Tanpa Login)**, **Customer Portal (Dengan Login)**, dan **Backoffice Admin/NOC/Finance**.

---

## 1. Prinsip Desain & Hierarki Visual

* **Mobile-First Payment Experience:** Mayoritas pelanggan membuka tautan tagihan dari aplikasi WhatsApp di ponsel. Alur menuju halaman pembayaran Sumopod harus bebas hambatan (*zero-login friction*) dengan tombol aksi berukuran minimal tinggi `48px` (`min-h-12`) agar mudah dijangkau ibu jari.
* **Kejelasan Status Jaringan & Tagihan:** Menggunakan kode warna semantik yang konsisten di seluruh aplikasi:
  * **Hijau (`emerald-600`):** Layanan Aktif / Tagihan Lunas (`paid`).
  * **Kuning/Amber (`amber-600`):** Menunggu Pembayaran (`unpaid`) / Mendekati Jatuh Tempo.
  * **Merah (`rose-600`):** Terisolir (`isolated`) / Menunggak (`overdue`).
  * **Abu-abu (`slate-500`):** Sesi QRIS Kedaluwarsa (`expired`) / Dibatalkan (`cancelled`).

---

## 2. Alur & Tampilan Smart Payment Link Publik (`/bayar/{public_token}`)

Ketika pelanggan mengetuk tautan `https://<domain-isp>/bayar/{public_token}` di WhatsApp:

### 2.1. Skenario A: Invoice Belum Lunas (Alur Utama — Auto-Redirect)
* Pelanggan **tidak melihat form login maupun halaman perantara yang membingungkan**.
* Server segera menyiapkan sesi Sumopod yang aktif (atau memperbaruinya otomatis jika sesi sebelumnya sudah melewati `expires_at`) dan langsung mengarahkan browser (*HTTP 302 Redirect*) ke halaman checkout resmi Sumopod (`payment_link_url`).

### 2.2. Skenario B: Invoice Sudah Lunas (`resources/views/public/payment-paid.blade.php`)
Ditampilkan apabila pelanggan mengklik kembali tautan WhatsApp untuk tagihan yang sudah dibayar:
* **Kartu Status Terpusat (`max-w-md mx-auto`):**
  * Ikon centang hijau besar (`emerald-100` / `emerald-600`) dengan judul **"Tagihan Sudah Lunas"**.
  * Rincian ringkas dengan penyamaran privasi:
    * Nomor Invoice: `INV-202611-000123`
    * Nama Pelanggan (Disamarkan): `B*** S******`
    * Paket Layanan: `Home Fiber 30 Mbps`
    * Total Dibayar: `Rp 150.000`
    * Waktu Lunas: `15 Nov 2026, 08:45 WIB`
  * Tombol sekunder **"Masuk ke Portal Pelanggan"** bagi pelanggan yang ingin mengunduh kuitansi PDF lengkap atau melihat riwayat bulan-bulan sebelumnya.

### 2.3. Skenario C: Gangguan Koneksi Gateway Sementara (`public/payment-fallback.blade.php`)
* Apabila server Sumopod sedang mengalami *timeout*, tampilkan kartu pemberitahuan bersih: **"Sedang Menghubungkan ke Sistem Pembayaran"** dengan tombol utama **"Coba Hubungkan Ulang"** yang memuat ulang `/bayar/{public_token}`.

---

## 3. Antarmuka Customer Portal (Dengan Login)

Bagi pelanggan yang login ke `/portal`, seluruh pengalaman pembayaran tersinkronisasi dengan sesi Sumopod yang sama:

### 3.1. Dashboard Pelanggan (`/portal/dashboard`)
* **Kartu Status Koneksi Internet:** Menampilkan nama paket, username PPPoE, status koneksi (`Aktif` atau `Terisolir`), dan tanggal jatuh tempo berikutnya.
* **Banner Tagihan Aktif (Jika ada `unpaid`/`overdue`):**
  * Menampilkan nomor invoice (`INV-202611-000123`), nominal tagihan, dan **dua tombol aksi berdampingan**:
    1. **Tombol Utama (Solid Indigo/Blue):** **"Bayar di Sumopod"** — langsung membuka `payment_url` Sumopod aktif (atau menerbitkan sesi baru `-02` secara otomatis jika sesi lama expired).
    2. **Tombol Sekunder (Outline):** **"Lihat Detail & QRIS"** — membuka halaman detail invoice dengan kode QRIS di layar.

### 3.2. Halaman Detail Tagihan & QRIS (`/portal/invoices/{invoice}`)
Menggunakan komponen `Livewire\Portal\InvoicePaymentComponent`:
* **Kolom Kiri — Rincian Faktur:** Nomor invoice, periode layanan, harga pokok, PPN, dan total tagihan.
* **Kolom Kanan — Panel Pembayaran Dynamic QRIS:**
  * Merender **gambar QRIS SVG** tajam dari `payments.qris_string` menggunakan `SimpleSoftwareIO\QrCode` sehingga pelanggan yang membuka portal di laptop dapat langsung memindainya dari ponsel.
  * **Hitung Mundur Real-Time (Alpine.js):** Menghitung mundur sisa waktu menuju `payments.qris_expires_at` (yang disinkronkan dari server Sumopod).
  * **Tombol Utama:** **"Buka Halaman Pembayaran Sumopod"** (`href="{{ $activePayment->payment_url }}"` `target="_blank"`).
  * **Pembaruan Otomatis Saat Kedaluwarsa:** Ketika timer mencapai `00:00:00`, panel menampilkan tombol **"Buat Sesi QRIS Baru"** yang memanggil `renewPaymentSession()` untuk menghasilkan `PAY-...-02` beserta QRIS dan `payment_url` baru tanpa membuat invoice baru.
  * **Deteksi Pelunasan Real-Time:** Menggunakan `wire:poll.4s="checkLocalStatus"` yang membaca status lokal invoice; begitu webhook Sumopod masuk, panel langsung bertransisi ke tampilan **"Pembayaran Berhasil Diterima"** dengan konfeti/ikon centang hijau.

---

## 4. Antarmuka Backoffice (Admin, Finance & NOC)

### 4.1. Detail Invoice & Riwayat Percobaan Bayar (`/admin/invoices/{invoice}`)
* Menampilkan informasi invoice induk (`INV-202611-000123`), tombol salin **Smart Payment Link** (`/bayar/{public_token}`) untuk dibagikan manual oleh CS via WhatsApp jika diminta pelanggan, serta **Tabel Riwayat Sesi Pembayaran (`payments`)**:
  * Menampilkan seluruh percobaan pembayaran pada invoice tersebut, misalnya:
    * `PAY-202611-000123-01` — Status: `Expired` (Kedaluwarsa pada `16 Nov 2026 07:00`)
    * `PAY-202611-000123-02` — Status: `Paid` (`pay_8b3d9f12a4c1`, Bruto `Rp 150.000`, Fee `Rp 1.350`, Neto `Rp 148.650`)
* Menyediakan tombol **"Catat Pembayaran Tunai"** bagi peran `finance` / `super_admin` untuk pembayaran langsung di kantor ISP.

### 4.2. Pengaturan Gateway Sumopod (`/admin/settings/payment`)
* Pilihan Environment (`Sandbox` vs `Live`), input masked untuk `X-Api-Key`, pilihan mode verifikasi webhook (`Auto`, `Svix Signature`, `X-Webhook-Token`), input masked untuk `Signing Secret (whsec_...)` dan `Webhook Token (whtok_...)`, serta kotak salin cepat **Webhook URL** (`https://<domain-isp>/api/webhooks/sumopod`) untuk ditempelkan ke dashboard Sumopod.