<?php

namespace App\Services;

use App\Models\Factura;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generación y envío del PDF de una factura. Centraliza lo que antes vivía
 * duplicado en FacturaController: cualquier flujo de cobro (POS, caja por
 * placa, comandas de restaurante) genera y envía el mismo recibo.
 */
class ReciboService
{
    public function __construct(private Notificador $notificador) {}

    /** Genera (o regenera) el PDF de la factura y guarda su URL. */
    public function generarPdf(Factura $factura): string
    {
        $factura->loadMissing(['cliente', 'detalles']);

        $firma = null;
        if ($factura->firma_url) {
            $ruta = str_replace('/storage/', '', $factura->firma_url);
            if (Storage::disk('public')->exists($ruta)) {
                $firma = 'data:' . Storage::disk('public')->mimeType($ruta)
                    . ';base64,' . base64_encode(Storage::disk('public')->get($ruta));
            }
        }

        $pdf = Pdf::loadView('pdf.factura', ['factura' => $factura, 'firma' => $firma]);

        $nombre = "facturas/{$factura->numero}_" . now()->timestamp . '.pdf';
        Storage::disk('public')->put($nombre, $pdf->output());
        $url = Storage::url($nombre);
        $factura->update(['pdf_url' => $url]);

        return $url;
    }

    /**
     * Envía el recibo al correo del cliente (si tiene) en segundo plano.
     * Nunca lanza excepciones: un fallo de correo no debe tumbar el cobro.
     */
    public function enviarPorCorreo(Factura $factura): void
    {
        try {
            $factura->loadMissing('cliente', 'empresa');
            $email = $factura->cliente?->email;
            if (! $email) {
                return;
            }

            if (! $factura->pdf_url) {
                $this->generarPdf($factura);
                $factura->refresh();
            }

            $adjunto = $factura->pdf_url
                ? Storage::disk('public')->path(str_replace('/storage/', '', $factura->pdf_url))
                : null;

            // Si la empresa configuró su propio correo de facturación, la factura
            // sale a nombre de ella (From/Reply-To) en vez del remitente genérico.
            $empresa = $factura->empresa;

            $this->notificador->correo(
                $email,
                "Factura {$factura->numero} - Logix",
                "Factura {$factura->numero}",
                [
                    "Hola {$factura->cliente->nombre_completo},",
                    "Gracias por tu compra. Adjuntamos tu factura {$factura->numero} por un total de \${$factura->total}.",
                    'Si tienes alguna duda, responde a este correo.',
                ],
                $adjunto,
                'FACTURA',
                $empresa?->email_facturacion,
                $empresa?->nombre,
            );
        } catch (\Throwable $e) {
            Log::warning('Fallo el envío automático de la factura', [
                'factura' => $factura->numero,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
