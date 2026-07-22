# Progress Checkpoints

## Phase 0 — Baseline dan keputusan

Status: complete  
Selesai: audit repository sumber, inventaris 55 halaman/134 action/50 migration, parity matrix, ADR, dan keputusan BSI adapter.  
Verifikasi: `git -C C:\2026\siakad-stai status --short --branch`, inventory `rg`.  
Risiko: source lama tidak diubah; real BSI contract belum tersedia.

## Phase 1 — Bootstrap dan infrastructure

Status: complete (fondasi)  
Selesai: Laravel 13 scaffold, PHP 8.4-compatible dependencies, Inertia 3, Fortify, Spatie Permission, Predis, React 19, TypeScript, Vite, Tailwind 4, schema kanonik fondasi, middleware security, auth, dashboard, UI shell, fake BSI adapter, dan callback allocation service.  
Verifikasi: `npm run typecheck`, `npm run build`, `php artisan route:list`, PHP lint, `php artisan test` — hijau.  
Risiko: `migrate:fresh --seed` perlu dijalankan pada MySQL Laragon aktif; Docker Compose disediakan sebagai alternatif.

## Login dan PMB register

Status: complete (foundation)  
Selesai: CAPTCHA lokal 6 karakter uppercase alfanumerik dengan SVG noise, validasi server-side berbasis session dan expiry, session regeneration + active role session, login UI terang animatif, migration `pmb_applications`, dan registrasi akun Calon Mahasiswa.

## Phase 3 — RBAC, role switch, dan menu builder

Status: complete (fondasi operasional)  
Selesai: permission matrix untuk 9 role, seed menu hierarkis, filtering navigation server-side berdasarkan role aktif dan permission, role switch dengan session persistence, serta CRUD menu builder Admin dengan validasi dan role assignment.  
Catatan: halaman bisnis di balik menu masih dikerjakan pada fase domain masing-masing; menu placeholder memakai `#` sampai route modul tersedia.

## Phase 4/5 — Schema akademik dan master data inti

Status: complete (scope Phase 5)  
Selesai: model/relasi Campus, Faculty, Program, AcademicTerm, Course, ClassGroup, Lecturer, Student; soft delete periode akademik/kelas/mahasiswa; CRUD master data dengan search, pagination, edit, archive, referential validation, exclusive active term, dan audit log. Slice sarana, kurikulum, dosen, jadwal, mahasiswa, serta riwayat status tersedia lengkap dengan Policy, audit, filter, archive/restore, dan test aturan bisnis.  
Penutupan: lima resource inti telah memakai Policy terpusat dan workspace gabungan tidak lagi membuka data lintas permission. Pengembangan berikutnya beralih ke Phase 6 PMB end-to-end.

## Checkpoint Phase 5 — Gedung dan ruangan

Status: complete (slice sarana)  
Selesai: migration `buildings`/`rooms`, model dan relasi soft-delete aware, permission untuk Admin/Prodi/Staff, menu Sarana, CRUD Inertia, pencarian/filter status/kampus/jenis, archive/restore, audit mutasi, dan guard gedung yang masih memiliki ruangan aktif.  
Verifikasi: PHPUnit 9 test domain / 29 assertions; seluruh suite 12 test / 36 assertions; `npm run typecheck`, `npm run build`, route list, migration MySQL Laragon, dan seeder permission/menu hijau.  
Risiko: gedung/ruangan sudah dipakai jadwal; alokasi peserta aktual menunggu registrasi/KRS Phase 7.

## Checkpoint Phase 5 — Kurikulum dan prasyarat

Status: complete (slice kurikulum)  
Selesai: migration `curricula`, `curriculum_courses`, dan `course_prerequisites`; kurikulum aktif eksklusif per prodi dengan row lock; pemetaan mata kuliah per semester dan SKS; prasyarat dengan validasi prodi serta deteksi siklus; CRUD/filter/archive/restore Inertia; Policy/permission; dan audit seluruh mutasi.  
Verifikasi: PHPUnit 10 test domain / 48 assertions; seluruh suite 22 test / 84 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, route list, lint 61 file PHP, migration MySQL Laragon, dan seeder permission/menu hijau.  
Risiko: kurikulum dan jadwal belum dikonsumsi oleh KRS karena registrasi semester masih menjadi Phase 7.

## Checkpoint Phase 5 — Dosen dan jadwal kuliah

