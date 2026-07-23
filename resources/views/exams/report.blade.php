<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Berita Acara Ujian</title>
    <style>
        @page { margin: 34px; }
        body { color: #0f172a; font-family: "DejaVu Sans", sans-serif; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0; text-align: center; text-transform: uppercase; }
        h2 { font-size: 12px; margin: 18px 0 6px; text-transform: uppercase; }
        .subtitle { color: #64748b; margin-top: 4px; text-align: center; }
        .meta { border: 1px solid #cbd5e1; border-radius: 8px; margin: 18px 0; padding: 10px; }
        .meta table { width: 100%; }
        .meta td { padding: 3px 4px; vertical-align: top; }
        .counts { border-collapse: collapse; margin: 8px 0 14px; width: 100%; }
        .counts th, .counts td { border: 1px solid #94a3b8; padding: 7px; text-align: center; }
        .counts th { background: #e2e8f0; font-size: 9px; text-transform: uppercase; }
        .box { border: 1px solid #cbd5e1; min-height: 48px; padding: 9px; white-space: pre-wrap; }
        .signatures { margin-top: 24px; width: 100%; }
        .signatures td { height: 86px; text-align: center; vertical-align: top; width: 50%; }
        .qr { bottom: 28px; position: fixed; right: 34px; text-align: center; width: 112px; }
        .qr img { height: 76px; width: 76px; }
        .qr div { color: #64748b; font-size: 7px; word-break: break-all; }
    </style>
</head>
<body>
    <h1>Berita Acara Ujian {{ strtoupper($exam->exam_type) }}</h1>
    <div class="subtitle">{{ $exam->academicTerm->name }} · {{ $exam->academicTerm->code }}</div>

    <div class="meta">
        <table>
            <tr><td width="20%">Mata kuliah</td><td>: {{ $exam->classGroup->course->code }} · {{ $exam->classGroup->course->name }}</td></tr>
            <tr><td>Kelas</td><td>: {{ $exam->classGroup->name }}</td></tr>
            <tr><td>Jadwal</td><td>: {{ $exam->exam_date->format('d/m/Y') }}, {{ substr($exam->starts_at, 0, 5) }}–{{ substr($exam->ends_at, 0, 5) }}</td></tr>
            <tr><td>Pelaksanaan aktual</td><td>: {{ $exam->report->actual_starts_at->format('d/m/Y H:i') }}–{{ $exam->report->actual_ends_at->format('H:i') }}</td></tr>
            <tr><td>Ruangan</td><td>: {{ $exam->room ? ($exam->room->building?->name.' · '.$exam->room->code.' · '.$exam->room->name) : 'Daring' }}</td></tr>
            <tr><td>Pengawas</td><td>: {{ $exam->invigilators->sortByDesc(fn ($item) => $item->role === 'coordinator')->map(fn ($item) => $item->lecturer->name.' ('.($item->role === 'coordinator' ? 'Koordinator' : 'Anggota').')')->join(', ') }}</td></tr>
        </table>
    </div>

    <table class="counts">
        <thead><tr><th>Peserta</th><th>Hadir</th><th>Tidak hadir</th><th>Sakit</th><th>Izin</th></tr></thead>
        <tbody><tr><td>{{ $exam->report->participant_count }}</td><td>{{ $exam->report->present_count }}</td><td>{{ $exam->report->absent_count }}</td><td>{{ $exam->report->sick_count }}</td><td>{{ $exam->report->excused_count }}</td></tr></tbody>
    </table>

    <h2>Materi / ruang lingkup ujian</h2>
    <div class="box">{{ $exam->report->material_summary }}</div>
    <h2>Kejadian khusus</h2>
    <div class="box">{{ $exam->report->incidents ?: 'Tidak ada kejadian khusus.' }}</div>
    @if($exam->report->notes)
        <h2>Catatan tambahan</h2>
        <div class="box">{{ $exam->report->notes }}</div>
    @endif

    <table class="signatures">
        <tr>
            <td>Koordinator Pengawas<br><br><br><br><strong>{{ $exam->invigilators->firstWhere('role', 'coordinator')?->lecturer?->name ?? '____________________' }}</strong></td>
            <td>Difinalisasi oleh<br><br><br><br><strong>{{ $exam->report->finalizedBy?->name }}</strong><br>{{ $exam->report->finalized_at->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <div class="qr"><img src="{{ $qrCode }}" alt="QR verifikasi"><div>{{ $exam->report->verification_code }}</div><div>{{ $verificationUrl }}</div></div>
</body>
</html>
