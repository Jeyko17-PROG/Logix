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
            'currency' => ['nullable', 'in:COP,USD'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
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
                'currency' => $data['currency'] ?? 'COP',
                'exchange_rate' => $data['exchange_rate'] ?? null,
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

    /**
     * Actualiza una factura existente (editar líneas y metadatos).
     * Ajusta el stock usando KardexService en base a la diferencia entre
     * líneas antiguas y nuevas.
     */
    public function update(Request $request, Factura $factura)
    {
        $this->autorizarBodega($factura);

        $data = $request->validate([
            'fecha' => ['nullable', 'date'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas' => ['nullable', 'string'],
            'lineas' => ['nullable', 'array', 'min:1'],
            'lineas.*.descripcion' => ['required_with:lineas', 'string', 'max:255'],
            'lineas.*.producto_id' => ['nullable', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required_with:lineas', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['required_with:lineas', 'numeric', 'min:0'],
            'lineas.*.impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'in:COP,USD'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data, $request, $factura) {
            $bodegaId = $this->resolverBodegaFactura($request, $data['bodega_id'] ?? $factura->bodega_id);

            // Si vienen lineas, calcular subtotal/impuestos/total y ajustar stock por diferencias.
            if (! empty($data['lineas'])) {
                $pctGeneral = $data['impuesto_porcentaje'] ?? 0;

                $subtotal = 0;
                $impuestos = 0;
                $nuevas = [];
                foreach ($data['lineas'] as $l) {
                    $base = round($l['cantidad'] * $l['precio_unitario'], 2);
                    $pct = $l['impuesto_porcentaje'] ?? $pctGeneral;
                    $impLinea = round($base * $pct / 100, 2);
                    $subtotal += $base;
                    $impuestos += $impLinea;

                    $nuevas[] = [
                        'producto_id' => $l['producto_id'] ?? null,
                        'descripcion' => $l['descripcion'],
                        'cantidad' => $l['cantidad'],
                        'precio_unitario' => $l['precio_unitario'],
                        'impuesto_porcentaje' => $pct,
                        'subtotal' => $base,
                        'impuesto' => $impLinea,
                    ];
                }

                // Mapear cantidades por producto (solo aquellos con producto_id)
                $oldMap = [];
                foreach ($factura->detalles as $d) {
                    if ($d->producto_id) {
                        $oldMap[$d->producto_id] = ($oldMap[$d->producto_id] ?? 0) + (float) $d->cantidad;
                    }
                }

                $newMap = [];
                foreach ($nuevas as $d) {
                    if (! empty($d['producto_id'])) {
                        $newMap[$d['producto_id']] = ($newMap[$d['producto_id']] ?? 0) + (float) $d['cantidad'];
                    }
                }

                // Ajustes por producto: si diff>0 => salida, diff<0 => entrada
                $productoIds = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
                foreach ($productoIds as $pid) {
                    $oldCant = $oldMap[$pid] ?? 0.0;
                    $newCant = $newMap[$pid] ?? 0.0;
                    $diff = $newCant - $oldCant;
                    if ($diff > 0) {
                        $this->kardex->salida((int) $pid, $bodegaId, (float) $diff, $request->user()->id, 'VENTA_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                    } elseif ($diff < 0) {
                        $this->kardex->entrada((int) $pid, $bodegaId, (float) abs($diff),  $request->user()->id, 'DEVOLUCION_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                    }
                }

                // Reemplaza detalles (fácil y consistente)
                $factura->detalles()->delete();
                foreach ($nuevas as $d) {
                    $factura->detalles()->create($d);
                }

                $factura->subtotal = $subtotal;
                $factura->impuestos = $impuestos;
                $factura->total = $subtotal + $impuestos;
            }

            $updates = [];
            if (isset($data['fecha'])) $updates['fecha'] = $data['fecha'];
            if (isset($data['notas'])) $updates['notas'] = $data['notas'];
            if (isset($data['currency'])) $updates['currency'] = $data['currency'];
            if (array_key_exists('exchange_rate', $data)) $updates['exchange_rate'] = $data['exchange_rate'];

            if (! empty($updates)) {
                $factura->update($updates);
            } else {
                $factura->save();
            }

            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'EDITAR', null, $factura->numero, $bodegaId);

            return response()->json($factura->fresh('detalles'));
        });
    }

    /**
     * Elimina (soft) una factura y devuelve el stock de los productos involucrados.
     */
    public function destroy(Request $request, Factura $factura)
    {
        $this->autorizarBodega($factura);

        return DB::transaction(function () use ($request, $factura) {
            $bodegaId = $factura->bodega_id;

            foreach ($factura->detalles as $d) {
                if (! empty($d->producto_id) && (float) $d->cantidad > 0) {
                    $this->kardex->entrada((int) $d->producto_id, $bodegaId, (float) $d->cantidad, $request->user()->id, 'REVERSO_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                }
            }

            $factura->delete();
            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'ELIMINAR', null, $factura->numero, $bodegaId);

            return response()->json(['message' => 'Factura eliminada y stock restaurado.']);
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