Status: complete (slice dosen/jadwal)  
Selesai: migration `lecturers` serta normalisasi `class_groups` dengan FK dosen/ruangan dan soft delete; CRUD/filter/archive/restore dosen dan jadwal; pilihan kanonik periode/mata kuliah/dosen/ruangan; validasi kapasitas; pencegahan bentrok dosen dan ruangan; row lock serta pemeriksaan konflik ulang dalam transaksi; Policy/permission; dan audit mutasi.  
Verifikasi: PHPUnit 10 test domain / 48 assertions; seluruh suite 32 test / 132 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, route list, lint 68 file PHP, migration MySQL Laragon, dan seeder permission/menu hijau.  
Risiko: jumlah peserta masih berasal dari `enrolled_count`; alokasi peserta aktual baru tersedia setelah domain registrasi/KRS Phase 7.

## Checkpoint Phase 5 — Mahasiswa dan riwayat status

Status: complete (slice mahasiswa)  
Selesai: perluasan schema mahasiswa dengan akun, periode masuk, angkatan, jenis pendaftaran, dosen wali, dan data kontak; riwayat status append-only; role Mahasiswa otomatis; transisi status dengan row lock dan state machine; CRUD/filter/archive/restore Inertia; Policy/permission; serta audit mutasi. Halaman Inertia juga diubah ke lazy loading agar pertumbuhan modul tidak menghasilkan satu bundle besar.  
Verifikasi: PHPUnit 10 test domain / 46 assertions; seluruh suite 42 test / 178 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, route list, lint 76 file PHP, migration MySQL Laragon, dan seeder permission/menu hijau.  
Risiko: konversi PMB menjadi mahasiswa/NIM tetap menunggu workflow kelulusan Phase 6; data mahasiswa manual sudah siap menjadi target konversi.

## Phase 6 — PMB end-to-end

Status: complete (scope aplikasi; adapter BSI riil tetap blocked-by-bank-contract)  
Selesai: registrasi akun Calon Mahasiswa sebagai draft, wizard biodata/dokumen privat, Policy ownership, master tarif per periode/prodi/jalur/jenis/gelombang/tanggal, resolver deterministik tanpa fallback nominal, invoice idempotent saat submit, VA PMB otomatis melalui adapter fake lokal, callback pembayaran idempotent, penguncian aplikasi, workspace pendaftar/tarif, pemeriksaan dokumen privat oleh panitia, alur koreksi dan kirim ulang, finalisasi verifikasi, seleksi berbobot dengan passing grade, finalisasi hasil atomik, konversi menjadi mahasiswa, penerbitan NIM idempotent, serta portal hasil/NIM pemohon.  
Lanjutan eksternal: aktivasi adapter BSI riil tetap menunggu kontrak onboarding resmi.

## Quality gates terakhir

Terakhir dijalankan pada aplikasi baru:

```text
npm.cmd run typecheck
npm.cmd run build
php artisan route:list --except-vendor
php artisan test
PHP lint: 130 files
```

Status: typecheck, build, route list (108 route aplikasi), test (120 passed / 910 assertions), dan lint 153 file PHP domain/test hijau. Migration seleksi/NIM teruji lewat SQLite in-memory; penerapan migration terbaru ke MySQL lokal menunggu service MySQL pada `127.0.0.1:3306` aktif.

## Checkpoint Phase 5 — Manajemen pengguna

Status: complete (slice pengguna)  
Selesai: CRUD akun dengan pencarian/filter/paginasi; multi-role dan role aktif; aktivasi/nonaktivasi; reset kata sandi administratif; soft delete/restore; Policy/permission; audit tanpa data kata sandi; proteksi akun yang sedang digunakan; serta guard transaksional agar admin aktif terakhir tidak dapat dinonaktifkan, diarsipkan, atau kehilangan role Admin. Relasi mahasiswa, dosen, dan PMB tetap dapat membaca identitas akun yang diarsipkan.  
Verifikasi: PHPUnit 9 test domain / 51 assertions; seluruh suite 51 test / 229 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 53 route aplikasi, lint 89 file PHP, migration MySQL Laragon batch 7, dan seeder permission/menu hijau.  
Risiko: import/export massal pengguna belum dibuka sampai tersedia preview, validasi baris, dan laporan kegagalan yang aman.

## Checkpoint Phase 5 — Import/export master data inti

