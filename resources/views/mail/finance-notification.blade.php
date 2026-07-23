<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif;margin:0;padding:28px">
<main style="background:#ffffff;border-radius:16px;margin:auto;max-width:620px;padding:28px">
    <p style="color:#0f766e;font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase">{{ config('siakad.institution') }}</p>
    <h1 style="font-size:22px;margin:12px 0">{{ $mailSubject }}</h1>
    <p style="color:#475569;font-size:14px;line-height:1.7">{{ $messageContent }}</p>
    <div style="background:#f8fafc;border-radius:12px;margin-top:20px;padding:16px">
        @foreach($details as $label => $value)
            @if(filled($value) && $label !== 'link')
                <p style="font-size:13px;margin:7px 0"><strong>{{ $label }}</strong><br>{{ $value }}</p>
            @endif
        @endforeach
    </div>
    @if(filled($details['link'] ?? null))
        <p style="margin-top:24px"><a href="{{ $details['link'] }}" style="background:#0f766e;border-radius:10px;color:#fff;display:inline-block;font-size:13px;font-weight:bold;padding:12px 18px;text-decoration:none">Buka portal SIAKAD</a></p>
    @endif
    <p style="color:#94a3b8;font-size:11px;margin-top:28px">Pesan otomatis transaksional. Jangan membalas email ini.</p>
</main>
</body>
</html>
