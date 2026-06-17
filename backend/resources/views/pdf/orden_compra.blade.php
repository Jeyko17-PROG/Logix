<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #6b7280; }
        .box { border: 1px solid #e5e7eb; padding: 10px; border-radius: 6px; margin-top: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; }
        .right { text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Orden de Compra #{{ $orden->id }}</h1>
        <span class="muted">Logix ERP · {{ $orden->fecha->format('d/m/Y') }} · Estado: {{ $orden->estado }}</span>
    </div>

    <div class="box">
        <strong>Proveedor:</strong> {{ $orden->proveedor->razon_social }}<br>
        <span class="muted">{{ $orden->proveedor->tipo_documento }} {{ $orden->proveedor->numero_documento }}{{ $orden->proveedor->digito_verificacion ? '-'.$orden->proveedor->digito_verificacion : '' }}</span><br>
        <strong>Bodega destino:</strong> {{ $orden->bodega->nombre ?? '—' }}
    </div>

    <table>
        <thead>
            <tr><th>SKU</th><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio unit.</th><th class="right">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($orden->detalles as $d)
            <tr>
                <td>{{ $d->producto->sku }}</td>
                <td>{{ $d->producto->nombre }}</td>
                <td class="right">{{ number_format($d->cantidad, 2) }}</td>
                <td class="right">${{ number_format($d->precio_unitario, 2) }}</td>
                <td class="right">${{ number_format($d->cantidad * $d->precio_unitario, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="right total">TOTAL</td><td class="right total">${{ number_format($orden->total, 2) }}</td></tr>
        </tfoot>
    </table>

    <p class="muted" style="margin-top: 30px;">
        Documento generado automáticamente por Logix ERP.
        @if ($firma)
            <br>Firma electrónica: <strong>{{ $firma->estado }}</strong>
            @if ($firma->hash_documento) · Hash: {{ substr($firma->hash_documento, 0, 24) }}… @endif
        @endif
    </p>
</body>
</html>