Status: complete (slice transfer CSV inti)  
Selesai: template dan export CSV UTF-8 untuk kampus, fakultas, program studi, periode akademik, dan mata kuliah; upload maksimal 2 MB/500 baris; deteksi delimiter; preview wajib; validasi header serta error per baris; referensi berbasis kode; pencegahan duplikasi file dan konflik kode arsip; guard satu periode aktif; token preview terikat pengguna dan kedaluwarsa; konfirmasi upsert idempotent dalam transaksi; serta audit import/export.  
Verifikasi: PHPUnit 8 test domain / 81 assertions; seluruh suite 59 test / 310 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 58 route aplikasi, dan lint 93 file PHP hijau.  
Risiko: transfer CSV untuk pengguna belum tersedia; struktur akun memerlukan strategi kata sandi serta pelaporan yang lebih ketat.

## Checkpoint Phase 5 — Transfer dan bulk action sarana

Status: complete (slice sarana lanjutan)  
Selesai: template/import/export CSV gedung dan ruangan dengan identitas komposit per parent, referensi kode kampus/gedung, validasi gedung aktif, batas lantai, fasilitas terstruktur, preview dan audit; bulk archive/restore maksimal 100 data dengan transaksi, row lock, rollback seluruh batch ketika satu item masih direferensikan, audit per record, serta UI seleksi pada halaman aktif maupun arsip. Guard restore individual juga diperketat agar parent harus tersedia dan aktif.  
Verifikasi: PHPUnit 7 test domain / 53 assertions; seluruh suite 66 test / 363 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 64 route aplikasi, dan lint 96 file PHP hijau.  
Risiko: transfer data pengguna belum dibuka karena password awal dan distribusi kredensial harus ditentukan secara aman.

## Checkpoint Phase 5 — Transfer dan bulk action dosen

Status: complete (slice dosen lanjutan)  
Selesai: template/import/export CSV dosen dengan NIDN sebagai identitas upsert, referensi program berdasarkan kode, akun opsional berdasarkan email aktif, validasi keunikan nomor pegawai/akun, role Dosen serta role aktif awal otomatis, preview error per baris, audit; bulk archive/restore atomik maksimal 100 dosen dengan guard jadwal aktif, program aktif, dan akun aktif; serta UI transfer dan seleksi bulk pada workspace dosen/jadwal.  
Verifikasi: PHPUnit 7 test domain / 54 assertions; seluruh suite 73 test / 417 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 70 route aplikasi, dan lint 99 file PHP hijau.  
Risiko: sinkronisasi data dosen dari sistem eksternal masih memerlukan kesepakatan sumber identitas resmi bila NIDN tidak tersedia.

## Checkpoint Phase 5 — Transfer dan bulk action mahasiswa

Status: complete (slice mahasiswa lanjutan)  
Selesai: template/import/export CSV mahasiswa dengan NIM sebagai identitas upsert; referensi akun/prodi/dosen wali/periode berdasarkan email atau kode; validasi akun unik dan dosen wali satu prodi; role Mahasiswa serta role aktif awal otomatis; riwayat status awal append-only; larangan perubahan status melalui CSV; bulk archive/restore atomik dengan guard status, akun, dan prodi; UI preview/row error/bulk; audit; serta permission `students.export` khusus Admin/Prodi/Staff untuk melindungi export PII dari permission baca biasa.  
Verifikasi: PHPUnit 7 test domain / 65 assertions; seluruh suite 80 test / 482 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 76 route aplikasi, lint 102 file PHP, dan seeder permission MySQL Laragon hijau.  
Risiko: mekanisme export sudah dipisahkan dari `students.view`; ownership halaman mahasiswa untuk role Mahasiswa tetap perlu diperdalam saat portal akademik Phase 7 dibangun.

## Checkpoint Phase 5 — Transfer aman dan bulk action pengguna

