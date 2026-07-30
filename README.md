# SIAKAD OS — Laravel 13

Portal akademik terpadu untuk PMB, akademik, LMS, EDOM, keuangan, dan rekonsiliasi VA. Proyek ini dibangun sebagai aplikasi baru di samping baseline Next.js; aplikasi sumber tidak disentuh.

## Stack

- Laravel 13 + PHP 8.3/8.4
- MySQL 8.4 InnoDB dan Redis
- Inertia 3 + React 19 + TypeScript + Vite
- Tailwind CSS 4
- Fortify, Spatie Laravel Permission, Predis, Dompdf, dan BaconQrCode

## Menjalankan lokal

Pastikan PHP 8.3/8.4, Composer, Node.js 22+, dan Docker tersedia.

```text
docker compose up -d
composer install
php artisan key:generate
php artisan migrate:fresh --seed
npm install
npm run dev
```

Pada Windows Laragon, gunakan PHP 8.4 melalui menu Laragon atau jalankan Artisan dengan path PHP 8.4 secara eksplisit. `docker-compose.yml` menyediakan MySQL 8.4 dan Redis dengan kredensial development lokal yang tidak boleh dipakai production.

Suite test memakai SQLite in-memory, sehingga extension `pdo_sqlite` dan `sqlite3` harus aktif pada PHP CLI 8.4. Format NIM hasil konversi PMB dapat diatur melalui `SIAKAD_NIM_FORMAT` (placeholder `{PROGRAM}`, `{YEAR}`, `{SEQUENCE}`) dan `SIAKAD_NIM_SEQUENCE_DIGITS`; default-nya `{PROGRAM}{YEAR}{SEQUENCE}` dengan empat digit sequence.

## Super Admin

Portal pemilik sistem tersedia di `/superadmin`. Jika belum ada akun dengan role `Super Admin`, rute tersebut membuka setup satu kali untuk membuat kredensial pertama. Setelah setup selesai, akses berikutnya menggunakan `/superadmin/login`.

Portal menyediakan konfigurasi VA BSI terenkripsi, backup MySQL/SQLite, download backup privat, restore dengan backup otomatis, dan penghapusan database dengan konfirmasi nama database serta kata sandi. Backup tersimpan di `storage/app/private/backups/database`. Pada Windows, isi `SUPERADMIN_MYSQL_BIN_PATH` jika `mysql.exe` dan `mysqldump.exe` tidak tersedia pada `PATH`; nilainya adalah direktori `bin` MySQL, bukan path file executable. Penghapusan database production dinonaktifkan ketika `APP_DEBUG=false`.

Konfigurasi BSI production dapat disiapkan di portal, tetapi tidak dapat diaktifkan sebelum adapter resmi berdasarkan kontrak onboarding BSI tersedia. Driver `bsi-fake` tetap khusus simulasi non-production.

Modul Registrasi & KRS tersedia pada `/academic/registration`. Jalankan migration dan seeder terbaru agar tabel registrasi, permission role, serta menu sidebar tersedia.
Modul Nilai, KHS, dan Transkrip tersedia pada `/academic/grades`. Ambang huruf mutu default dapat disesuaikan melalui `SIAKAD_GRADE_A_MIN`, `SIAKAD_GRADE_B_PLUS_MIN`, `SIAKAD_GRADE_B_MIN`, `SIAKAD_GRADE_C_PLUS_MIN`, `SIAKAD_GRADE_C_MIN`, dan `SIAKAD_GRADE_D_MIN` sebelum deployment production.
Aturan batas SKS dari IPS sebelumnya dapat disesuaikan melalui keluarga environment `SIAKAD_CREDIT_GPA_*`. Nilai resolver disimpan sebagai snapshot pada registrasi agar histori tidak berubah saat konfigurasi diperbarui.
Ambang early warning dapat disesuaikan melalui `SIAKAD_GUIDANCE_LOW_GPA_THRESHOLD`, `SIAKAD_GUIDANCE_LOW_ATTENDANCE_THRESHOLD`, dan `SIAKAD_GUIDANCE_REMINDER_HOURS_BEFORE`. Scheduler Laravel menjalankan `guidance:send-reminders` setiap jam; pada development dapat diuji manual dengan `php artisan guidance:send-reminders`.
Ambang presensi minimum untuk kartu peserta ujian dapat disesuaikan melalui `SIAKAD_EXAM_ATTENDANCE_THRESHOLD` (default 75 persen).
Syarat kelulusan dapat disesuaikan melalui `SIAKAD_GRADUATION_MINIMUM_GPA`, `SIAKAD_GRADUATION_MINIMUM_CREDITS`, dan `SIAKAD_GRADUATION_REQUIRE_PROJECT`. Format nomor ijazah/transkrip final/SKPI memakai `SIAKAD_GRADUATE_DOCUMENT_FORMAT` serta `SIAKAD_GRADUATE_DOCUMENT_SEQUENCE_DIGITS`.

Modul lanjutan tersedia pada:

