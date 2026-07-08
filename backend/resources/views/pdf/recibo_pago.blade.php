<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de pago #{{ $transaccion->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; padding: 32px; }
        .encabezado { display: table; width: 100%; margin-bottom: 24px; border-bottom: 3px solid #2563eb; padding-bottom: 16px; }
        .marca { display: table-cell; vertical-align: middle; }
        .marca h1 { font-size: 22px; color: #2563eb; }
        .marca p { color: #6b7280; font-size: 10px; }
        .num { display: table-cell; text-align: right; vertical-align: middle; }
        .num .titulo { font-size: 16px; font-weight: bold; color: #111827; }
        .num .ref { color: #6b7280; font-size: 10px; }
        .bloque { margin-bottom: 18px; }
        .bloque h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 6px; }
        table.detalle { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.detalle th { background: #2563eb; color: #fff; text-align: left; padding: 8px 10px; font-size: 11px; }
        table.detalle td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; }
        .total { text-align: right; margin-top: 14px; font-size: 16px; font-weight: bold; color: #111827; }
        .estado { display: inline-block; background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .pie { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <div class="encabezado">
        <div class="marca">
            <h1>Logix POS</h1>
            <p>Plataforma de punto de venta y gestión para talleres y negocios</p>
        </div>
        <div class="num">
            <div class="titulo">RECIBO DE PAGO</div>
            <div class="ref">No. {{ str_pad($transaccion->id, 6, '0', STR_PAD_LEFT) }}</div>
            <div class="ref">{{ $fecha->format('d/m/Y h:i A') }}</div>
        </div>
    </div>

    <div class="bloque">
        <h2>Cliente</h2>
        <strong>{{ $usuario->name }}</strong><br>
        {{ $usuario->email }}
        @if($usuario->numero_documento)
            <br>{{ $usuario->tipo_documento }} {{ $usuario->numero_documento }}
        @endif
    </div>

    <div class="bloque">
        <h2>Detalle del pago</h2>
        <table class="detalle">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Referencia</th>
                    <th>Medio</th>
                    <th style="text-align:right">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $concepto }}</td>
                    <td>{{ $transaccion->payload['reference'] ?? $transaccion->provider_event_id ?? ('TX-' . $transaccion->id) }}</td>
                    <td>{{ strtoupper($transaccion->provider) }}</td>
                    <td style="text-align:right">${{ number_format($monto, 0, ',', '.') }} COP</td>
                </tr>
            </tbody>
        </table>
        <div class="total">Total pagado: ${{ number_format($monto, 0, ',', '.') }} COP &nbsp; <span class="estado">APROBADO</span></div>
    </div>

    <div class="pie">
        Este recibo confirma el pago recibido por Logix a través de la pasarela {{ strtoupper($transaccion->provider) }}.<br>
        Documento generado automáticamente — no requiere firma.
    </div>
</body>
</html>
