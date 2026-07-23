<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Berita Acara {{ $project->project_number }}</title>
    <style>
        @page { margin: 34px; }
        body { color: #0f172a; font-family: "DejaVu Sans", sans-serif; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 19px; margin: 0; text-align: center; text-transform: uppercase; }
        .subtitle { color: #64748b; margin-top: 5px; text-align: center; }
        .meta { border: 1px solid #cbd5e1; margin: 18px 0; padding: 10px; }
        .meta table, .scores { border-collapse: collapse; width: 100%; }
        .meta td { padding: 3px 4px; vertical-align: top; }
        .scores th, .scores td { border: 1px solid #94a3b8; padding: 6px; }
        .scores th { background: #e2e8f0; font-size: 9px; }
        .box { border: 1px solid #cbd5e1; margin-top: 5px; min-height: 56px; padding: 9px; white-space: pre-wrap; }
        h2 { font-size: 11px; margin: 16px 0 4px; text-transform: uppercase; }
        .result { background: #ecfdf5; border: 1px solid #a7f3d0; margin: 16px 0; padding: 10px; text-align: center; }
        .qr { bottom: 28px; position: fixed; right: 34px; text-align: center; width: 110px; }
        .qr img { height: 76px; width: 76px; }
        .qr div { color: #64748b; font-size: 7px; word-break: break-all; }
    </style>
</head>
<body>
    <h1>Berita Acara {{ ['proposal_seminar' => 'Seminar Proposal', 'final_seminar' => 'Seminar Akhir', 'defense' => 'Sidang Akhir'][$defense->defense_type] }}</h1>
    <div class="subtitle">{{ $project->project_number }} · {{ $project->academicTerm->name }}</div>
    <div class="meta"><table>
        <tr><td width="20%">Mahasiswa</td><td>: {{ $project->student->user->name }} · {{ $project->student->nim }}</td></tr>
        <tr><td>Program studi</td><td>: {{ $project->student->program->name }}</td></tr>
        <tr><td>Judul</td><td>: {{ $project->title }}</td></tr>
        <tr><td>Pelaksanaan</td><td>: {{ $defense->scheduled_at->format('d/m/Y H:i') }}–{{ $defense->ends_at->format('H:i') }}</td></tr>
        <tr><td>Lokasi</td><td>: {{ $defense->room ? $defense->room->building?->name.' · '.$defense->room->code : 'Daring' }}</td></tr>
        <tr><td>Pembimbing</td><td>: {{ $project->lecturerAssignments->where('role', 'supervisor')->pluck('lecturer.name')->join(', ') }}</td></tr>
        <tr><td>Penguji</td><td>: {{ $project->lecturerAssignments->where('role', 'examiner')->pluck('lecturer.name')->join(', ') }}</td></tr>
    </table></div>
    <table class="scores"><thead><tr><th>Komponen</th><th>Bobot</th><th>Nilai penguji</th></tr></thead><tbody>
        @foreach($defense->rubricItems->sortBy('sort_order') as $item)
            <tr><td>{{ $item->name }}</td><td>{{ number_format((float) $item->weight, 2) }}%</td><td>{{ $item->scores->map(fn ($score) => $score->lecturer->name.': '.number_format((float) $score->score, 2).'/'.number_format((float) $item->max_score, 2))->join('; ') }}</td></tr>
        @endforeach
    </tbody></table>
    <div class="result"><strong>Hasil: {{ strtoupper($defense->result) }}</strong> · Nilai akhir {{ number_format((float) $defense->final_score, 2) }}</div>
    <h2>Ringkasan berita acara</h2><div class="box">{{ $defense->minutes_summary }}</div>
    <h2>Kejadian khusus</h2><div class="box">{{ $defense->incidents ?: 'Tidak ada kejadian khusus.' }}</div>
    <p style="margin-top:24px">Difinalisasi oleh <strong>{{ $defense->completer?->name }}</strong> pada {{ $defense->completed_at->format('d/m/Y H:i') }}.</p>
    <div class="qr"><img src="{{ $qrCode }}" alt="QR verifikasi"><div>{{ $defense->verification_code }}</div><div>{{ $verificationUrl }}</div></div>
</body>
</html>
