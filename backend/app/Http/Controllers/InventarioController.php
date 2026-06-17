<?php

namespace App\Http\Controllers;

use App\Models\MovimientoInventario;
use App\Models\StockBodega;
use App\Services\KardexService;
use Illuminate\Http\Request;

class InventarioController extends Controller
{
    public function __construct(private KardexService $kardex) {}

    /**
     * Stock actual por producto y bodega.
     */
    public function stock(Request $request)
    {
        $q = StockBodega::with(['producto:id,sku,nombre,stock_minimo', 'bodega:id,nombre']);
        if ($bodega = $request->query('bodega_id')) {
            $q->where('bodega_id', $bodega);
        }
        return $q->orderByDesc('cantidad')->paginate(30);
    }

    /**
     * Listado de movimientos (Kardex), opcionalmente filtrado por producto.
     */
    public function movimientos(Request $request)
    {
        $q = MovimientoInventario::with([
            'producto:id,sku,nombre',
            'bodegaOrigen:id,nombre',
            'bodegaDestino:id,nombre',
            'usuario:id,name',
        ]);
        if ($producto = $request->query('producto_id')) {
            $q->where('producto_id', $producto);
        }
        return $q->latest()->paginate(30);
    }

    /**
     * Alertas de reabastecimiento: stock <= stock_minimo (con mínimo > 0).
     */
    public function alertas()
    {
        return StockBodega::with(['producto:id,sku,nombre', 'bodega:id,nombre'])
            ->where('stock_minimo', '>', 0)
            ->whereColumn('cantidad', '<=', 'stock_minimo')
            ->orderBy('cantidad')
            ->get();
    }

    /**
     * Define/actualiza el stock mínimo de un producto en una bodega.
     */
    public function definirMinimo(Request $request)
    {
        $data = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'bodega_id' => ['required', 'exists:bodegas,id'],
            'stock_minimo' => ['required', 'numeric', 'min:0'],
        ]);

        $stock = StockBodega::firstOrCreate(
            ['producto_id' => $data['producto_id'], 'bodega_id' => $data['bodega_id']],
            ['cantidad' => 0, 'costo_promedio' => 0]
        );
        $stock->update(['stock_minimo' => $data['stock_minimo']]);

        return $stock;
    }

    /**
     * Registra un movimiento de inventario (entrada / salida / traslado).
     */
    public function registrarMovimiento(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:ENTRADA,SALIDA,TRASLADO'],
            'producto_id' => ['required', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'max:50'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'bodega_origen_id' => ['nullable', 'exists:bodegas,id'],
            'bodega_destino_id' => ['nullable', 'exists:bodegas,id'],
        ]);

        $userId = $request->user()->id;

        $movimiento = match ($data['tipo']) {
            'ENTRADA' => $this->kardex->entrada(
                $data['producto_id'],
                $this->requerir($data, 'bodega_destino_id', 'la bodega de destino'),
                $data['cantidad'],
                $data['costo_unitario'] ?? 0,
                $userId,
                $data['motivo'] ?? 'COMPRA',
            ),
            'SALIDA' => $this->kardex->salida(
                $data['producto_id'],
                $this->requerir($data, 'bodega_origen_id', 'la bodega de origen'),
                $data['cantidad'],
                $userId,
                $data['motivo'] ?? 'VENTA',
            ),
            'TRASLADO' => $this->kardex->traslado(
                $data['producto_id'],
                $this->requerir($data, 'bodega_origen_id', 'la bodega de origen'),
                $this->requerir($data, 'bodega_destino_id', 'la bodega de destino'),
                $data['cantidad'],
                $userId,
            ),
        };

        return response()->json($movimiento->load(['producto:id,sku,nombre']), 201);
    }

    private function requerir(array $data, string $campo, string $nombre): int
    {
        abort_unless(! empty($data[$campo]), 422, "Debes indicar {$nombre}.");
        return (int) $data[$campo];
    }
}
