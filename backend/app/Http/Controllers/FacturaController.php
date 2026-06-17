<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Services\Notificador;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FacturaController extends Controller
{
    public function __construct(private Notificador $notificador) {}

    public function index(Request $request)
    {
        $q = Factura::with('cliente:id,nombre_completo');
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
            // IVA general usado como respaldo cuando una línea no define el suyo.
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

            return response()->json($factura->load(['cliente:id,nombre_completo', 'detalles']), 201);
        });
    }

    /** Genera (o regenera) el PDF de la factura. */
    public function generarPdf(Factura $factura)
    {
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

        return response()->json(['pdf_url' => $url]);
    }

    /** Envía la factura por correo a cualquier dirección. */
    public function enviar(Request $request, Factura $factura)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $factura->load(['cliente', 'detalles']);

        // Asegura que el PDF exista.
        if (! $factura->pdf_url) {
            $this->generarPdf($factura);
            $factura->refresh();
        }
        $adjunto = $factura->pdf_url
            ? Storage::disk('public')->path(str_replace('/storage/', '', $factura->pdf_url))
            : null;

        $this->notificador->correo(
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

        return response()->json(['mensaje' => "Factura enviada a {$data['email']}."]);
    }

    private function siguienteNumero(): string
    {
        $ultimo = Factura::withTrashed()->count() + 1;
        return 'FAC-' . str_pad((string) $ultimo, 5, '0', STR_PAD_LEFT);
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
