# Deployment otomatis GitHub ke VPS

Dokumen ini memakai asumsi awal:

- branch production adalah `master`;
- server memakai CloudPanel pada Ubuntu 24.04, Nginx, PHP-FPM 8.4,
  Percona MySQL, dan Redis;
- project berada di `/home/siakad-staialittihad/htdocs/siakad-staialittihad.tech`;
- user deployment bernama `siakad-staialittihad`;
- repository GitHub dapat private;
- HTTPS ditangani Certbot atau reverse proxy lain.

VPS yang sudah dikonfigurasi memakai domain `siakad-staialittihad.tech` dan
upstream PHP-FPM `127.0.0.1:19000`.

## Alur deployment

Setiap push ke `master` menjalankan `.github/workflows/deploy-production.yml`.
GitHub Actions lebih dahulu menjalankan typecheck, build, dan seluruh test. Jika
semuanya lolos, GitHub Actions membuka koneksi SSH ke VPS dan memanggil
`scripts/deploy-vps.sh` dengan SHA commit yang baru diuji.

Di VPS, script akan:

1. mengunci proses agar dua deployment tidak berjalan bersamaan;
2. mengambil commit terbaru langsung dari GitHub;
3. mengaktifkan maintenance mode;
4. me-reset working tree ke commit yang tepat;
5. memasang dependency Composer production dan membangun asset Vite;
6. menjalankan migration, membuat storage link, dan meng-cache Laravel;
7. me-restart queue worker secara graceful;
8. membuka kembali aplikasi.

File `.env`, isi `storage`, database, dan secret tidak masuk ke Git.

## 1. Kebutuhan VPS

Pasang Nginx, MySQL 8+, Redis, Git, Composer 2, Node.js 22/npm, `flock`
(umumnya dari `util-linux`), serta PHP 8.3+ dengan PHP-FPM dan extension:

```text
ctype curl dom fileinfo intl mbstring mysql openssl pdo tokenizer xml zip
```

Pastikan perintah ini berhasil untuk user deployment:

```bash
php -v
composer --version
node --version
npm --version
git --version
```

Node yang terpasang system-wide dapat langsung digunakan. Script deployment
juga memuat `$HOME/.nvm/nvm.sh` ketika `node` atau `npm` tidak tersedia pada
`PATH` sesi SSH non-interaktif.

## 2. User dan direktori aplikasi

CloudPanel sudah menyediakan user dan direktori berikut:

```bash
id siakad-staialittihad
ls -ld /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech
```

Jangan menjalankan Composer, npm, atau worker aplikasi sebagai root.

## 3. Beri VPS akses read-only ke repository GitHub

Repository ini public, sehingga VPS cukup menggunakan URL HTTPS read-only.
Clone awal dilakukan oleh user `siakad-staialittihad`:

```bash
sudo -iu siakad-staialittihad
git clone https://github.com/arikurniawan9/siakad-stai-laravel.git \
  /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech
```

## 4. Siapkan environment aplikasi

Di VPS:

```bash
cd /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech
cp deploy/env.production.example .env
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan key:generate
npm ci --no-audit --no-fund
npm run build
```

Edit `.env` dan ganti seluruh domain, kredensial database, SMTP, serta
konfigurasi institusi. Jangan pernah commit `.env`. Nilai penting:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://siakad-staialittihad.tech
SESSION_SECURE_COOKIE=true
```

`BSI_VA_DRIVER=fake` harus tetap disertai `BSI_ENABLED=false` di production
sampai adapter resmi bank tersedia. Isi secret WhatsApp/BSI hanya di `.env`
VPS.

Buat database dan user MySQL dengan password kuat, lalu jalankan:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
chgrp -R www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +
```

Pastikan PHP-FPM (`www-data`) dan user `siakad-staialittihad` sama-sama dapat menulis ke
`storage` dan `bootstrap/cache`.

## 5. Konfigurasi Nginx dan HTTPS

Salin `deploy/nginx.conf.example` ke konfigurasi Nginx, lalu ubah:

- `server_name`;
- `root` jika path project berbeda;
- upstream `127.0.0.1:19000` sesuai PHP-FPM 8.4 CloudPanel.

