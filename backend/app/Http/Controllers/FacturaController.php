<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Auditoria;
use App\Models\Factura;
use App\Services\Notificador;
use App\Services\KardexService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FacturaController extends Controller
{
    public function __construct(private Notificador $notificador, private KardexService $kardex) {}

    public function index(Request $request)
    {
        $q = Factura::with('cliente:id,nombre_completo,email,telefono');
        if ($request->user()?->estaLimitadoABodega()) {
            $q->where('bodega_id', $request->user()->bodega_id);
        }
        if ($cliente = $request->query('cliente_id')) {
            $q->where('cliente_id', $cliente);
        }
        return $q->latest()->paginate(20);
    }

    public function show(Factura $factura)
    {
        return $factura->load(['cliente', 'detalles']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'fecha' => ['required', 'date'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas' => ['nullable', 'string'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.producto_id' => ['nullable', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'lineas.*.impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Firma digital: data URL (data:image/png;base64,...) dibujada o subida.
            'firma' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $bodegaId = $this->resolverBodegaFactura($request, $data['bodega_id'] ?? null);

            // IVA general usado como respaldo cuando una linea no define el suyo.
            $pctGeneral = $data['impuesto_porcentaje'] ?? 0;

            $subtotal = 0;
            $impuestos = 0;
            $detalles = [];

            foreach ($data['lineas'] as $l) {
                $base = round($l['cantidad'] * $l['precio_unitario'], 2);
                $pct = $l['impuesto_porcentaje'] ?? $pctGeneral;
                $impLinea = round($base * $pct / 100, 2);

                $subtotal += $base;
                $impuestos += $impLinea;

                $detalles[] = [
                    'producto_id' => $l['producto_id'] ?? null,
                    'descripcion' => $l['descripcion'],
                    'cantidad' => $l['cantidad'],
                    'precio_unitario' => $l['precio_unitario'],
                    'impuesto_porcentaje' => $pct,
                    'subtotal' => $base,
                    'impuesto' => $impLinea,
                ];
            }

            $factura = Factura::create([
                'numero' => $this->siguienteNumero(),
                'bodega_id' => $bodegaId,
                'cliente_id' => $data['cliente_id'],
                'fecha' => $data['fecha'],
                'subtotal' => $subtotal,
                'impuestos' => $impuestos,
                'total' => $subtotal + $impuestos,
                'estado' => 'EMITIDA',
                'notas' => $data['notas'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($detalles as $d) {
                $factura->detalles()->create($d);
                if (! empty($d['producto_id'])) {
                    $this->kardex->salida(
                        (int) $d['producto_id'],
                        $bodegaId,
                        (float) $d['cantidad'],
                        $request->user()->id,
                        'VENTA_FACTURA',
                        ['tipo' => 'FACTURA', 'id' => $factura->id],
                    );
                }
            }

            // Firma digital (dibujada o imagen subida) recibida como data URL.
            if (! empty($data['firma'])) {
                if ($url = $this->guardarFirma($factura, $data['firma'])) {
                    $factura->update(['firma_url' => $url]);
                }
            }

            // Notificación interna SOLO para el dueño de la factura (su workspace).
            $this->notificador->aUsuario($factura->owner_id, 'FACTURA',
                "Factura {$factura->numero} generada", "Total: \${$factura->total}");

            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'EMITIR', null, $factura->numero, $bodegaId);

            return response()->json($factura->load(['cliente:id,nombre_completo,email,telefono', 'detalles']), 201);
        });
    }

    /** Genera (o regenera) el PDF de la factura. */
    public function generarPdf(Factura $factura)
    {
        $this->autorizarBodega($factura);
        $factura->load(['cliente', 'detalles']);

        // Embebe la firma como data URI: DomPDF no resuelve rutas /storage/ relativas.
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

        return response()->json([
            'pdf_url' => $url,
            'pdf_public_url' => url($url),
            'pdf_api_url' => url("/api/facturas/{$factura->id}/pdf"),
        ]);
    }

    /** Muestra el PDF con headers correctos para navegadores y dispositivos moviles. */
    public function verPdf(Factura $factura)
    {
        $this->autorizarBodega($factura);

        if (! $factura->pdf_url) {
            $this->generarPdf($factura);
            $factura->refresh();
        }

        $ruta = str_replace('/storage/', '', (string) $factura->pdf_url);
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            abort(404, 'PDF no encontrado.');
        }

        $contenido = Storage::disk('public')->get($ruta);
        $nombre = "factura_{$factura->numero}.pdf";

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Content-Length' => strlen($contenido),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /** Envía la factura por correo a cualquier dirección. */
    public function enviar(Request $request, Factura $factura)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->autorizarBodega($factura);
        $factura->load(['cliente', 'detalles']);

        // Asegura que el PDF exista.
        if (! $factura->pdf_url) {
            $this->generarPdf($factura);
            $factura->refresh();
        }
        $adjunto = $factura->pdf_url
            ? Storage::disk('public')->path(str_replace('/storage/', '', $factura->pdf_url))
            : null;

        $enviado = $this->notificador->correo(
            $data['email'],
            "Factura {$factura->numero} - Logix",
            "Factura {$factura->numero}",
            [
                "Hola {$factura->cliente->nombre_completo},",
                "Adjuntamos tu factura {$factura->numero} por un total de \${$factura->total}.",
                'Gracias por tu confianza.',
            ],
            $adjunto,
            'FACTURA',
        );

        if (! $enviado) {
            return response()->json([
                'message' => 'No se pudo enviar el correo. Revisa MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS y MAIL_ENCRYPTION en Render.',
            ], 502);
        }

        return response()->json(['mensaje' => "Factura enviada a {$data['email']}."]);
    }

    public function whatsapp(Factura $factura)
    {
        $this->autorizarBodega($factura);
        $factura->load('cliente');

        if (! $factura->pdf_url) {
            $this->generarPdf($factura);
            $factura->refresh();
        }

        $telefono = preg_replace('/\D+/', '', (string) ($factura->cliente->telefono ?? ''));
        $urlPdf = url($factura->pdf_url);
        $mensaje = "Hola, adjuntamos tu factura numero {$factura->numero}: {$urlPdf}";

        return response()->json([
            'whatsapp_url' => 'https://wa.me/' . $telefono . '?text=' . rawurlencode($mensaje),
            'mensaje' => $mensaje,
            'pdf_public_url' => $urlPdf,
        ]);
    }

    private function siguienteNumero(): string
    {
        $ultimo = Factura::withTrashed()->count() + 1;
        return 'FAC-' . str_pad((string) $ultimo, 5, '0', STR_PAD_LEFT);
    }

    private function resolverBodegaFactura(Request $request, ?int $bodegaId): int
    {
        $user = $request->user();
        if ($user->estaLimitadoABodega()) {
            return (int) $user->bodega_id;
        }

        if ($bodegaId) {
            return $bodegaId;
        }

        $principal = Bodega::query()
            ->orderByDesc('es_principal')
            ->orderBy('id')
            ->value('id');

        if (! $principal) {
            throw ValidationException::withMessages([
                'bodega_id' => ['Debes crear una bodega antes de emitir facturas con productos.'],
            ]);
        }

        return (int) $principal;
    }

    private function autorizarBodega(Factura $factura): void
    {
        $user = request()->user();
        if ($user?->estaLimitadoABodega() && (int) $factura->bodega_id !== (int) $user->bodega_id) {
            abort(403, 'No tienes acceso a facturas de otro establecimiento.');
        }
    }

    /**
     * Decodifica una firma recibida como data URL (data:image/png;base64,...),
     * la guarda en el disco público y devuelve la URL. Acepta PNG/JPG/JPEG.
     */
    private function guardarFirma(Factura $factura, string $dataUrl): ?string
    {
        if (! preg_match('/^data:image\/(png|jpe?g);base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $contenido = base64_decode($m[2], true);
        if ($contenido === false) {
            return null;
        }

        $nombre = "firmas/factura_{$factura->id}_" . now()->timestamp . ".{$ext}";
        Storage::disk('public')->put($nombre, $contenido);

        return Storage::url($nombre);
    }
}
