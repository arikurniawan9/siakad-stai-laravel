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

Status: typecheck, build, route list (238 route aplikasi), test (197 passed / 1.652 assertions), dan lint 311 file PHP domain/test hijau. Migration hingga `2026_07_23_250000` serta seeder permission/menu sudah diterapkan ke MySQL lokal.

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

## Checkpoint Phase 15 — Bimbingan akademik dan early warning

Status: complete (scope non-production Phase 15)  
Selesai pada slice ini: workspace bimbingan role-aware untuk Mahasiswa, Dosen, Prodi, Staff, Pimpinan, dan Admin; pengajuan jadwal konsultasi melalui modal dengan mode daring/luring/telepon; konfirmasi, penyelesaian, pembatalan, dan pencatatan status; catatan bimbingan privat dengan tindak lanjut; early warning otomatis dari IPK, presensi, tunggakan, dan status mahasiswa; acknowledgement/resolution; notifikasi dosen/mahasiswa; audit trail; slot ketersediaan dosen; rencana intervensi dengan status dan target; permission dan menu sidebar; migration MySQL; serta layout responsif dengan kartu metrik dan aksen premium.  
Verifikasi: PHPUnit regresi penuh 176 test / 1.554 assertions, test workflow Phase 14 10 test / 134 assertions, `npm run typecheck`, `npm run build` hijau (2.407 modul), 188 route terdaftar, dan migration `2026_07_22_190000`–`2026_07_22_200000` berhasil pada MySQL Laragon.  
Penutupan: kalender ketersediaan dan panel rencana intervensi tampil di workspace dengan modal input responsif; reminder terjadwal berjalan melalui `guidance:send-reminders` setiap jam; ambang early warning dapat dikonfigurasi; konflik jadwal dan ownership dosen wali tervalidasi; serta pengujian domain khusus telah ditambahkan. Kebijakan intervensi institusi dapat diperluas kemudian tanpa mengubah histori yang sudah tercatat.

## Checkpoint Phase 16 — Kalender akademik dan operasional ujian

Status: complete (scope non-production Phase 16).

Selesai: kalender akademik terpusat berbasis periode; agenda publik; jadwal UTS/UAS luring, daring, dan hybrid; deteksi konflik ruangan, dosen pengampu, mahasiswa, serta pengawas; validasi kelas/periode/kapasitas; eligibility dari KRS approved, presensi, dan keuangan; kartu peserta PDF dengan QR dan verifikasi publik; penetapan koordinator/anggota pengawas melalui modal; permission `exams.assign`/`exams.operate`; notifikasi penugasan; roster peserta berupa snapshot eligibility yang idempotent; pencatatan daftar hadir terisolasi untuk pengawas yang ditugaskan; PDF daftar hadir; berita acara draf/final; ringkasan kehadiran otomatis; penguncian jadwal, pengawas, roster, dan berita acara setelah finalisasi; PDF berita acara; Policy, transaksi, row lock, constraint, audit, migration, dan seeder.

Verifikasi: PHPUnit khusus Phase 16 8 test / 32 assertions dan regresi penuh 187 test / 1.601 assertions; `npm.cmd run typecheck` dan `npm.cmd run build` hijau (2.408 modul); 218 route terdaftar; lint 283 file PHP hijau; migration `2026_07_23_220000`–`2026_07_23_230000` serta seeder permission/menu berhasil pada MySQL Laragon. Test dijalankan langsung melalui PHPUnit memakai PHP 8.4.2 dengan extension SQLite lokal karena `artisan test` mewarisi binary PHP 8.1 dari PATH Windows.
Catatan kebijakan: ambang presensi ujian tetap configurable melalui `SIAKAD_EXAM_ATTENDANCE_THRESHOLD`; format dokumen dan identitas penandatangan perlu disesuaikan dengan pejabat resmi kampus sebelum production. Production/deployment dan adapter BSI riil tetap sengaja di luar scope.

## Checkpoint Phase 17 — Tugas akhir, PKL, dan KKN

Status: complete (scope non-production Phase 17).

