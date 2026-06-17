<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\OrdenCompra;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class DocumentoController extends Controller
{
    public function index()
    {
        return Documento::with(['firma', 'creador:id,name'])->latest()->paginate(20);
    }

    /**
     * Genera el PDF de una orden de compra, lo guarda y registra el documento.
     */
    public function generarOrdenCompra(OrdenCompra $orden)
    {
        $orden->load(['proveedor', 'bodega:id,nombre', 'detalles.producto:id,sku,nombre']);

        $pdf = Pdf::loadView('pdf.orden_compra', [
            'orden' => $orden,
            'firma' => null,
        ]);

        $nombre = "documentos/orden_compra_{$orden->id}_" . now()->timestamp . '.pdf';
        Storage::disk('public')->put($nombre, $pdf->output());
        $url = Storage::url($nombre);

        // Reutiliza el documento si ya existía para esta orden.
        $documento = Documento::updateOrCreate(
            ['entidad_tipo' => OrdenCompra::class, 'entidad_id' => $orden->id, 'tipo' => 'ORDEN_COMPRA'],
            ['archivo_url' => $url, 'created_by' => request()->user()->id],
        );

        return response()->json($documento->load('firma'), 201);
    }
}
