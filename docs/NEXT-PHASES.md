# Roadmap Fase Lanjutan SIAKAD

Terakhir diperbarui: 30 Juli 2026

Status aplikasi terakhir: Phase 18 selesai dan seluruh roadmap fase 14–18 sudah diterapkan ke database lokal.

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

Status: complete — selesai dan terverifikasi pada 23 Juli 2026.

- Kalender akademik terpusat dan agenda berdasarkan semester.
- Penjadwalan UTS/UAS dengan deteksi bentrok ruang, dosen, dan mahasiswa.
- Syarat mengikuti ujian berdasarkan KRS, presensi, dan status keuangan.
- Kartu peserta ujian dengan QR.
- Penetapan pengawas, daftar hadir, berita acara, dan dokumen PDF.

Slice selesai: kalender, jadwal UTS/UAS, eligibility KRS-presensi-keuangan, kartu peserta PDF, QR verifikasi, penetapan pengawas dengan deteksi bentrok, snapshot roster peserta yang memenuhi syarat, pencatatan kehadiran, notifikasi pengawas, serta berita acara final dan PDF.

### Phase 17 — Tugas akhir, PKL, dan KKN

Status: complete — selesai dan terverifikasi pada 23 Juli 2026.

- Pengajuan judul/proposal dan pemeriksaan kelayakan.
- Penentuan pembimbing serta penguji.
- Logbook kegiatan dan bimbingan.
- Unggah revisi dan histori versi berkas.
- Jadwal seminar/sidang, rubrik penilaian, serta berita acara PDF.
- Repository hasil akhir dan pemeriksaan kelengkapan kelulusan.

### Phase 18 — Yudisium, wisuda, dan alumni

Status: complete — selesai dan terverifikasi pada 23 Juli 2026.

- Pemeriksaan otomatis syarat kelulusan: SKS, IPK, administrasi, tugas akhir, dan dokumen.
- Pengajuan dan persetujuan yudisium.
- Pendaftaran wisuda serta pengelolaan peserta.
- Nomor ijazah, transkrip final, dan SKPI.
- Portal alumni dan tracer study.

Slice selesai: periode pendaftaran dan kuota yudisium/wisuda, pengajuan mahasiswa yang idempoten, dokumen persyaratan privat berversi, pemeriksaan otomatis status/SKS/IPK/tunggakan/tugas akhir, approval dengan pemeriksaan ulang, penetapan status Lulus, histori status mahasiswa, penerbitan ijazah/transkrip final/SKPI bernomor urut dengan snapshot/hash/QR/PDF, verifikasi publik, profil alumni, serta tracer study tahunan yang idempoten.

## Backlog fitur pendukung

Fitur berikut dapat ditempatkan setelah atau di sela Phase 14–18 berdasarkan kebutuhan kampus:

- Beasiswa dan bantuan pendidikan.
- Pusat tiket, keluhan, dan SLA layanan mahasiswa.
- Pengumuman tersegmentasi berdasarkan role, prodi, angkatan, kelas, dan semester.
- Portal orang tua dengan persetujuan mahasiswa.
- PWA agar aplikasi nyaman dipasang dan digunakan seperti aplikasi mobile.
- Adapter PDDikti Feeder dengan staging, validasi, dan rekonsiliasi data.
- SSO Google/Microsoft institusi.
- Adapter notifikasi email dan WhatsApp resmi. Fondasi email serta Meta WhatsApp Cloud API untuk transaksi keuangan selesai; aktivasi pengiriman nyata memerlukan SMTP, access token, phone number ID, dan approved utility template institusi.
- Portal Super Admin, konfigurasi VA terenkripsi, serta backup/restore/hapus database selesai untuk operasi lokal/non-production.
- Dashboard kesehatan sistem masih perlu dilanjutkan untuk pemantauan antrean, scheduler, kegagalan integrasi, retensi backup off-host, dan restore drill terjadwal.

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

Roadmap Phase 14–18 telah selesai. Portal Super Admin dan operasi database lokal juga sudah tersedia. Sesi berikutnya dapat melanjutkan dashboard kesehatan sistem, kebijakan retensi backup off-host, restore drill terjadwal, atau backlog fitur pendukung lain berdasarkan prioritas kampus. Audit kesiapan production baru dilanjutkan setelah kebijakan institusi, identitas penandatangan, dan kontrak BSI resmi tersedia. Deployment production dan adapter BSI riil tetap berada di luar scope sampai ada arahan khusus.
