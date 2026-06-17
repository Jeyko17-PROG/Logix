<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#0f172a;color:#fff;padding:16px 24px;border-radius:12px 12px 0 0;">
            <h1 style="margin:0;font-size:20px;">Logix</h1>
        </div>
        <div style="background:#fff;padding:24px;border-radius:0 0 12px 12px;">
            <h2 style="color:#0f172a;margin-top:0;">{{ $titulo }}</h2>
            @foreach ($lineas as $linea)
                <p style="color:#334155;font-size:15px;line-height:1.5;margin:8px 0;">{{ $linea }}</p>
            @endforeach
            <p style="color:#94a3b8;font-size:12px;margin-top:24px;">Este es un mensaje automático de Logix.</p>
        </div>
    </div>
</body>
</html>