Status: complete (slice pengguna lanjutan)  
Selesai: sinkronisasi CSV khusus akun yang sudah tersedia berdasarkan email; template/export UTF-8 tanpa kolom maupun nilai kata sandi; permission `users.export` terpisah; batas 2 MB/500 baris; preview wajib dan token sesi 30 menit; validasi akun arsip/tidak dikenal, username, multi-role, role aktif, status, dan duplikasi file; revalidasi serta row lock saat konfirmasi; pergantian Admin lintas baris yang atomik; audit import/export tanpa kredensial; bulk aktivasi, nonaktivasi, arsip, dan restore maksimal 100 akun; serta proteksi atomik untuk akun sendiri dan Admin aktif terakhir. Akun baru tetap dibuat melalui formulir agar password awal tidak pernah didistribusikan melalui CSV.  
Verifikasi: PHPUnit 8 test domain / 85 assertions; seluruh suite 88 test / 567 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 82 route aplikasi, lint 108 file PHP, dan seeder permission MySQL Laragon hijau.  
Risiko: sinkronisasi sengaja tidak membuat akun baru; onboarding massal baru boleh ditambahkan setelah tersedia alur undangan atau set-password sekali pakai yang aman.

## Checkpoint Phase 5 — Policy master data inti

Status: complete (penutupan Phase 5)  
Selesai: Policy terpisah untuk kampus, fakultas, program studi, periode akademik, dan mata kuliah; FormRequest CRUD dan transfer CSV memakai ability Policy; export/template memakai `viewAny`; delete memakai policy model; workspace gabungan mewajibkan seluruh permission baca atas data yang diekspos; serta pengujian negatif/positif seluruh ability `viewAny`, `create`, `update`, `delete`, dan `restore`. Pengujian isolasi memastikan permission satu resource tidak dapat dipakai untuk memutasi resource lain.  
Verifikasi: PHPUnit policy + transfer 11 test / 143 assertions; seluruh suite 91 test / 629 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 82 route aplikasi, dan lint 114 file PHP hijau.  
Risiko: tidak ada blocker tersisa untuk scope Phase 5; pekerjaan berikutnya adalah melanjutkan workflow PMB Phase 6.

## Checkpoint Phase 6 — Wizard biodata dan dokumen PMB

Status: complete (slice wizard pemohon)  
Selesai: registrasi publik sekarang membuat aplikasi `draft` dan role aktif Calon Mahasiswa; password minimum diseragamkan menjadi 12 karakter; biodata lengkap dengan program aktif, jalur, jenis pendaftaran, NIK unik 16 digit, data kelahiran/sekolah/wali; empat tipe dokumen wajib (pas foto, identitas, ijazah/SKL, transkrip/rapor) dengan validasi PDF/JPG/PNG maksimal 2 MB; file disimpan pada disk privat; replacement membersihkan file lama; Policy ownership; audit tanpa nilai PII; submit dengan row lock dan validasi ulang kelengkapan; serta aplikasi terkunci setelah dikirim.  
Verifikasi: PHPUnit 7 test domain / 68 assertions; seluruh suite 98 test / 697 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 86 route aplikasi, lint 121 file PHP, dan migration `2026_07_22_050000` berhasil pada MySQL Laragon.  
Risiko: dokumen belum memiliki preview/download aman untuk panitia karena workspace verifikasi belum dibangun; invoice sengaja menunggu fee resolver agar nominal tidak pernah di-hardcode pada aplikasi pemohon.

## Checkpoint Phase 6 — Fee resolver dan invoice PMB

Status: complete (slice tarif/invoice)  
Selesai: master tarif PMB dengan periode akademik, prodi opsional, jalur, jenis, gelombang, rentang tanggal, nominal, serta jatuh tempo; validasi overlap cakupan aktif; Policy dan permission `pmb_fees.*` terpisah dari portal calon mahasiswa; workspace admin untuk CRUD/archive/restore tarif serta pemantauan aplikasi/invoice; resolver hanya memakai periode aktif dan memilih tarif paling spesifik secara deterministik; tidak ada fallback nominal hardcode; submit gagal aman bila tarif belum tersedia; invoice satu-per-aplikasi dengan row lock, nomor deterministik, nominal beku, dan penerbitan idempotent; portal pemohon menampilkan preview tarif dan invoice.  
Verifikasi: PHPUnit fee/invoice + workflow 12 test / 93 assertions; seluruh suite 103 test / 722 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 91 route aplikasi, lint 130 file PHP, migration `2026_07_22_060000`, dan seeder permission/menu berhasil pada MySQL Laragon.  
Risiko: invoice belum memiliki VA karena kontrak real BSI tetap tidak boleh ditebak; slice berikutnya dapat memakai fake/local adapter yang eksplisit untuk workflow PMB sambil menunggu kontrak bank resmi.

## Checkpoint Phase 6 — Verifikasi dokumen PMB

