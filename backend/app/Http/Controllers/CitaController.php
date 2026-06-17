<?php

namespace App\Http\Controllers;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Servicio;
use App\Services\AgendaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CitaController extends Controller
{
    public function __construct(private AgendaService $agenda) {}

    /**
     * Lista citas en un rango (para vistas día/semana/mes).
     */
    public function index(Request $request)
    {
        $q = Cita::with(['cliente:id,nombre_completo', 'servicio:id,nombre,duracion_min', 'empleado:id,name']);

        if ($desde = $request->query('desde')) {
            $q->where('inicio', '>=', Carbon::parse($desde)->startOfDay());
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('inicio', '<=', Carbon::parse($hasta)->endOfDay());
        }
        if ($cliente = $request->query('cliente_id')) {
            $q->where('cliente_id', $cliente);
        }

        return $q->orderBy('inicio')->get();
    }

    /**
     * Horarios disponibles para una fecha (disponibilidad en tiempo real).
     */
    public function disponibilidad(Request $request)
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
        ]);

        $duracion = $this->duracionPara($data['servicio_id'] ?? null);
        $slots = $this->agenda->slotsDisponibles(Carbon::parse($data['fecha']), $duracion);

        return response()->json(['duracion_min' => $duracion, 'slots' => $slots]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'empleado_id' => ['nullable', 'exists:users,id'],
            'inicio' => ['required', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($this->duracionPara($data['servicio_id'] ?? null));

        $this->agenda->asegurarDisponible($inicio, $fin);

        $cita = Cita::create([
            ...$data,
            'inicio' => $inicio,
            'fin' => $fin,
            'estado' => 'PENDIENTE',
            'origen' => 'ADMIN',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cita->load(['cliente:id,nombre_completo', 'servicio:id,nombre']), 201);
    }

    public function show(Cita $cita)
    {
        return $cita->load(['cliente', 'servicio', 'empleado:id,name']);
    }

    public function update(Request $request, Cita $cita)
    {
        $data = $request->validate([
            'observaciones' => ['nullable', 'string'],
            'empleado_id' => ['nullable', 'exists:users,id'],
        ]);
        $cita->update($data);
        return $cita;
    }

    /** Reprogramar: cambia inicio/fin validando disponibilidad. */
    public function reprogramar(Request $request, Cita $cita)
    {
        $data = $request->validate(['inicio' => ['required', 'date']]);

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($this->duracionPara($cita->servicio_id));

        $this->agenda->asegurarDisponible($inicio, $fin, $cita->id);

        $cita->update(['inicio' => $inicio, 'fin' => $fin, 'estado' => 'REPROGRAMADA']);
        return $cita;
    }

    public function confirmar(Cita $cita)
    {
        $cita->update(['estado' => 'CONFIRMADA']);
        return $cita;
    }

    public function cancelar(Cita $cita)
    {
        $cita->update(['estado' => 'CANCELADA']);
        return $cita;
    }

    private function duracionPara(?int $servicioId): int
    {
        if ($servicioId && $servicio = Servicio::find($servicioId)) {
            return $servicio->duracion_min;
        }
        return AjusteAgenda::actual()->duracion_cita_min;
    }
}
