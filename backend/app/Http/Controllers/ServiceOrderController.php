<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderDetail;
use App\Models\Producto;
use App\Models\OperablesEmployee;
use App\Models\AssetHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;

class ServiceOrderController extends Controller
{
    /**
     * Listar órdenes de servicio.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ServiceOrder::with([
            'cliente:id,nombre',
            'assetVehicle:id,placa_identificador,marca,modelo',
            'details.operablesEmployee:id,nombre,apellido',
            'details.producto:id,nombre,precio_venta',
        ]);

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($clienteId = $request->query('cliente_id')) {
            $query->where('cliente_id', $clienteId);
        }

        if ($buscar = $request->query('buscar')) {
            $query->where(function ($w) use ($buscar) {
                $w->where('numero_orden', 'like', "%{$buscar}%")
                    ->orWhere('descripcion_trabajo', 'like', "%{$buscar}%")
                    ->orWhereHas('cliente', fn (Builder $q) => $q->where('nombre', 'like', "%{$buscar}%"));
            });
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    /**
     * Crear una nueva orden de servicio.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $data['owner_id'] = auth()->id();
        $data['numero_orden'] = ServiceOrder::generarNumeroOrden(auth()->id());

        $orden = ServiceOrder::create($data);
        return response()->json($orden->load('cliente:id,nombre', 'assetVehicle:id,placa_identificador,marca,modelo'), 201);
    }

    /**
     * Obtener detalle de una orden.
     */
    public function show(ServiceOrder $serviceOrder): JsonResponse
    {
        return response()->json(
            $serviceOrder->load([
                'cliente:id,nombre,telefono,email',
                'assetVehicle:id,placa_identificador,marca,modelo,anio,color',
                'details' => fn ($q) => $q->with([
                    'producto:id,nombre,precio_venta,is_service',
                    'operablesEmployee:id,nombre,apellido,ci_cedula',
                ]),
            ])
        );
    }

    /**
     * Actualizar una orden de servicio.
     */
    public function update(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        // Permitir cambios solo si no está facturada
        if ($serviceOrder->estado === 'facturado') {
            return response()->json(['error' => 'No se puede editar una orden facturada.'], 403);
        }

        $data = $request->validate([
            'descripcion_trabajo' => ['nullable', 'string'],
            'estado' => ['in:recibido,en_proceso,listo,facturado,cancelado'],
            'fecha_entrega_estimada' => ['nullable', 'date'],
            'requiere_pago_anticipo' => ['boolean'],
            'monto_anticipo' => ['nullable', 'numeric', 'min:0'],
        ]);

        $serviceOrder->update($data);
        return response()->json($serviceOrder->load('details'));
    }

    /**
     * Agregar un detalle a la orden.
     */
    public function agregarDetalle(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $data = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'precio_unitario' => ['required', 'numeric', 'min:0'],
            'tiene_comision' => ['boolean'],
            'tipo_comision' => ['nullable', 'in:percentage,fixed'],
            'comision_value' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        $subtotal = $data['cantidad'] * $data['precio_unitario'];
        $data['subtotal'] = $subtotal;

        // Crear el detalle
        $detail = $serviceOrder->details()->create($data);

        // Calcular comisión si aplica
        if ($data['tiene_comision']) {
            $detail->calcularComision();
            $detail->save();
        }

        // Recalcular totales de la orden
        $serviceOrder->recalculateTotals();

        return response()->json($detail->load('producto:id,nombre', 'operablesEmployee:id,nombre,apellido'), 201);
    }