Status: complete (slice verifikasi panitia)  
Selesai: workspace pemeriksaan per aplikasi dengan permission `pmb_verification.view/update`; halaman panitia terpisah dari ownership pemohon; download file privat melalui authorization dan scoping aplikasi; keputusan terverifikasi/ditolak per dokumen; catatan wajib untuk penolakan; audit setiap keputusan; pengembalian aplikasi hanya setelah ada dokumen ditolak; portal pemohon menampilkan status serta catatan koreksi; replacement menghapus file lama dan mengembalikan status dokumen ke pending; kirim ulang menolak dokumen rejected dan memakai kembali invoice lama secara idempotent; finalisasi hanya berhasil jika empat dokumen wajib seluruhnya verified; serta status final mengunci keputusan lanjutan.  
Verifikasi: PHPUnit verifikasi 5 test / 54 assertions; seluruh suite 108 test / 776 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 96 route aplikasi, lint 134 file PHP domain/test, serta seeder permission MySQL Laragon hijau.  
Risiko: invoice belum memiliki VA; proses seleksi dan konversi aplikasi verified menjadi mahasiswa/NIM masih menjadi slice Phase 6 berikutnya.

## Checkpoint Phase 6 — VA dan pembayaran pendaftaran PMB

Status: complete (slice adapter lokal dan orkestrasi pembayaran)  
Selesai: migration yang menghubungkan VA serta payment ke aplikasi/invoice PMB sebelum record mahasiswa tersedia; penerbitan satu VA deterministik otomatis bersama invoice; expiry mengikuti batas invoice/gateway; adapter fake hanya diizinkan non-production dan diberi label simulasi; portal pemohon serta workspace panitia/keuangan menampilkan VA; callback HMAC mengalokasikan pembayaran penuh atau parsial ke invoice; event ID dan external reference mencegah transaksi ganda; overpayment, VA kedaluwarsa, currency, dan nominal invalid ditolak; invoice, VA, payment, webhook, dan audit diperbarui atomik; simulator pembayaran khusus local memakai permission `pmb_payments.update`; serta command backfill `pmb:issue-missing-virtual-accounts` tersedia untuk invoice lama.  
Verifikasi: PHPUnit VA/payment 5 test / 57 assertions; seluruh suite 113 test / 833 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, 97 route aplikasi, lint 140 file PHP domain/test, migration `2026_07_22_070000`, serta seeder permission MySQL Laragon hijau.  
Risiko: nomor VA saat ini berasal dari adapter simulasi `bsi-fake`, bukan layanan bank. Aktivasi production memerlukan endpoint, credential, certificate/signature, prefix VA, expiry, inquiry, dan format callback resmi dari BSI; adapter fake akan menolak berjalan pada production.

## Checkpoint Phase 6 — Seleksi, kelulusan, dan konversi mahasiswa

Status: complete (penutupan scope aplikasi Phase 6)  
Selesai: workspace jadwal seleksi per periode dan prodi; komponen nilai dengan bobot maksimum 100%; peserta dibatasi pada aplikasi terverifikasi, periode sesuai, akun/prodi aktif, dan invoice lunas/waived; input nilai tervalidasi terhadap skor maksimum; finalisasi atomik mewajibkan bobot tepat 100% serta nilai lengkap; keputusan lulus/tidak lulus dihitung dari passing grade dan mengunci aplikasi; portal pemohon menampilkan hasil; konversi hanya untuk hasil lulus; relasi sumber PMB dipertahankan; role aktif Mahasiswa, riwayat status awal, dan audit dibuat; sequence NIM per prodi/angkatan memakai row lock, unik, idempotent, serta format configurable melalui environment.  
Verifikasi: PHPUnit seleksi 7 test / 77 assertions; seluruh suite 120 test / 910 assertions; `npm run typecheck`, `npm run build`, route list 108 route, dan lint 153 file PHP hijau.  
Risiko: format NIM default `{PROGRAM}{YEAR}{SEQUENCE}` dengan empat digit sequence harus disesuaikan melalui `SIAKAD_NIM_FORMAT` bila kampus menetapkan format resmi lain sebelum data production dibuat. Migration MySQL terbaru belum diterapkan karena service MySQL lokal tidak aktif saat verifikasi.

## Checkpoint Phase 7 — Registrasi semester dan KRS

