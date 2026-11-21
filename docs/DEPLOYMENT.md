# Panduan Deployment Produksi

## Prasyarat server
- PHP 8.2+ dengan ekstensi: `bcmath, curl, mbstring, openssl, pdo_mysql, sockets, gd` (`sockets` wajib untuk RouterOS API)
- MySQL 8.0+ / MariaDB 10.6+, Redis 6+, Nginx/Apache + HTTPS, Node 20+ (hanya untuk build aset), Supervisor

## Langkah deploy pertama
```bash
git clone <repo> /var/www/billing && cd /var/www/billing
composer install --no-dev --optimize-autoloader
cp .env.production.example .env      # lalu isi DB_*, APP_URL, ADMIN_PASSWORD
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force          # membuat super_admin (butuh ADMIN_PASSWORD >= 12 karakter)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Proses latar belakang (wajib)
**Queue worker** (webhook Sumopod mendelegasikan MikroTik & WhatsApp ke antrean) — `/etc/supervisor/conf.d/billing-worker.conf`:
```ini
[program:billing-worker]
command=php /var/www/billing/artisan queue:work redis --sleep=1 --tries=3 --backoff=10 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
```
**Scheduler** — crontab `www-data`:
```
* * * * * cd /var/www/billing && php artisan schedule:run >> /dev/null 2>&1
```
Jadwal: `01:00` isolir, `07:00` terbit invoice, `08:30` pengingat (WIB).

## Konfigurasi setelah login (Admin → Pengaturan Gateway)
1. Isi `X-Api-Key` Sumopod, pilih environment `live`.
2. Isi Signing Secret (`whsec_...`) atau Webhook Token dan pilih mode verifikasi.
3. Daftarkan URL `https://<domain>/api/webhooks/sumopod` di dashboard Sumopod.
4. Isi WhatsApp Gateway URL + token (endpoint menerima `POST {phone, message}` dengan Bearer token).
5. Tambahkan router MikroTik (user API khusus, hak: `ppp`, `ip firewall`, `read`, `write`, `api`) dan buat rule firewall/NAT untuk address-list `ISOLIR` (redirect ke halaman pembayaran).

## Checklist keamanan
- [ ] `APP_DEBUG=false`, HTTPS aktif, `SESSION_SECURE_COOKIE=true`
- [ ] Ganti password super_admin bawaan; port MySQL/Redis tidak terbuka ke publik
- [ ] API RouterOS (8728/8729) hanya dapat diakses dari IP server billing (VPN/firewall)
- [ ] Backup database terjadwal (`mysqldump` harian) dan backup `APP_KEY` (kunci dekripsi kredensial)
- [ ] Monitor `storage/logs`, antrean gagal (`php artisan queue:failed`) dan endpoint `/up`

## Update rilis
```bash
php artisan down && git pull && composer install --no-dev -o && npm ci && npm run build
php artisan migrate --force && php artisan config:cache route:cache view:cache
php artisan queue:restart && php artisan up
```