Selesai: satu lifecycle bersama untuk tugas akhir, PKL, dan KKN; pengajuan draf oleh mahasiswa; pemeriksaan kelayakan berbasis status aktif, SKS lulus, dan IPK dengan snapshot immutable; ambang configurable per jenis kegiatan; proposal serta dokumen pendukung privat dengan version history dan hash SHA-256; review approve/revisi/tolak; penetapan maksimal dua pembimbing dan tiga penguji; penguncian tim setelah jadwal terbit; notifikasi serta isolasi akses dosen hanya pada penugasan; logbook kegiatan dan review pembimbing; catatan bimbingan privat; jadwal seminar proposal/seminar akhir/sidang dengan deteksi bentrok ruangan dan seluruh dosen; rubrik wajib berbobot tepat 100%; input nilai terisolasi per penguji; finalisasi hanya setelah semua komponen seluruh penguji lengkap; nilai akhir otomatis; berita acara immutable, PDF, QR, dan verifikasi publik; laporan akhir privat; repository metadata idempotent yang baru dapat terbit setelah hasil lulus dan berkas final tersedia; unduhan publik mengikuti consent mahasiswa; status kegiatan selesai; Policy, modal responsif, transaksi, row lock, constraint, audit, permission, menu, dan seeder.

Verifikasi: PHPUnit khusus Phase 17 10 test / 51 assertions dan regresi penuh 197 test / 1.652 assertions; `npm.cmd run typecheck` dan `npm.cmd run build` hijau (2.409 modul); 238 route terdaftar; lint 311 file PHP hijau; migration `2026_07_23_240000`–`2026_07_23_250000` serta seeder berhasil pada MySQL Laragon. MySQL menangkap nama identifier awal yang melewati batas 64 karakter; index/foreign key diperpendek dan hanya tabel baru kosong dari percobaan gagal yang dibersihkan sebelum migration ulang berhasil.
Catatan kebijakan: default minimum IPK adalah 2,00 dan minimum SKS lulus adalah 120 (tugas akhir), 80 (PKL), serta 90 (KKN); seluruhnya wajib dikonfirmasi institusi dan dapat diubah melalui `SIAKAD_PROJECT_MINIMUM_GPA`, `SIAKAD_THESIS_MINIMUM_CREDITS`, `SIAKAD_INTERNSHIP_MINIMUM_CREDITS`, dan `SIAKAD_COMMUNITY_SERVICE_MINIMUM_CREDITS`. Identitas penandatangan serta format berita acara perlu disesuaikan sebelum production.

## Checkpoint Phase 18 — Yudisium, wisuda, dan alumni

Status: complete (scope non-production Phase 18).

Selesai: pengelolaan periode yudisium/wisuda berbasis semester dengan rentang pendaftaran, tanggal penetapan, kuota, dan status aktif; pengajuan mahasiswa idempoten; tiga dokumen persyaratan privat dengan version history serta SHA-256; pemeriksaan otomatis status aktif, SKS lulus, IPK, tunggakan, dan repository tugas akhir dengan snapshot; approval/penolakan dengan pemeriksaan ulang; penetapan Lulus atomik dan histori status mahasiswa; penerbitan idempoten ijazah, transkrip final, serta SKPI menggunakan sequence terkunci per tahun/jenis; snapshot dokumen immutable, content hash, PDF, QR, dan verifikasi publik; pembuatan profil alumni; pemutakhiran kontak/karier; tracer study satu respons per tahun yang di-upsert; policy/ownership, notifikasi, audit, transaksi, row lock, constraint, permission, menu, modal responsif, dan seeder.

Verifikasi: PHPUnit khusus Phase 18 8 test / 62 assertions dan regresi penuh 205 test / 1.714 assertions; `npm.cmd run typecheck` dan `npm.cmd run build` hijau (2.410 modul); 277 route terdaftar; lint 366 file PHP hijau; migration `2026_07_23_260000` serta seeder berhasil pada MySQL Laragon.
Catatan kebijakan: default minimum kelulusan adalah IPK 2,00, 144 SKS, tanpa tunggakan, dan tugas akhir/repository selesai. Nilai dapat diubah melalui `SIAKAD_GRADUATION_MINIMUM_GPA`, `SIAKAD_GRADUATION_MINIMUM_CREDITS`, serta `SIAKAD_GRADUATION_REQUIRE_PROJECT`; format nomor melalui `SIAKAD_GRADUATE_DOCUMENT_FORMAT` dan `SIAKAD_GRADUATE_DOCUMENT_SEQUENCE_DIGITS`. Nama institusi, identitas pejabat penandatangan, format dokumen, serta integrasi nomor ijazah nasional wajib dikonfirmasi sebelum production. Roadmap Phase 14–18 selesai; deployment production dan adapter BSI riil tetap di luar scope.