Status: complete (slice fondasi registrasi dan persetujuan KRS)  
Selesai: periode registrasi per semester dengan jadwal buka/tutup dan batas SKS; workspace role-aware untuk Mahasiswa, Dosen PA, Prodi, Staff, Admin, dan Keuangan; registrasi idempotent hanya bagi mahasiswa Aktif; pemilihan kelas dari kurikulum aktif; pencegahan kelas ganda untuk mata kuliah yang sama, bentrok waktu, kelebihan SKS, serta prasyarat nilai published/finalized; KRS tidak dapat diajukan saat tagihan periode tertunggak kecuali dispensasi disetujui; keputusan dispensasi terpisah; review KRS hanya oleh dosen PA yang sesuai atau pengelola berwenang; persetujuan memakai row lock, memeriksa kapasitas ulang, dan mereservasi kursi seluruh kelas secara atomik; penolakan dapat diedit dan diajukan ulang; seluruh mutasi sensitif diaudit; menu dan permission seeder telah diaktifkan.  
Verifikasi: PHPUnit Phase 7 7 test / 60 assertions; seluruh suite 128 test / 975 assertions; `npm run typecheck`, `npm run build`, 148 route aplikasi, migration `2026_07_22_080000` dan `2026_07_22_090000`, serta seeder permission/menu berhasil pada MySQL Laragon.  
Risiko: batas SKS saat ini berasal dari periode/reviewer dan belum dihitung otomatis dari IPS semester sebelumnya; perubahan KRS setelah disetujui serta workflow drop/add resmi belum tersedia. Nilai/transkrip akan dilanjutkan pada slice Phase 7 berikutnya.

## Checkpoint Phase 7 — Nilai, KHS, transkrip, dan modal form

Status: complete (penutupan slice nilai/transkrip Phase 7)  
Selesai: lembar nilai satu-per-kelas; komponen penilaian tambah/edit melalui modal responsif dengan bobot maksimal 100%, skor maksimum, urutan, serta proteksi perubahan terhadap skor tersimpan; input seluruh komponen per mahasiswa enrolled; isolasi kelas dosen pengampu; publikasi atomik hanya ketika bobot tepat 100% dan seluruh skor lengkap; perhitungan nilai akhir serta huruf mutu berdasarkan konfigurasi server; pembukaan ulang published hanya oleh Prodi/Admin dan penyembunyian sementara dari mahasiswa; finalisasi permanen; KHS per semester; transkrip sementara; IPS/IPK berbobot SKS; ownership mahasiswa; audit seluruh mutasi; serta permission/menu role sudah disinkronkan. Komponen `ModalForm` reusable mencakup mobile bottom-sheet, backdrop blur, Escape-to-close, focus restoration, scroll lock, footer aksi, dan scrollbar elegan; pengaturan periode registrasi juga sudah dimigrasikan dari form inline ke modal.  
Verifikasi: PHPUnit nilai 7 test / 120 assertions; seluruh suite 135 test / 1095 assertions; `npm run typecheck`, `npm run build`, 156 route aplikasi, migration `2026_07_22_100000`, indeks unik skor, dan seeder permission/menu berhasil pada MySQL Laragon.  
Risiko: ambang nilai default harus dikonfirmasi dengan kebijakan akademik kampus sebelum production dan dapat diubah melalui environment `SIAKAD_GRADE_*_MIN`; perhitungan IPK saat ini memasukkan seluruh attempt mata kuliah, sehingga aturan penggantian nilai untuk mata kuliah ulang perlu ditetapkan kampus bila berbeda.

## Checkpoint Phase 7 — Batas SKS berbasis IPS dan add/drop KRS