- `/academic/lms` untuk materi, tugas, pengumpulan, penilaian, dan forum kelas;
- `/academic/edom` untuk instrumen serta evaluasi anonim. Ambang perlindungan komentar dapat diatur lewat `SIAKAD_EDOM_ANONYMITY_THRESHOLD` (default 3 respons);
- `/academic/attendance` untuk pertemuan presensi, kode check-in terenkripsi, rekap status, dan penguncian setelah sesi ditutup;
- `/documents` untuk penerbitan KRS/KHS/transkrip/tagihan/kwitansi, unduhan PDF, registri versi, pencabutan, QR, dan verifikasi publik;
- `/services` untuk pengajuan layanan mahasiswa, persetujuan berjenjang, SLA, lampiran privat, surat PDF, QR verifikasi, dan pencabutan dokumen;
- `/academic/guidance` untuk jadwal bimbingan dosen wali, catatan privat, tindak lanjut, dan early warning mahasiswa;
- `/academic/calendar` untuk kalender akademik, jadwal UTS/UAS, pemeriksaan kelayakan, kartu peserta, penugasan pengawas, daftar hadir, dan berita acara ujian;
- `/academic/projects` untuk tugas akhir, PKL, KKN, proposal, pembimbing/penguji, logbook, sidang, berita acara, dan repository;
- `/graduation` untuk periode yudisium/wisuda, pemeriksaan kelulusan, penerbitan ijazah/transkrip final/SKPI, profil alumni, dan tracer study;
- `/finance` untuk ledger, VA, pembayaran, pembebasan tagihan, dan rekonsiliasi callback;
- `/reports` untuk ringkasan eksekutif lintas akademik, keuangan, PMB, dan EDOM;
- `/admin/audit-logs` untuk audit trail serta ekspor CSV yang menyamarkan secret;
- `/notifications` untuk kotak masuk notifikasi per pengguna.

Pemulihan password tersedia melalui `/forgot-password`. Konfigurasikan `MAIL_*` ke layanan email institusi pada production; driver log hanya sesuai untuk development.

## Notifikasi keuangan otomatis

Penerbitan tagihan mahasiswa/PMB, pembayaran sebagian, pelunasan, pembebasan tagihan, serta pengingat jatuh tempo otomatis menghasilkan notifikasi dalam aplikasi, email, dan WhatsApp. Pengingat default dikirim pada H-7, H-3, H-1, hari H, H+1, dan H+7; jadwal dapat diubah melalui `FINANCE_NOTIFICATION_REMINDER_DAYS`.

Pada development, `WHATSAPP_DRIVER=log` menulis simulasi pesan ke log tanpa menghubungi penerima nyata. Untuk Meta WhatsApp Cloud API, gunakan `WHATSAPP_DRIVER=meta`, isi `WHATSAPP_META_PHONE_NUMBER_ID` dan `WHATSAPP_META_ACCESS_TOKEN`, lalu sediakan approved utility template bernama sesuai `WHATSAPP_META_FINANCE_TEMPLATE`. Template default `siakad_finance_notification` menerima lima body parameter berurutan: nama penerima, judul, isi pesan, nomor referensi, dan tautan portal. Jangan menyimpan access token di repository.

Antrean tersimpan secara idempoten pada `outbound_notifications`; kegagalan provider tidak menduplikasi pesan dan dicoba ulang sesuai batas konfigurasi. `composer run dev` kini menjalankan scheduler. Pada server, pastikan scheduler Laravel dijalankan setiap menit. Perintah operasionalnya:

```text
php artisan finance:queue-reminders
php artisan finance:dispatch-notifications
php artisan schedule:list
```

Tidak ada akun demo yang dibuat oleh seeder. Buat akun administrator secara sadar setelah migrasi:

```text
php artisan tinker
$user = App\Models\User::create(['name' => 'Administrator', 'username' => 'admin', 'email' => 'admin@kampus.ac.id', 'password' => 'ganti-dengan-password-kuat']);
$user->assignRole('Admin');
```

## Quality gates

```text
npm run typecheck
npm run build
php artisan test
php artisan route:list
```

## Deployment production

Deployment otomatis dari GitHub ke VPS disiapkan melalui GitHub Actions.
Panduan setup server, SSH key, GitHub Secrets, Nginx, queue worker, scheduler,
dan prosedur rollback tersedia di
[`docs/DEPLOYMENT-VPS.md`](docs/DEPLOYMENT-VPS.md).

## Konfigurasi sensitif

Turnstile dan BSI sengaja nonaktif pada development. Isi `TURNSTILE_*` dan `BSI_*` hanya dari secret manager/environment deployment. Kontrak onboarding resmi BSI adalah sumber kebenaran endpoint, signature, header, sertifikat, dan response code; adapter real belum boleh diklaim siap sebelum kontrak itu tersedia.

Pada lingkungan lokal, `BSI_VA_DRIVER=fake` menerbitkan VA simulasi deterministik saat invoice PMB dibuat. Invoice lama dapat diproses dengan `php artisan pmb:issue-missing-virtual-accounts`. Adapter fake ditolak pada production dan tidak boleh dianggap sebagai nomor VA bank yang nyata.

Dokumen tracking ada di `docs/PARITY-MATRIX.md`, `docs/DECISIONS.md`, `docs/PROGRESS.md`, dan `docs/NEXT-PHASES.md`. File terakhir menjadi titik lanjut apabila sesi pengerjaan terputus.
