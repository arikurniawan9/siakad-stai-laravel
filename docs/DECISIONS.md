# Architecture Decision Records

## ADR-001 — Laravel 13 + Inertia React

Status: accepted.  
Laravel 13, PHP 8.4, Inertia 3, React 19, TypeScript, Tailwind CSS 4, dan Vite dipakai agar paradigma UI dekat dengan aplikasi sumber tanpa REST API penuh.

## ADR-002 — MySQL canonical schema

Status: accepted.  
MySQL 8.4/InnoDB menjadi database target. ID legacy dipertahankan sebagai UUID `CHAR(36)`, uang memakai `DECIMAL(15,2)`, dan data audit/ledger bersifat append-only.

## ADR-003 — BSI sebagai adapter, bukan kontrak spekulatif

Status: accepted.  
Sebelum onboarding resmi BSI tersedia, aplikasi hanya menyediakan interface gateway, fake deterministic gateway untuk test, callback orchestration, idempotency, allocation, deposit, dan reconciliation. URL, header, signature, prefix, serta response code BSI tidak ditebak.

## ADR-004 — CAPTCHA fail-closed di production

Status: accepted.  
CAPTCHA lokal 6 karakter alfanumerik uppercase diverifikasi server-side berbasis session, memakai expiry dan sekali pakai. Kode ditampilkan sebagai SVG dengan noise tanpa layanan eksternal.

## ADR-005 — Queue database lokal, Redis production-ready

Status: accepted.  
Windows development memakai database queue/session yang mudah diverifikasi. Deployment production diarahkan ke Redis dan Horizon setelah worker Linux tersedia; Horizon tidak dipasang sebagai binary palsu karena `pcntl` tidak tersedia di Windows.

## ADR-006 — Tarif PMB fail-closed tanpa fallback nominal

Status: accepted.  
Invoice PMB hanya boleh diterbitkan dari master tarif aktif pada periode akademik aktif. Resolver memilih kombinasi paling spesifik berdasarkan prodi, jalur, jenis, gelombang, dan tanggal berlaku. Jika tidak ada tarif yang cocok, submit ditolak dan tidak ada invoice atau nominal buatan yang dibuat. Nilai invoice disalin saat penerbitan agar perubahan master tarif tidak mengubah kewajiban yang sudah terbit.

## ADR-007 — VA PMB dimiliki aplikasi sebelum konversi mahasiswa

Status: accepted.  
Pada tahap pendaftaran, VA dihubungkan ke aplikasi dan invoice PMB karena pemohon belum memiliki record mahasiswa/NIM. Setelah aplikasi diterima dan dikonversi, histori pembayaran tetap merujuk aplikasi asal dan dapat dihubungkan ke mahasiswa hasil konversi. Adapter fake menerbitkan nomor deterministik hanya pada lingkungan non-production, diberi label simulasi pada UI, dan ditolak secara fail-closed di production. Callback sementara memakai envelope HMAC lokal, event ID serta referensi pembayaran idempotent, validasi sisa invoice, dan audit; skema autentikasi final wajib diganti sesuai kontrak onboarding resmi BSI.

## ADR-008 — Finalisasi seleksi atomik dan sequence NIM terkonfigurasi

Status: accepted.  
Peserta seleksi harus berasal dari aplikasi terverifikasi pada periode yang sama dan memiliki invoice lunas atau waived. Finalisasi hanya berjalan jika bobot komponen tepat 100% dan seluruh skor lengkap; nilai akhir serta keputusan seluruh peserta diperbarui dalam satu transaksi dan tidak dapat diedit setelah final. Konversi mahasiswa mempertahankan foreign key aplikasi PMB, bersifat idempotent, dan mengunci sequence per prodi/angkatan. Format NIM dikendalikan oleh `SIAKAD_NIM_FORMAT` dengan placeholder `{PROGRAM}`, `{YEAR}`, `{SEQUENCE}` serta jumlah digit sequence terpisah agar format resmi kampus dapat diterapkan tanpa mengubah service.

## ADR-009 — Nilai dipublikasikan sebelum finalisasi dan skala dikonfigurasi server

Status: accepted.  
Lembar nilai tetap draft selama komponen atau skor belum lengkap. Publish menghitung seluruh nilai secara atomik dan membuatnya terlihat pada KHS; Prodi/Admin dapat membuka kembali nilai published sehingga sementara disembunyikan dari mahasiswa. Finalisasi mengunci nilai dan tidak memiliki jalur edit biasa. Ambang huruf mutu berada pada konfigurasi server `SIAKAD_GRADE_*_MIN`, bukan UI, agar kebijakan kampus dapat disesuaikan tanpa mengubah workflow atau data skor mentah.