Status: complete (penutupan Phase 7)  
Selesai: resolver mencari semester terdahulu terbaru yang memiliki nilai published/finalized, menghitung IPS berbobot SKS, menerapkan tabel batas SKS configurable, membatasi hasil terhadap maksimum periode, serta menyimpan snapshot IPS dan sumber keputusan pada registrasi; reviewer override juga ditandai eksplisit. Periode add/drop memiliki jadwal dan sakelar terpisah. Mahasiswa dengan KRS approved dapat mengajukan add/drop melalui modal beserta alasan, membatalkan permintaan pending, dan melihat histori. Request add memvalidasi ulang semester, prodi, kurikulum aktif, prasyarat, duplikasi, pending request, SKS, serta bentrok; approval mengunci dan memeriksa kapasitas aktual sebelum membuat enrollment serta menaikkan peserta. Drop menolak nilai published/finalized, lalu approval menurunkan kapasitas dan menandai enrollment dropped. Reviewer dibatasi pada dosen PA/pengelola berwenang dan seluruh mutasi diaudit.  
Verifikasi: PHPUnit registrasi 11 test / 79 assertions; seluruh suite 139 test / 1114 assertions; `npm run typecheck`, `npm run build`, 159 route aplikasi, migration `2026_07_22_110000`, dan schema MySQL Laragon hijau.  
Risiko: aturan nilai ulang untuk IPK dan tabel batas SKS default tetap harus dikonfirmasi melalui kebijakan akademik resmi sebelum production; keduanya sudah dipisahkan pada konfigurasi agar dapat disesuaikan tanpa mengubah histori.

## Checkpoint Phase 8–11 — LMS, EDOM, keuangan, operasi, dan hardening

Status: complete (penutupan scope aplikasi internal)  
Selesai: LMS dengan materi/lampiran privat, tugas, pengumpulan, status terlambat, penilaian, feedback, forum, pin/lock, serta scoping kelas; EDOM dengan periode, instrumen standar/custom, rating/esai, satu respons per mahasiswa/kelas, hasil agregat tanpa identitas, dan threshold komentar; pusat keuangan dengan penerbitan tagihan, ledger mahasiswa, pembayaran manual parsial/penuh atomik, pembebasan beralasan, VA mahasiswa, callback/alokasi/deposit, dan rekonsiliasi event; notifikasi in-app dengan unread badge dan ownership; audit trail dengan filter, detail, redaksi secret, dan CSV; laporan eksekutif lintas modul; pemulihan password anti-enumerasi; serta dashboard role-aware tanpa data agregat yang tidak semestinya. Seluruh form tambah/edit utama menggunakan modal responsif dan aksen visual konsisten.  
Verifikasi: seluruh suite 155 test / 1.297 assertions, `npm run typecheck`, `npm run build`, 195 route, serta migration `120000`–`150000` dan seeder role/menu berhasil pada MySQL Laragon.  
Risiko eksternal: adapter VA BSI production tetap menunggu kontrak onboarding resmi (endpoint, credential, sertifikat/signature, prefix, inquiry, expiry, dan format callback). Adapter fake tetap ditolak pada production. Kebijakan nilai ulang, tabel batas SKS, tarif, dan instrumen EDOM default harus dikonfirmasi institusi sebelum go-live.

## Checkpoint Phase 13 — Presensi dan dokumen resmi terverifikasi

Status: complete (scope non-production Phase 13)  
Selesai: workspace presensi role-aware untuk Admin/Prodi/Dosen/Mahasiswa; dosen hanya mengelola kelas yang diampu dan mahasiswa hanya melihat enrollment miliknya; pembuatan pertemuan otomatis mengambil snapshot peserta approved/enrolled; kode check-in 4–8 digit tersimpan memakai encrypted cast dan tidak pernah diserialisasi ke portal mahasiswa; transisi draft→open→closed; batas check-in 30 menit sebelum hingga 30 menit sesudah pertemuan; klasifikasi terlambat setelah 15 menit; bulk status hadir/terlambat/sakit/izin/alpa dengan catatan; peserta belum tercatat otomatis menjadi alpa saat ditutup; sesi tertutup terkunci; notifikasi dan audit tersedia. Pusat dokumen resmi menerbitkan KRS, KHS, transkrip, tagihan, dan kwitansi sebagai snapshot immutable; penerbitan identik idempotent; perubahan isi menerbitkan versi baru dan mencabut versi lama; nomor serta kode ULID unik; hash SHA-256 menjaga integritas; QR menuju verifikasi publik; PDF A4 dihasilkan server-side dengan Dompdf; dokumen bisa dicetak, diunduh, dan dicabut petugas melalui modal beralasan tanpa menghapus histori; ownership serta domain akademik/keuangan dipisahkan per role. Menu, permission, ikon sidebar, migration MySQL, dan dokumentasi telah disinkronkan.  
Verifikasi: PHPUnit Phase 13 11 test / 123 assertions dan regresi penuh 166 test / 1.420 assertions; PDF diuji memiliki signature `%PDF-`; `npm run typecheck` dan `npm run build` hijau (2.405 modul); 178 route terdaftar; migration `2026_07_22_160000` dan `2026_07_22_170000` serta seeder berhasil pada MySQL Laragon.  
Catatan kebijakan: jendela check-in dan ambang terlambat saat ini memakai default 30/15 menit. Identitas penandatangan institusi dan kebijakan penerbitan transkrip final dapat disesuaikan ketika kampus memberikan aturan resminya. Aktivitas production/deployment tetap sengaja tidak dikerjakan pada fase ini sesuai arahan.

