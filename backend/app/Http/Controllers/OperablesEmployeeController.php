<?php

namespace App\Http\Controllers;

use App\Models\OperablesEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OperablesEmployeeController extends Controller
{
    /**
     * Listar todos los empleados operables del inquilino.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OperablesEmployee::with(['commissionLiquidations']);
        
        if ($buscar = $request->query('buscar')) {
            $query->where(function ($w) use ($buscar) {
                $w->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('apellido', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%");
            });
        }

        if ($tipo = $request->query('tipo_operario')) {
            $query->where('tipo_operario', $tipo);
        }

        $query->where('activo', true)->orderBy('nombre');

        return response()->json($query->paginate(20));
    }

    /**
     * Crear un nuevo empleado operable.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $empleado = OperablesEmployee::create($data);
        return response()->json($empleado, 201);
    }

    /**
     * Obtener detalle de un empleado.
     */
    public function show(OperablesEmployee $operablesEmployee): JsonResponse
    {
        return response()->json(
            $operablesEmployee->load(['commissionLiquidations', 'serviceOrderDetails.serviceOrder'])
        );
    }

    /**
     * Actualizar un empleado.
     */
    public function update(Request $request, OperablesEmployee $operablesEmployee): JsonResponse
    {
        $data = $this->validar($request, $operablesEmployee->id);
        $operablesEmployee->update($data);
        return response()->json($operablesEmployee);
    }

    /**
     * Eliminar (soft delete) un empleado.
     */
    public function destroy(OperablesEmployee $operablesEmployee): JsonResponse
    {
        $operablesEmployee->delete();
        return response()->json(['message' => 'Empleado eliminado.']);
    }

    /**
     * Obtener tipos de operarios disponibles.
     */
    public function tipos(): JsonResponse
    {
        return response()->json([
            'tipos' => [
                ['valor' => 'mecanico', 'etiqueta' => 'Mecánico'],
                ['valor' => 'lavador', 'etiqueta' => 'Lavador'],
                ['valor' => 'electricista', 'etiqueta' => 'Electricista'],
                ['valor' => 'esteticien', 'etiqueta' => 'Esteticien'],
                ['valor' => 'tecnico', 'etiqueta' => 'Técnico'],
                ['valor' => 'asesor', 'etiqueta' => 'Asesor'],
                ['valor' => 'otro', 'etiqueta' => 'Otro'],
            ],
        ]);
    }

    /**
     * Validar datos del empleado.
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', "unique:operables_employees,email{$unique}"],
            'telefono' => ['nullable', 'string', 'max:20'],
            'ci_cedula' => ['required', 'string', 'max:50', "unique:operables_employees,ci_cedula{$unique}"],
            'tipo_operario' => ['required', 'in:mecanico,lavador,electricista,esteticien,tecnico,asesor,otro'],
            'comision_default' => ['nullable', 'numeric', 'min:0'],
            'tipo_comision_default' => ['nullable', 'in:percentage,fixed'],
            'activo' => ['boolean'],
        ]);
    }
}
