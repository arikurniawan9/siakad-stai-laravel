# Roadmap Fase Lanjutan SIAKAD

Terakhir diperbarui: 22 Juli 2026  
Status aplikasi terakhir: Phase 15 selesai dan sudah diterapkan ke database lokal.  
Catatan penting: aktivitas production, deployment, dan integrasi BSI riil belum dikerjakan sampai ada arahan khusus dan kontrak resmi.

## Urutan pengerjaan yang disepakati

### Phase 14 — Layanan mahasiswa dan surat digital

Status: complete — selesai dan terverifikasi pada 22 Juli 2026.

- Pengajuan surat aktif kuliah, cuti, pindah, rekomendasi, bebas administrasi, dan jenis surat configurable.
- Nomor surat otomatis dan template institusi.
- Workflow persetujuan mahasiswa → dosen PA → prodi → akademik/keuangan sesuai jenis surat.
- Pemeriksaan, revisi, penolakan, dan approval melalui modal responsif.
- Timeline status, catatan petugas, notifikasi, dan audit trail.
- PDF resmi, snapshot dokumen, QR verifikasi, serta pencabutan/versi dokumen.
- Dashboard antrean layanan dan waktu penyelesaian.

### Phase 15 — Bimbingan akademik dan early warning

Status: complete — selesai dan terverifikasi pada 22 Juli 2026.

- Jadwal konsultasi mahasiswa dan dosen PA.
- Catatan bimbingan privat serta rencana tindak lanjut.
- Early warning untuk IPK/IPS rendah, presensi buruk, tunggakan, SKS tertinggal, dan mahasiswa tidak aktif.
- Dashboard mahasiswa berisiko untuk Prodi dan dosen PA.
- Penugasan intervensi, status tindak lanjut, notifikasi, serta audit.
- Pembatasan akses data sensitif berdasarkan role dan relasi dosen PA.

### Phase 16 — Kalender akademik dan ujian

Status: planned — prioritas pengerjaan berikutnya.

- Kalender akademik terpusat dan agenda berdasarkan semester.
- Penjadwalan UTS/UAS dengan deteksi bentrok ruang, dosen, dan mahasiswa.
- Syarat mengikuti ujian berdasarkan KRS, presensi, dan status keuangan.
- Kartu peserta ujian dengan QR.
- Penetapan pengawas, daftar hadir, berita acara, dan dokumen PDF.

### Phase 17 — Tugas akhir, PKL, dan KKN

Status: planned.

- Pengajuan judul/proposal dan pemeriksaan kelayakan.
- Penentuan pembimbing serta penguji.
- Logbook kegiatan dan bimbingan.
- Unggah revisi dan histori versi berkas.
- Jadwal seminar/sidang, rubrik penilaian, serta berita acara PDF.
- Repository hasil akhir dan pemeriksaan kelengkapan kelulusan.

### Phase 18 — Yudisium, wisuda, dan alumni

Status: planned.

- Pemeriksaan otomatis syarat kelulusan: SKS, IPK, administrasi, tugas akhir, dan dokumen.
- Pengajuan dan persetujuan yudisium.
- Pendaftaran wisuda serta pengelolaan peserta.
- Nomor ijazah, transkrip final, dan SKPI.
- Portal alumni dan tracer study.

## Backlog fitur pendukung

Fitur berikut dapat ditempatkan setelah atau di sela Phase 14–18 berdasarkan kebutuhan kampus:

- Beasiswa dan bantuan pendidikan.
- Pusat tiket, keluhan, dan SLA layanan mahasiswa.
- Pengumuman tersegmentasi berdasarkan role, prodi, angkatan, kelas, dan semester.
- Portal orang tua dengan persetujuan mahasiswa.
- PWA agar aplikasi nyaman dipasang dan digunakan seperti aplikasi mobile.
- Adapter PDDikti Feeder dengan staging, validasi, dan rekonsiliasi data.
- SSO Google/Microsoft institusi.
- Adapter notifikasi email dan WhatsApp resmi.
- Dashboard kesehatan sistem, antrean, scheduler, dan kegagalan integrasi.

## Definition of Done untuk setiap fase

Satu fase hanya dianggap selesai jika memiliki:

- UI responsif dan konsisten dengan desain aplikasi.
- Form tambah/edit/review utama menggunakan modal yang sesuai.
- Validasi server dan pesan kesalahan yang jelas.
- Policy/Gate, ownership, dan isolasi data antarrole.
- Transaksi database serta constraint untuk mutasi penting.
- Audit trail dan notifikasi untuk alur sensitif.
- Pengujian positif, negatif, ownership, dan idempotensi bila relevan.
- TypeScript typecheck dan frontend build berhasil.
- Migration serta seeder berhasil pada MySQL lokal.
- Dokumentasi progress dan parity matrix diperbarui.

## Titik mulai sesi berikutnya

Mulai dari **Phase 16 — Kalender akademik dan ujian**. Sebelum implementasi, audit periode akademik, jadwal kuliah, ruangan, enrollment approved, presensi, tagihan, dan komponen `ModalForm`. Production dan adapter BSI riil tetap berada di luar scope.