## Checkpoint Phase 14 — Layanan mahasiswa dan surat digital

Status: complete (scope non-production Phase 14)  
Selesai: katalog jenis layanan configurable beserta SLA, persyaratan lampiran, template surat, pemeriksaan bebas administrasi, dan workflow per jenis; lima layanan awal aktif kuliah, cuti, pindah, rekomendasi, dan bebas administrasi telah tersedia. Mahasiswa dapat mengajukan, mengunggah lampiran privat, memperbaiki permintaan, membatalkan, serta memantau timeline. Persetujuan berjenjang dosen PA → prodi → keuangan/akademik dibatasi berdasarkan role, relasi, dan tahap aktif; keputusan approve, revisi, serta tolak memakai transaksi dan row lock. Persetujuan final menerbitkan surat resmi immutable dengan nomor otomatis, snapshot, hash SHA-256, QR verifikasi publik, PDF A4, dan pencabutan beralasan. Dashboard antrean, SLA/terlambat, notifikasi, audit trail, permission, menu sidebar, modal responsif, migration, dan seeder telah disinkronkan.  
Verifikasi: PHPUnit Phase 14 10 test / 134 assertions dan regresi penuh 176 test / 1.554 assertions; PDF diuji memiliki signature `%PDF-`; `npm run typecheck` dan `npm run build` hijau (2.406 modul); 180 route terdaftar; migration `2026_07_22_180000` batch 17 dan seeder berhasil pada MySQL Laragon.  
Catatan kebijakan: isi template, format nomor, SLA, urutan approval, dan kebutuhan bebas administrasi dapat dikelola melalui katalog layanan. Identitas penandatangan institusi tetap perlu disesuaikan dengan pejabat resmi kampus sebelum production. Production/deployment dan adapter BSI riil tetap sengaja berada di luar scope.

## Roadmap setelah Phase 14

Roadmap Phase 15–18 dan backlog fitur pendukung tersimpan permanen pada `docs/NEXT-PHASES.md`. Prioritas sesi berikutnya adalah Phase 15 — bimbingan akademik dan early warning.

## Checkpoint Phase 15 — Bimbingan akademik dan early warning (slice fondasi)

Status: in progress  
Selesai pada slice ini: workspace bimbingan role-aware untuk Mahasiswa, Dosen, Prodi, Staff, Pimpinan, dan Admin; pengajuan jadwal konsultasi melalui modal dengan mode daring/luring/telepon; konfirmasi, penyelesaian, pembatalan, dan pencatatan status; catatan bimbingan privat dengan tindak lanjut; early warning otomatis dari IPK, presensi, tunggakan, dan status mahasiswa; acknowledgement/resolution; notifikasi dosen/mahasiswa; audit trail; slot ketersediaan dosen; rencana intervensi dengan status dan target; permission dan menu sidebar; migration MySQL; serta layout responsif dengan kartu metrik dan aksen premium.  
Verifikasi: PHPUnit regresi penuh 176 test / 1.554 assertions, test workflow Phase 14 10 test / 134 assertions, `npm run typecheck`, `npm run build` hijau (2.407 modul), 188 route terdaftar, dan migration `2026_07_22_190000`–`2026_07_22_200000` berhasil pada MySQL Laragon.  
Lanjutan Phase 15: kalender ketersediaan dan panel rencana intervensi kini tampil di workspace dengan modal input responsif; reminder terjadwal berjalan melalui `guidance:send-reminders` setiap jam dan ambang dapat diatur melalui `SIAKAD_GUIDANCE_LOW_GPA_THRESHOLD`, `SIAKAD_GUIDANCE_LOW_ATTENDANCE_THRESHOLD`, serta `SIAKAD_GUIDANCE_REMINDER_HOURS_BEFORE`. Penyempurnaan kebijakan intervensi institusi dan pengujian domain khusus masih perlu diselesaikan sebelum fase ditutup.
