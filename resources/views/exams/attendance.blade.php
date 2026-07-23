<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar Hadir Ujian</title>
    <style>
        @page { margin: 28px; }
        body { color: #0f172a; font-family: "DejaVu Sans", sans-serif; font-size: 10px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #64748b; }
        .meta { border: 1px solid #cbd5e1; border-radius: 8px; margin: 16px 0; padding: 10px; }
        .meta table { width: 100%; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        table.roster { border-collapse: collapse; width: 100%; }
        .roster th, .roster td { border: 1px solid #94a3b8; padding: 6px; }
        .roster th { background: #e2e8f0; font-size: 9px; text-transform: uppercase; }
        .center { text-align: center; }
        .signature { height: 36px; }
        .footer { margin-top: 14px; font-size: 8px; color: #64748b; }
    </style>
</head>
<body>
    <h1>Daftar Hadir Ujian {{ strtoupper($exam->exam_type) }}</h1>
    <div class="muted">{{ $exam->academicTerm->code }} · Dicetak {{ now()->format('d/m/Y H:i') }}</div>

    <div class="meta">
        <table>
            <tr><td width="18%">Mata kuliah</td><td width="32%">: {{ $exam->classGroup->course->code }} · {{ $exam->classGroup->course->name }}</td><td width="18%">Tanggal</td><td>: {{ $exam->exam_date->format('d/m/Y') }}</td></tr>
            <tr><td>Kelas</td><td>: {{ $exam->classGroup->name }}</td><td>Waktu</td><td>: {{ substr($exam->starts_at, 0, 5) }}–{{ substr($exam->ends_at, 0, 5) }}</td></tr>
            <tr><td>Ruangan</td><td>: {{ $exam->room ? ($exam->room->building?->name.' · '.$exam->room->code) : 'Daring' }}</td><td>Pengawas</td><td>: {{ $exam->invigilators->pluck('lecturer.name')->join(', ') ?: 'Belum ditetapkan' }}</td></tr>
        </table>
    </div>

    <table class="roster">
        <thead><tr><th width="4%">No.</th><th width="16%">Nomor peserta</th><th width="14%">NIM</th><th>Nama mahasiswa</th><th width="12%">Status</th><th width="17%">Tanda tangan</th></tr></thead>
        <tbody>
        @foreach($exam->participants as $participant)
            <tr>
                <td class="center">{{ $loop->iteration }}</td>
                <td>{{ $participant->participant_number }}</td>
                <td>{{ $participant->student_nim }}</td>
                <td>{{ $participant->student_name }}</td>
                <td class="center">{{ ['unmarked' => 'Belum', 'present' => 'Hadir', 'absent' => 'Tidak hadir', 'sick' => 'Sakit', 'excused' => 'Izin'][$participant->attendance_status] ?? $participant->attendance_status }}</td>
                <td class="signature"></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="footer">Daftar peserta merupakan snapshot mahasiswa dengan KRS disetujui, presensi memenuhi ambang, dan status keuangan bersih saat roster disiapkan.</div>
</body>
</html>
