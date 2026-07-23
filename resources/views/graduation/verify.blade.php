<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verifikasi Dokumen Kelulusan</title>
    <style>
        body{background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif;margin:0;padding:32px}.card{background:#fff;border-radius:18px;box-shadow:0 18px 50px #0f172a16;margin:auto;max-width:720px;padding:28px}.badge{background:{{ $valid ? '#dcfce7' : '#fee2e2' }};border-radius:999px;color:{{ $valid ? '#166534' : '#991b1b' }};display:inline-block;font-size:12px;font-weight:bold;padding:7px 11px}.meta{background:#f8fafc;border-radius:12px;margin-top:20px;padding:16px}.meta p{margin:8px 0}.muted{color:#64748b}
    </style>
</head>
<body><main class="card">
    <span class="badge">{{ $valid ? 'DOKUMEN VALID' : 'DOKUMEN TIDAK VALID / DICABUT' }}</span>
    <h1>Verifikasi dokumen kelulusan</h1>
    <p class="muted">Kode {{ $document->verification_code }}</p>
    <div class="meta">
        <p><strong>{{ ['diploma' => 'Ijazah', 'final_transcript' => 'Transkrip Akademik Final', 'skpi' => 'SKPI'][$document->document_type] }}</strong></p>
        <p>Nomor {{ $document->document_number }}</p>
        <p>{{ $document->application->student->user->name }} · {{ $document->application->student->nim }}</p>
        <p>Diterbitkan {{ $document->issued_at->translatedFormat('d F Y H:i') }}</p>
        @if($document->revoked_at)<p style="color:#991b1b">Dicabut: {{ $document->revocation_reason ?: 'tanpa keterangan' }}</p>@endif
    </div>
</main></body>
</html>
