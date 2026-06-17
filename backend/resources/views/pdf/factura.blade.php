<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .box { border: 1px solid #e5e7eb; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; }
        .right { text-align: right; }
        .tot { font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Factura {{ $factura->numero }}</h1>
        <span class="muted">Logix · {{ $factura->fecha->format('d/m/Y') }} · {{ $factura->estado }}</span>
    </div>

    <div class="box">
        <strong>Cliente:</strong> {{ $factura->cliente->nombre_completo }}<br>
        @if ($factura->cliente->numero_documento)<span class="muted">{{ $factura->cliente->tipo_documento }} {{ $factura->cliente->numero_documento }}</span><br>@endif
        @if ($factura->cliente->email)<span class="muted">{{ $factura->cliente->email }}</span>@endif
    </div>

    <table>
        <thead>
            <tr><th>Descripción</th><th class="right">Cant.</th><th class="right">Precio</th><th class="right">IVA</th><th class="right">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($factura->detalles as $d)
            <tr>
                <td>{{ $d->descripcion }}</td>
                <td class="right">{{ number_format($d->cantidad, 2) }}</td>
                <td class="right">${{ number_format($d->precio_unitario, 2) }}</td>
                <td class="right">{{ number_format($d->impuesto_porcentaje, 0) }}%</td>
                <td class="right">${{ number_format($d->subtotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top:12px;width:40%;float:right;">
        <tr><td>Subtotal</td><td class="right">${{ number_format($factura->subtotal, 2) }}</td></tr>
        <tr><td>Impuestos</td><td class="right">${{ number_format($factura->impuestos, 2) }}</td></tr>
        <tr class="tot"><td>TOTAL</td><td class="right">${{ number_format($factura->total, 2) }}</td></tr>
    </table>

    @if (!empty($firma))
        <div style="clear:both;margin-top:48px;width:240px;">
            <img src="{{ $firma }}" alt="Firma" style="max-height:90px;max-width:240px;">
            <div style="border-top:1px solid #111827;margin-top:4px;padding-top:4px;" class="muted">Firma autorizada</div>
        </div>
    @endif
</body>
</html>
