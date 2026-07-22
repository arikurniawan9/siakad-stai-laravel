# Parity Matrix SIAKAD Laravel

Tanggal baseline: 21 Juli 2026  
Sumber: audit `C:\2026\siakad-stai` dan dokumen `C:\siakad-laravel\doc`.

Status yang digunakan: `pending`, `partial`, `complete`, `intentionally-retired`.

## Baseline terukur

| Artefak sumber | Jumlah | Status awal | Bukti |
|---|---:|---|---|
| Halaman Next.js | 55 | partial | `rg --files src/app -g page.tsx` |
| Export server action | 134 | pending | `rg -n '^export' src/actions` |
| Migration Supabase | 50 | pending | `rg --files supabase/migrations -g *.sql` |
| Route hasil audit | 75 | pending | dokumen audit |
| Role domain | 9 | partial | `src/lib/constants.ts` |

## Pemetaan fase

| Domain | Route/area sumber | Target Laravel | Status | Evidence / catatan |
|---|---|---|---|---|
| Portal publik | `/`, `/pmb`, `/pmb/daftar` | landing dan PMB wizard Inertia | complete | landing, registrasi publik, wizard biodata/dokumen, pembayaran, status verifikasi/seleksi, hasil, serta konversi mahasiswa tersedia |
| Auth | `/login`, forgot/reset password | session controller + password broker | complete | login database, CAPTCHA session, rate limit, respons lupa-password anti-enumerasi, reset token satu kali, dan UI responsif tersedia |
| Dashboard | `/dashboard` dan role menu | Dashboard query service + Inertia | complete | metrik nyata dan terisolasi untuk Mahasiswa, Dosen, Calon Mahasiswa, serta pengelola; tidak ada placeholder maupun kebocoran agregat lintas peran |
| RBAC | role/menu dinamis | Spatie permission + menu domain | complete | matrix 9 role, Policy/Gate, menu hierarkis server-side, role switch, CRUD menu builder, dan isolasi fitur lintas role tersedia |
| Master data | `/dashboard/master-data/**`, sarana | migrations/model/resource | complete | transfer CSV tervalidasi/audit tersedia untuk kampus, fakultas, prodi, periode, mata kuliah, gedung, ruangan, dosen, mahasiswa, serta sinkronisasi aman pengguna; sarana/dosen/mahasiswa memiliki bulk archive/restore atomik; pengguna memiliki bulk status/arsip dan export tanpa kredensial; seluruh resource inti memakai Policy dan workspace gabungan terlindung dari pembacaan lintas permission |
| PMB | `/dashboard/pmb` | domain PMB + invoice | complete | register draft, wizard biodata/dokumen privat, ownership, tarif/resolver/invoice, VA/callback lokal, pembayaran parsial, verifikasi/koreksi, jadwal dan komponen seleksi, nilai berbobot, passing grade, finalisasi atomik, hasil pada portal, serta konversi mahasiswa/NIM idempotent tersedia; adapter BSI riil dilacak terpisah sebagai blocked-by-bank-contract |
| Registrasi/KRS | `/dashboard/registrasi`, `/dashboard/krs` | academic actions | complete | periode registrasi dan add/drop terpisah, batas SKS otomatis dari IPS sebelumnya dengan snapshot sumber, KRS, validasi kurikulum/prasyarat/SKS/bentrok, kontrol tagihan/dispensasi, review dosen PA, perubahan pasca-persetujuan, reservasi/pelepasan kapasitas atomik, audit, Policy, modal, dan test tersedia |
| Nilai/transkrip | `/dashboard/nilai`, `/dashboard/khs`, `/dashboard/transkrip` | grade/transcript services | complete | lembar nilai per kelas, komponen berbobot, skor terisolasi per enrollment, kalkulasi huruf mutu terkonfigurasi, publish/reopen/finalize, KHS, transkrip, IPS/IPK, Policy role/ownership, audit, modal form, dan test positif-negatif tersedia |
| Presensi & dokumen resmi | presensi, KRS/KHS/transkrip cetak, invoice/kwitansi | attendance service + digital document registry | complete | pertemuan presensi, snapshot peserta, kode akses terenkripsi, check-in berbatas waktu, bulk status, auto-absent, penguncian sesi, PDF server-side, snapshot hash SHA-256, QR verifikasi publik, versi idempotent, pencabutan beralasan, audit, role/ownership, modal, dan test positif-negatif tersedia |
| Layanan mahasiswa & surat | pengajuan layanan dan surat akademik | configurable service workflow + verified letter registry | complete | katalog jenis/SLA/template, lampiran privat, workflow dosen PA/prodi/keuangan/akademik, revisi/penolakan/persetujuan, pemeriksaan tunggakan, timeline, antrean role-aware, notifikasi, audit, snapshot surat immutable, PDF, hash SHA-256, QR verifikasi publik, pencabutan, modal, dan test positif-negatif tersedia |
| Bimbingan & early warning | konsultasi dosen wali dan pemantauan mahasiswa berisiko | academic guidance workspace + intervention signals | partial | jadwal konsultasi dengan modal, catatan privat, role/ownership dosen PA, notifikasi, audit, dan early warning otomatis dari IPK, presensi, tunggakan, serta status mahasiswa tersedia; kalender ketersediaan, reminder scheduler, dan rencana intervensi bertahap masih dilanjutkan pada Phase 15 |
| LMS/EDOM | `/dashboard/akademik/lms/**`, `/dashboard/edom` | LMS + EDOM domain | complete | materi/lampiran privat, tugas, submission, nilai/feedback, forum/moderasi, kontrol kelas, EDOM anonim dengan threshold privasi, instrumen, analytics, audit, notifikasi, modal, dan test tersedia |
| Keuangan | `/dashboard/keuangan/**` | billing, payment, ledger | complete | tagihan, pembayaran manual atomik, VA mahasiswa, callback/alokasi/deposit, pembebasan bertanda tangan, portal ledger, rekonsiliasi event idempotent, audit, notifikasi, modal, dan test tersedia |
| Audit, notifikasi & laporan | settings, audit, webhook logs, reports | append-only audit + inbox + analytics | complete | pusat audit terfilter dengan redaksi secret/export CSV, inbox terisolasi pengguna, badge unread, dan laporan lintas akademik/keuangan/PMB/EDOM tersedia |
| Midtrans legacy | route lama | tidak dimigrasikan | intentionally-retired | kanal target BSI VA sesuai ADR |
| Real BSI contract | callback dan gateway | adapter contract + fake gateway | partial | blocked-by-bank-contract; tidak mengarang endpoint/signature |

## Role minimum

Admin, Prodi, Dosen, Mahasiswa, Calon Mahasiswa, Staff, Keuangan, Pimpinan, dan Bendahara dipertahankan. Setiap fitur wajib memiliki Policy/Gate selain filtering menu.

## Definition of Done parity

Item hanya boleh `complete` jika memiliki UI, validasi server, authorization/ownership, constraint/transaksi, audit untuk mutasi sensitif, test positif-negatif, dan evidence quality gate.
