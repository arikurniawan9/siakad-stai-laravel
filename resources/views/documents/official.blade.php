<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $typeLabel }} · {{ $document->document_number }}</title>
    <style>
        @page { margin: 18mm 16mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf2f6; color: #14211d; font: 12px/1.55 DejaVu Sans, Arial, sans-serif; }
        .toolbar { position: sticky; top: 0; z-index: 5; padding: 12px; text-align: center; background: rgba(7,25,22,.94); }
        .toolbar a,.toolbar button { display:inline-block; margin:0 4px; padding:10px 16px; border:0; border-radius:10px; background:#fff; color:#123a31; font-weight:700; text-decoration:none; cursor:pointer; }
        .toolbar .primary { background:#35d2a2; color:#06231c; }
        .sheet { position:relative; width:210mm; min-height:297mm; margin:24px auto; padding:17mm 16mm; overflow:hidden; background:#fff; box-shadow:0 20px 60px rgba(13,38,32,.13); }
        .top-line { height:5px; margin:-17mm -16mm 12mm; background:#087f68; }
        .header { width:100%; border-bottom:2px solid #0e6d5b; padding-bottom:14px; }
        .header td { vertical-align:middle; }
        .logo { width:66px; height:66px; object-fit:contain; }
        .institution { font-size:18px; font-weight:800; letter-spacing:.2px; text-transform:uppercase; }
        .address { color:#60736d; font-size:10px; }
        .doc-title { margin:24px 0 5px; text-align:center; font-size:18px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; }
        .doc-number { text-align:center; color:#61716d; font-size:10px; }
        .meta { width:100%; margin:22px 0 16px; border-collapse:collapse; }
        .meta td { padding:3px 0; vertical-align:top; }
        .meta td:first-child,.meta td:nth-child(4) { width:18%; color:#65746f; }
        .meta td:nth-child(2),.meta td:nth-child(5) { width:30%; font-weight:700; }
        table.data { width:100%; border-collapse:collapse; }
        .data th { padding:8px 7px; background:#0c6555; color:#fff; font-size:9px; letter-spacing:.4px; text-align:left; text-transform:uppercase; }
        .data td { padding:8px 7px; border-bottom:1px solid #dce6e2; vertical-align:top; }
        .data tbody tr:nth-child(even) { background:#f7faf9; }
        .center { text-align:center !important; } .right { text-align:right !important; }
        .summary { margin-top:15px; width:100%; }
        .summary td { width:33.33%; padding:10px; border:1px solid #dce6e2; text-align:center; }
        .summary strong { display:block; color:#096552; font-size:17px; }
        .summary span { color:#6c7c77; font-size:9px; text-transform:uppercase; letter-spacing:.5px; }
        .finance-card { margin:22px 0; padding:18px; border:1px solid #d9e6e1; border-left:5px solid #079174; background:#f7fbf9; }
        .finance-card h2 { margin:0 0 12px; font-size:16px; }
        .finance-grid { width:100%; }
        .finance-grid td { padding:5px 0; }
        .amount { margin-top:16px; padding:14px; background:#0b594b; color:#fff; text-align:right; }
        .amount small { display:block; opacity:.75; } .amount strong { font-size:22px; }
        .signatures { width:100%; margin-top:35px; }
        .signatures td { width:50%; vertical-align:top; text-align:center; }
        .signature-space { height:62px; }
        .verify-box { margin-top:30px; padding:12px; border:1px solid #dce6e2; background:#f8faf9; }
        .verify-box table { width:100%; } .verify-box td { vertical-align:middle; }
        .qr { width:82px; height:82px; }
        .verify-title { color:#08725e; font-size:10px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; }
        .verify-url { word-break:break-all; color:#64746f; font-size:8px; }
        .security { color:#7c8985; font-size:8px; }
        .revoked { position:absolute; top:45%; left:9%; z-index:3; transform:rotate(-27deg); border:8px solid rgba(190,24,93,.18); color:rgba(190,24,93,.18); padding:12px 24px; font-size:58px; font-weight:900; letter-spacing:5px; }
        .footer { position:absolute; right:16mm; bottom:10mm; left:16mm; border-top:1px solid #dce6e2; padding-top:6px; color:#81908b; font-size:7px; text-align:center; }
        @media print { body{background:#fff}.toolbar{display:none}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.top-line{margin:-18mm -16mm 12mm}.footer{position:fixed;bottom:-8mm;right:0;left:0} }
        @if($pdf) body{background:#fff}.toolbar{display:none}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.top-line{margin:-18mm -16mm 12mm}.footer{position:fixed;bottom:-8mm;right:0;left:0} @endif
    </style>
</head>
<body>
@unless($pdf)<div class="toolbar"><a href="{{ route('documents.index', ['student_id' => $document->student_id]) }}">Kembali</a><button onclick="window.print()">Cetak</button><a class="primary" href="{{ route('documents.pdf', $document) }}">Unduh PDF</a></div>@endunless
<main class="sheet">
    <div class="top-line"></div>
    @if($document->revoked_at)<div class="revoked">DICABUT</div>@endif
    <table class="header"><tr><td style="width:82px">@if($logo)<img class="logo" src="{{ $logo }}" alt="Logo">@endif</td><td><div class="institution">{{ $snapshot['institution']['name'] }}</div><div class="address">{{ $snapshot['institution']['campus'] ?: 'Kampus Utama' }} · {{ $snapshot['institution']['address'] ?: 'Sistem Informasi Akademik Terpadu' }}</div></td><td class="right" style="width:115px"><strong>DOKUMEN RESMI</strong><br><span class="address">TERVERIFIKASI DIGITAL</span></td></tr></table>
    <h1 class="doc-title">{{ $typeLabel }}</h1><div class="doc-number">Nomor: {{ $document->document_number }}</div>
    <table class="meta"><tr><td>Nama</td><td>{{ $snapshot['student']['name'] }}</td><td></td><td>NIM</td><td>{{ $snapshot['student']['nim'] }}</td></tr><tr><td>Program Studi</td><td>{{ $snapshot['student']['program'] }} ({{ $snapshot['student']['program_code'] }})</td><td></td><td>Jenjang</td><td>{{ $snapshot['student']['degree'] }}</td></tr><tr><td>Fakultas</td><td>{{ $snapshot['student']['faculty'] }}</td><td></td><td>Diterbitkan</td><td>{{ $document->issued_at->locale('id')->translatedFormat('d F Y, H:i') }} WIB</td></tr></table>

    @if(in_array($document->type, ['krs','khs']))
        <div style="margin-bottom:12px"><strong>Semester:</strong> {{ $snapshot['term']['name'] }} · {{ $snapshot['term']['semester'] }} ({{ $snapshot['term']['code'] }})</div>
        <table class="data"><thead><tr><th class="center" style="width:30px">No</th><th style="width:75px">Kode</th><th>Mata kuliah</th><th class="center" style="width:45px">Kelas</th><th>Dosen</th><th class="center" style="width:40px">SKS</th>@if($document->type==='khs')<th class="center" style="width:45px">Nilai</th><th class="center" style="width:38px">Huruf</th>@endif</tr></thead><tbody>@foreach($snapshot['courses'] as $i=>$row)<tr><td class="center">{{ $i+1 }}</td><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="center">{{ $row['class'] }}</td><td>{{ $row['lecturer'] ?: '-' }}</td><td class="center">{{ $row['credits'] }}</td>@if($document->type==='khs')<td class="center">{{ number_format($row['score'],2,',','.') }}</td><td class="center"><strong>{{ $row['letter'] }}</strong></td>@endif</tr>@endforeach</tbody></table>
        <table class="summary"><tr><td><strong>{{ $snapshot['summary']['courses'] }}</strong><span>Mata kuliah</span></td><td><strong>{{ $snapshot['summary']['credits'] }}</strong><span>Total SKS</span></td><td><strong>{{ $document->type==='khs' ? number_format($snapshot['summary']['gpa'],2,',','.') : 'DISETUJUI' }}</strong><span>{{ $document->type==='khs' ? 'Indeks Prestasi' : 'Status KRS' }}</span></td></tr></table>
    @elseif($document->type==='transcript')
        <table class="data"><thead><tr><th class="center" style="width:28px">No</th><th style="width:75px">Semester</th><th style="width:65px">Kode</th><th>Mata kuliah</th><th class="center" style="width:35px">SKS</th><th class="center" style="width:45px">Nilai</th><th class="center" style="width:38px">Huruf</th></tr></thead><tbody>@foreach($snapshot['courses'] as $i=>$row)<tr><td class="center">{{ $i+1 }}</td><td>{{ $row['term'] }}</td><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="center">{{ $row['credits'] }}</td><td class="center">{{ number_format($row['score'],2,',','.') }}</td><td class="center"><strong>{{ $row['letter'] }}</strong></td></tr>@endforeach</tbody></table>
        <table class="summary"><tr><td><strong>{{ $snapshot['summary']['courses'] }}</strong><span>Mata kuliah</span></td><td><strong>{{ $snapshot['summary']['credits'] }}</strong><span>Total SKS</span></td><td><strong>{{ number_format($snapshot['summary']['gpa'],2,',','.') }}</strong><span>IP Kumulatif</span></td></tr></table>
    @elseif($document->type==='invoice')
        <div class="finance-card"><h2>{{ $snapshot['invoice']['description'] }}</h2><table class="finance-grid"><tr><td>Nomor tagihan</td><td class="right"><strong>{{ $snapshot['invoice']['number'] }}</strong></td></tr><tr><td>Periode akademik</td><td class="right">{{ $snapshot['term']['name'] ?? '-' }}</td></tr><tr><td>Jatuh tempo</td><td class="right">{{ $snapshot['invoice']['due_on'] ? \Carbon\Carbon::parse($snapshot['invoice']['due_on'])->locale('id')->translatedFormat('d F Y') : '-' }}</td></tr><tr><td>Status</td><td class="right"><strong>{{ strtoupper($snapshot['invoice']['status']) }}</strong></td></tr><tr><td>Sudah dibayar</td><td class="right">Rp {{ number_format($snapshot['invoice']['paid_amount'],0,',','.') }}</td></tr></table><div class="amount"><small>SISA TAGIHAN</small><strong>Rp {{ number_format($snapshot['invoice']['outstanding'],0,',','.') }}</strong></div></div>
    @else
        <div class="finance-card"><h2>Pembayaran telah diterima</h2><table class="finance-grid"><tr><td>Referensi</td><td class="right"><strong>{{ $snapshot['payment']['reference'] }}</strong></td></tr><tr><td>Kanal pembayaran</td><td class="right">{{ strtoupper($snapshot['payment']['provider']) }}</td></tr><tr><td>Waktu pembayaran</td><td class="right">{{ $snapshot['payment']['paid_at'] ? \Carbon\Carbon::parse($snapshot['payment']['paid_at'])->locale('id')->translatedFormat('d F Y, H:i') : '-' }} WIB</td></tr><tr><td>Status</td><td class="right"><strong>{{ strtoupper($snapshot['payment']['status']) }}</strong></td></tr></table><div class="amount"><small>TOTAL PEMBAYARAN</small><strong>Rp {{ number_format($snapshot['payment']['amount'],0,',','.') }}</strong></div></div>
        @if(count($snapshot['payment']['allocations']))<table class="data"><thead><tr><th>Nomor tagihan</th><th>Alokasi pembayaran</th><th class="right">Jumlah</th></tr></thead><tbody>@foreach($snapshot['payment']['allocations'] as $row)<tr><td>{{ $row['invoice_number'] }}</td><td>{{ $row['description'] }}</td><td class="right">Rp {{ number_format($row['amount'],0,',','.') }}</td></tr>@endforeach</tbody></table>@endif
    @endif

    <table class="signatures"><tr><td>@if(in_array($document->type,['krs','khs','transcript']))Mengetahui,<br>Ketua Program Studi<div class="signature-space"></div><strong>{{ $snapshot['approved_by'] ?? 'Pejabat Akademik Berwenang' }}</strong>@elseDokumen diterbitkan secara elektronik<br>oleh Unit Keuangan<div class="signature-space"></div><strong>{{ $document->issuer?->name }}</strong>@endif</td><td>{{ $snapshot['institution']['campus'] ?: 'Kampus Utama' }}, {{ $document->issued_at->locale('id')->translatedFormat('d F Y') }}<br>Penerima dokumen<div class="signature-space"></div><strong>{{ $snapshot['student']['name'] }}</strong></td></tr></table>
    <div class="verify-box"><table><tr><td style="width:94px"><img class="qr" src="{{ $qrCode }}" alt="QR verifikasi"></td><td><div class="verify-title">Verifikasi keaslian dokumen</div><p style="margin:5px 0">Pindai QR atau buka alamat berikut. Kode: <strong>{{ $document->verification_code }}</strong></p><div class="verify-url">{{ $verificationUrl }}</div><p class="security">Hash SHA-256: {{ $document->content_hash }} · Dokumen ini memakai snapshot data yang tidak berubah setelah diterbitkan.</p></td></tr></table></div>
    <div class="footer">{{ $document->document_number }} · Diterbitkan oleh {{ $snapshot['institution']['name'] }} · Halaman ini sah apabila status verifikasi digital dinyatakan VALID.</div>
</main>
</body></html>
