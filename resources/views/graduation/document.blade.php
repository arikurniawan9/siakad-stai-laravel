<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $document->document_number }}</title>
    <style>
        @page { margin: 34px; }
        body { color: #0f172a; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { font-size: 23px; letter-spacing: 1px; margin: 0; text-align: center; text-transform: uppercase; }
        .institution { font-size: 14px; font-weight: bold; margin-bottom: 5px; text-align: center; }
        .subtitle { color: #64748b; margin: 5px 0 22px; text-align: center; }
        .identity { border: 1px solid #cbd5e1; margin: 18px auto; max-width: 620px; padding: 14px; }
        .identity table, .courses { border-collapse: collapse; width: 100%; }
        .identity td { padding: 4px; vertical-align: top; }
        .courses th, .courses td { border: 1px solid #94a3b8; padding: 5px; }
        .courses th { background: #e2e8f0; font-size: 8px; }
        .statement { font-size: 13px; line-height: 1.8; margin: 28px auto; max-width: 680px; text-align: center; }
        .sign { margin-left: auto; margin-top: 28px; text-align: center; width: 240px; }
        .sign-space { height: 58px; }
        .qr { bottom: 28px; left: 34px; position: fixed; width: 110px; }
        .qr img { height: 72px; width: 72px; }
        .qr div { color: #64748b; font-size: 7px; word-break: break-all; }
        .summary { background: #ecfdf5; border: 1px solid #a7f3d0; margin: 14px 0; padding: 9px; }
        .footer { bottom: 20px; color: #64748b; font-size: 7px; position: fixed; right: 34px; }
    </style>
</head>
<body>
@php
    $snapshot = $document->snapshot;
    $student = $snapshot['student'];
    $graduation = $snapshot['graduation'];
    $eligibility = $snapshot['eligibility'];
    $labels = ['diploma' => 'Ijazah', 'final_transcript' => 'Transkrip Akademik Final', 'skpi' => 'Surat Keterangan Pendamping Ijazah'];
@endphp
<div class="institution">{{ $snapshot['institution'] }}</div>
<h1>{{ $labels[$document->document_type] }}</h1>
<div class="subtitle">Nomor {{ $document->document_number }}</div>

<div class="identity"><table>
    <tr><td width="24%">Nama</td><td>: <strong>{{ $student['name'] }}</strong></td></tr>
    <tr><td>NIM</td><td>: {{ $student['nim'] }}</td></tr>
    <tr><td>Program studi</td><td>: {{ $student['program'] }} ({{ $student['degree'] }})</td></tr>
    <tr><td>Periode yudisium</td><td>: {{ $graduation['period'] }}</td></tr>
    <tr><td>Tanggal yudisium</td><td>: {{ \Illuminate\Support\Carbon::parse($graduation['judicium_on'])->translatedFormat('d F Y') }}</td></tr>
</table></div>

@if($document->document_type === 'diploma')
    <p class="statement">Dengan ini dinyatakan telah memenuhi seluruh persyaratan akademik dan administratif, serta dinyatakan <strong>LULUS</strong> dari {{ $student['program'] }} pada {{ $snapshot['institution'] }}.</p>
@elseif($document->document_type === 'final_transcript')
    <div class="summary"><strong>Ringkasan:</strong> {{ $eligibility['passed_credits'] }} SKS lulus · IPK {{ number_format((float) $eligibility['gpa'], 2) }}</div>
    <table class="courses"><thead><tr><th>No.</th><th>Kode</th><th>Mata kuliah</th><th>SKS</th><th>Nilai</th></tr></thead><tbody>
    @foreach($snapshot['courses'] as $index => $course)
        <tr><td>{{ $index + 1 }}</td><td>{{ $course['code'] }}</td><td>{{ $course['name'] }}</td><td>{{ $course['credits'] }}</td><td>{{ $course['letter'] }}</td></tr>
    @endforeach
    </tbody></table>
@else
    <p class="statement">Dokumen ini menerangkan capaian pembelajaran dan aktivitas akademik pendamping selama menempuh pendidikan pada {{ $student['program'] }}.</p>
    <div class="identity"><table>
        <tr><td width="24%">Capaian akademik</td><td>: {{ $eligibility['passed_credits'] }} SKS · IPK {{ number_format((float) $eligibility['gpa'], 2) }}</td></tr>
        <tr><td>Kegiatan akhir</td><td>: {{ $snapshot['project']['title'] ?? 'Tidak tercatat' }}</td></tr>
        <tr><td>Jenis kegiatan</td><td>: {{ $snapshot['project']['project_type'] ?? '-' }}</td></tr>
    </table></div>
@endif

<div class="sign">Diterbitkan pada {{ $document->issued_at->translatedFormat('d F Y') }}<div class="sign-space"></div><strong>{{ $document->issuer?->name }}</strong><br>Pejabat berwenang</div>
<div class="qr"><img src="{{ $qrCode }}" alt="QR verifikasi"><div>{{ $document->verification_code }}</div></div>
<div class="footer">Verifikasi: {{ $verificationUrl }}</div>
</body>
</html>
