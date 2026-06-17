<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\StockBodega;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio central del Kardex.
 *
 * Reglas:
 *  - El stock vive en stock_por_bodega (producto × bodega), nunca se edita a mano.
 *  - Costeo por COSTO PROMEDIO PONDERADO: se recalcula en cada ENTRADA.
 *  - Las SALIDAS se valoran al costo promedio vigente (no lo modifican).
 *  - Todo ocurre dentro de una transacción para mantener consistencia.
 */
class KardexService
{
    /**
     * Registra una ENTRADA (compra, devolución, ajuste positivo) en una bodega.
     */
    public function entrada(int $productoId, int $bodegaId, float $cantidad, float $costoUnitario, ?int $usuarioId = null, string $motivo = 'COMPRA', array $referencia = []): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $costoUnitario, $usuarioId, $motivo, $referencia) {
            $stock = $this->lockStock($productoId, $bodegaId);

            $cantidadActual = (float) $stock->cantidad;
            $costoActual = (float) $stock->costo_promedio;

            // Costo promedio ponderado.
            $nuevaCantidad = $cantidadActual + $cantidad;
            $nuevoCosto = $nuevaCantidad > 0
                ? (($cantidadActual * $costoActual) + ($cantidad * $costoUnitario)) / $nuevaCantidad
                : $costoUnitario;

            $stock->cantidad = $nuevaCantidad;
            $stock->costo_promedio = $nuevoCosto;
            $stock->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'ENTRADA',
                'motivo' => $motivo,
                'bodega_destino_id' => $bodegaId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costoUnitario,
                'costo_promedio_resultante' => $nuevoCosto,
                'stock_resultante' => $nuevaCantidad,
                'usuario_id' => $usuarioId,
            ], $referencia);
        });
    }

    /**
     * Registra una SALIDA (venta, pérdida, ajuste negativo) desde una bodega.
     */
    public function salida(int $productoId, int $bodegaId, float $cantidad, ?int $usuarioId = null, string $motivo = 'VENTA', array $referencia = []): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $usuarioId, $motivo, $referencia) {
            $stock = $this->lockStock($productoId, $bodegaId);

            if ((float) $stock->cantidad < $cantidad) {
                throw ValidationException::withMessages([
                    'cantidad' => ["Stock insuficiente en la bodega (disponible: {$stock->cantidad})."],
                ]);
            }

            $costo = (float) $stock->costo_promedio; // las salidas no alteran el promedio
            $stock->cantidad = (float) $stock->cantidad - $cantidad;
            $stock->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'SALIDA',
                'motivo' => $motivo,
                'bodega_origen_id' => $bodegaId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'costo_promedio_resultante' => $costo,
                'stock_resultante' => $stock->cantidad,
                'usuario_id' => $usuarioId,
            ], $referencia);
        });
    }

    /**
     * Traslada stock entre dos bodegas (salida en origen + entrada en destino).
     */
    public function traslado(int $productoId, int $bodegaOrigenId, int $bodegaDestinoId, float $cantidad, ?int $usuarioId = null): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        if ($bodegaOrigenId === $bodegaDestinoId) {
            throw ValidationException::withMessages([
                'bodega_destino_id' => ['La bodega de origen y destino no pueden ser la misma.'],
            ]);
        }

        return DB::transaction(function () use ($productoId, $bodegaOrigenId, $bodegaDestinoId, $cantidad, $usuarioId) {
            $origen = $this->lockStock($productoId, $bodegaOrigenId);

            if ((float) $origen->cantidad < $cantidad) {
                throw ValidationException::withMessages([
                    'cantidad' => ["Stock insuficiente en la bodega de origen (disponible: {$origen->cantidad})."],
                ]);
            }

            $costoTraslado = (float) $origen->costo_promedio;

            // Salida en origen.
            $origen->cantidad = (float) $origen->cantidad - $cantidad;
            $origen->save();

            // Entrada en destino (recalcula su promedio con el costo trasladado).
            $destino = $this->lockStock($productoId, $bodegaDestinoId);
            $cantDestino = (float) $destino->cantidad;
            $costoDestino = (float) $destino->costo_promedio;
            $nuevaCantDestino = $cantDestino + $cantidad;
            $nuevoCostoDestino = $nuevaCantDestino > 0
                ? (($cantDestino * $costoDestino) + ($cantidad * $costoTraslado)) / $nuevaCantDestino
                : $costoTraslado;
            $destino->cantidad = $nuevaCantDestino;
            $destino->costo_promedio = $nuevoCostoDestino;
            $destino->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'TRASLADO',
                'motivo' => 'TRASLADO',
                'bodega_origen_id' => $bodegaOrigenId,
                'bodega_destino_id' => $bodegaDestinoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costoTraslado,
                'costo_promedio_resultante' => $nuevoCostoDestino,
                'stock_resultante' => $nuevaCantDestino,
                'usuario_id' => $usuarioId,
            ], []);
        });
    }

    /**
     * Obtiene (o crea) y bloquea la fila de stock producto×bodega.
     */
    private function lockStock(int $productoId, int $bodegaId): StockBodega
    {
        StockBodega::firstOrCreate(
            ['producto_id' => $productoId, 'bodega_id' => $bodegaId],
            ['cantidad' => 0, 'stock_minimo' => 0, 'costo_promedio' => 0]
        );

        return StockBodega::where('producto_id', $productoId)
            ->where('bodega_id', $bodegaId)
            ->lockForUpdate()
            ->first();
    }

    private function registrar(array $datos, array $referencia): MovimientoInventario
    {
        if (! empty($referencia)) {
            $datos['referencia_tipo'] = $referencia['tipo'] ?? null;
            $datos['referencia_id'] = $referencia['id'] ?? null;
        }

        return MovimientoInventario::create($datos);
    }

    private function validarCantidadPositiva(float $cantidad): void
    {
        if ($cantidad <= 0) {
            throw ValidationException::withMessages([
                'cantidad' => ['La cantidad debe ser mayor a cero.'],
            ]);
        }
    }
}