Uji konfigurasi sebelum reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Aktifkan sertifikat TLS dengan Certbot/reverse proxy yang digunakan. Jangan
aktifkan `SESSION_SECURE_COOKIE=true` sebelum domain sudah dilayani melalui
HTTPS.

## 6. Jalankan queue worker dan scheduler

Salin service contoh:

```bash
sudo cp deploy/siakad-queue.service.example /etc/systemd/system/siakad-queue.service
sudo systemctl daemon-reload
sudo systemctl enable --now siakad-queue
sudo systemctl status siakad-queue
```

Tambahkan scheduler lewat crontab user `siakad-staialittihad` (`crontab -e`):

```cron
* * * * * cd /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Queue worker diperlukan untuk notifikasi. Scheduler diperlukan untuk pengingat
bimbingan dan notifikasi keuangan terjadwal.

## 7. Beri GitHub Actions akses SSH ke VPS

Ini key yang berbeda dari deploy key GitHub pada langkah 3. Buat key pada
komputer admin yang aman:

```bash
ssh-keygen -t ed25519 -f siakad_actions_vps -C "github-actions-siakad" -N ""
```

Tambahkan isi `siakad_actions_vps.pub` ke
`/home/siakad-staialittihad/.ssh/authorized_keys` di VPS. Simpan private key
`siakad_actions_vps` sebagai secret `VPS_SSH_KEY`.

Ambil host key VPS, lalu verifikasi fingerprint-nya melalui console/provider
VPS sebelum mempercayainya:

```bash
ssh-keyscan -p 22 domain-atau-ip-vps
```

Di GitHub repository, buka **Settings → Environments → production**. Tambahkan
secrets berikut:

| Secret | Contoh/isi |
| --- | --- |
| `VPS_HOST` | IP atau hostname VPS |
| `VPS_PORT` | `22` |
| `VPS_USER` | `siakad-staialittihad` |
| `VPS_APP_PATH` | `/home/siakad-staialittihad/htdocs/siakad-staialittihad.tech` |
| `VPS_SSH_KEY` | seluruh isi private key Actions ke VPS |
| `VPS_KNOWN_HOSTS` | output `ssh-keyscan` yang fingerprint-nya sudah diverifikasi |

Environment `production` dapat diberi required reviewer agar deployment
memerlukan persetujuan manual. Tanpa rule tersebut, deploy berjalan otomatis
setelah quality gate lolos.

## 8. Deployment pertama dan berikutnya

Sebelum push pertama, pastikan clone repository dan setup manual di atas sudah
selesai. Workflow mengambil versi script deployment dari commit yang baru
diuji, sehingga clone awal di VPS tidak harus sudah memiliki script tersebut.
Commit dan push seluruh file:

```bash
git add .github/workflows/deploy-production.yml scripts/deploy-vps.sh deploy docs/DEPLOYMENT-VPS.md
git commit -m "chore: add automated VPS deployment"
git push origin master
```

Pantau proses pada tab **Actions** repository. Deployment berikutnya cukup:

```bash
git push origin master
```

## Pemeriksaan dan pemulihan

Periksa kondisi VPS:

```bash
cd /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech
git rev-parse HEAD
php artisan about
php artisan migrate:status
systemctl status siakad-queue
tail -n 100 storage/logs/laravel.log
```

Jika deploy gagal, workflow berhenti dengan error dan script mencoba membuka
maintenance mode kembali. Perbaiki penyebabnya, lalu gunakan **Re-run jobs** di
GitHub Actions atau push commit perbaikan.

Rollback kode dapat dilakukan dengan menjalankan ulang script memakai SHA lama
yang masih merupakan ancestor `master`:

```bash
cd /home/siakad-staialittihad/htdocs/siakad-staialittihad.tech
bash scripts/deploy-vps.sh SHA_COMMIT_LAMA
```

Migration database tidak otomatis di-rollback karena rollback skema dapat
menghilangkan data. Buat backup database sebelum migration berisiko dan desain
migration production agar kompatibel dengan versi kode sebelum dan sesudahnya.