    /**
     * Actualizar un detalle de la orden (incluyendo comisiones en caliente).
     */
    public function actualizarDetalle(Request $request, ServiceOrder $serviceOrder, ServiceOrderDetail $detail): JsonResponse
    {
        $data = $request->validate([
            'cantidad' => ['integer', 'min:1'],
            'precio_unitario' => ['numeric', 'min:0'],
            'tiene_comision' => ['boolean'],
            'tipo_comision' => ['nullable', 'in:percentage,fixed'],
            'comision_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['cantidad']) && isset($data['precio_unitario'])) {
            $data['subtotal'] = $data['cantidad'] * $data['precio_unitario'];
        }

        $detail->update($data);

        // Recalcular comisión si cambió
        if (isset($data['tiene_comision']) || isset($data['tipo_comision']) || isset($data['comision_value'])) {
            $detail->calcularComision();
            $detail->save();
        }

        $serviceOrder->recalculateTotals();

        return response()->json($detail);
    }

    /**
     * Eliminar un detalle.
     */
    public function eliminarDetalle(ServiceOrder $serviceOrder, ServiceOrderDetail $detail): JsonResponse
    {
        $detail->delete();
        $serviceOrder->recalculateTotals();
        return response()->json(['message' => 'Detalle eliminado.']);
    }

    /**
     * Preparar para facturación (cambiar estado a "listo").
     */
    public function prepararFacturacion(ServiceOrder $serviceOrder): JsonResponse
    {
        if (empty($serviceOrder->details)) {
            return response()->json(['error' => 'La orden debe tener al menos un detalle.'], 422);
        }

        $serviceOrder->update(['estado' => 'listo']);
        return response()->json($serviceOrder);
    }

    /**
     * Marcar como completada y registrar hoja de vida.
     */
    public function completar(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $data = $request->validate([
            'km_salida' => ['nullable', 'integer', 'min:0'],
            'estado_salida' => ['nullable', 'string', 'max:255'],
        ]);

        // Registrar en hoja de vida si hay asset
        if ($serviceOrder->asset_vehicle_id) {
            AssetHistory::create([
                'asset_vehicle_id' => $serviceOrder->asset_vehicle_id,
                'service_order_id' => $serviceOrder->id,
                'descripcion_trabajo' => $serviceOrder->descripcion_trabajo,
                'costo_total' => $serviceOrder->total,
                'km_salida' => $data['km_salida'] ?? null,
                'estado_salida' => $data['estado_salida'] ?? null,
                'fecha_entrada' => $serviceOrder->fecha_recepcion,
                'fecha_salida' => now(),
            ]);
        }

        $serviceOrder->update([
            'estado' => 'listo',
            'fecha_entrega_real' => now(),
        ]);

        return response()->json($serviceOrder);
    }

    /**
     * Buscar órdenes o activos para precarga rápida en POS.
     */
    public function buscarPorPlacaOOrden(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json([]);
        }

        // Buscar órdenes de servicio
        $ordenes = ServiceOrder::where('numero_orden', 'like', "%{$query}%")
            ->where('estado', '!=', 'facturado')
            ->with('assetVehicle:id,placa_identificador,marca,modelo')
            ->limit(5)
            ->get();

        // Buscar por placa de activo
        $activos = \App\Models\AssetVehicle::where('placa_identificador', 'like', "%{$query}%")
            ->with('cliente:id,nombre')
            ->limit(5)
            ->get();

        return response()->json([
            'ordenes' => $ordenes,
            'activos' => $activos,
        ]);
    }

    /**
     * Obtener estados disponibles.
     */
    public function estados(): JsonResponse
    {
        return response()->json([
            'estados' => [
                ['valor' => 'recibido', 'etiqueta' => 'Recibido'],
                ['valor' => 'en_proceso', 'etiqueta' => 'En Proceso'],
                ['valor' => 'listo', 'etiqueta' => 'Listo'],
                ['valor' => 'facturado', 'etiqueta' => 'Facturado'],
                ['valor' => 'cancelado', 'etiqueta' => 'Cancelado'],
            ],
        ]);
    }

    /**
     * Validar datos de la orden.
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'asset_vehicle_id' => ['nullable', 'exists:assets_vehicles,id'],
            'descripcion_trabajo' => ['nullable', 'string'],
            'fecha_entrega_estimada' => ['nullable', 'date'],
            'requiere_pago_anticipo' => ['boolean'],
            'monto_anticipo' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}