## Checkpoint pendukung — Notifikasi email dan WhatsApp keuangan

Status: complete untuk fondasi aplikasi; aktivasi provider nyata menunggu kredensial institusi.

Selesai: transactional outbox idempoten untuk kanal in-app, email, dan WhatsApp; event tagihan mahasiswa serta PMB ketika diterbitkan, dibayar sebagian, lunas, atau dibebaskan; pengingat jatuh tempo H-7/H-3/H-1/H/H+1/H+7; nomor Indonesia dinormalisasi; alamat kosong dilewati tanpa menggagalkan transaksi; retry, batas percobaan, pemulihan record processing yang tertinggal, dan provider message ID; email HTML melalui mailer Laravel; safe local WhatsApp log driver; adapter Meta WhatsApp Cloud API berbasis approved utility template; scheduler harian dan dispatcher setiap menit; command operasional; konfigurasi environment; serta pengujian callback VA, pembayaran manual, PMB, idempotensi, reminder, email, dan payload Meta.

Verifikasi: 8 test baru / 27 assertions; regresi penuh 213 test / 1.741 assertions; 379 file PHP lolos syntax lint; `npm.cmd run typecheck`, production build 2.410 modul, dan validasi `composer.json` hijau; migration `2026_07_23_270000` berhasil pada MySQL Laragon; scheduler menampilkan reminder harian dan dispatcher per menit. Pengiriman lokal sengaja menghasilkan log dan belum menghubungi nomor nyata. Pengiriman nyata memerlukan SMTP serta Meta phone number ID, access token, dan approved template dengan lima parameter yang terdokumentasi.

## Checkpoint pendukung - Portal Super Admin dan pemeliharaan database

Status: complete untuk operasi lokal/non-production; adapter VA BSI production tetap menunggu kontrak resmi bank.

Selesai: portal terpisah pada `/superadmin`; setup akun pertama ketika role Super Admin belum tersedia; login, logout, dan isolasi role khusus; session berbasis file agar portal tetap dapat dipakai setelah database dihapus; konfigurasi koneksi VA BSI tersimpan terenkripsi pada storage privat; mode production tidak dapat diaktifkan sebelum adapter riil tersedia; backup MySQL melalui `mysqldump`; daftar, unduh, restore, dan hapus backup; restore database dari berkas SQL tervalidasi; serta penghapusan database dengan backup final, konfirmasi frasa, password, dan proteksi production. Setup pertama juga dapat membangun ulang schema yang hilang. Masalah unduhan backup yang semula mengembalikan `StreamedResponse` tetapi dideklarasikan sebagai `BinaryFileResponse` telah diperbaiki dan dilindungi test endpoint.

Verifikasi: regresi penuh 222 test / 1.796 assertions; test portal 9 test / 55 assertions; `npm run typecheck` dan production build 2.413 modul hijau; 12 route Super Admin terdaftar. Backup MySQL nyata `siakad_20260730_001043.sql` berhasil dibuat dengan ukuran 217.250 byte, dipulihkan ke database sementara, dan menghasilkan 98 tabel sebelum database verifikasi dihapus.

Catatan operasional: backup disimpan privat pada `storage/app/private/backups/database`; lokasi binary MySQL dapat diatur melalui `SUPERADMIN_MYSQL_BIN_PATH`; file backup tidak masuk Git. Pengaturan VA saat ini adalah control plane konfigurasi, bukan koneksi transaksi BSI production. Aktivasi production baru boleh dilakukan setelah endpoint, credential, signature/certificate, prefix VA, inquiry, expiry, callback, dan prosedur rekonsiliasi resmi diterima.
