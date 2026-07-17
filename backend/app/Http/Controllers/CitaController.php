<?php

namespace App\Http\Controllers;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Servicio;
use App\Services\AgendaService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CitaController extends Controller
{
    public function __construct(private AgendaService $agenda) {}

    /**
     * Lista citas en un rango (para vistas día/semana/mes).
     */
    public function index(Request $request)
    {
        $q = Cita::with(['cliente:id,nombre_completo', 'servicio:id,nombre,duracion_min', 'empleado:id,name', 'planLavado:id,nombre,precio']);

        // Rol Lavador: solo ve las citas que tiene asignadas.
        $this->limitarALavador($request, $q);

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
        $data = $this->validar($request);

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($this->duracionPara($data['servicio_id'] ?? null, $data['plan_lavado_id'] ?? null));

        $this->agenda->asegurarDisponible($inicio, $fin);

        $cita = Cita::create([
            ...$data,
            'inicio' => $inicio,
            'fin' => $fin,
            'estado' => 'PENDIENTE',
            'origen' => 'ADMIN',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cita->load(['cliente:id,nombre_completo', 'servicio:id,nombre', 'planLavado:id,nombre']), 201);
    }

    public function show(Cita $cita)
    {
        $this->autorizarLavador($cita);

        return $cita->load(['cliente', 'servicio', 'planLavado', 'empleado:id,name']);
    }

    public function update(Request $request, Cita $cita)
    {
        $this->autorizarLavador($cita);

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
        $fin = $inicio->copy()->addMinutes($this->duracionPara($cita->servicio_id, $cita->plan_lavado_id));

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

    private function duracionPara(?int $servicioId, ?int $planLavadoId = null): int
    {
        if ($planLavadoId && $plan = \App\Models\PlanLavado::find($planLavadoId)) {
            return $plan->duracion_min;
        }
        if ($servicioId && $servicio = Servicio::find($servicioId)) {
            return $servicio->duracion_min;
        }
        return AjusteAgenda::actual()->duracion_cita_min;
    }

    /** Clave del tipo de negocio de la empresa del usuario autenticado. */
    private function tipoNegocio(Request $request): ?string
    {
        return $request->user()?->empresaDeCobro()?->tipoNegocio?->clave;
    }

    /**
     * Valida los datos de la cita. Placa y tipo de vehículo son obligatorios
     * solo para negocios que trabajan con vehículos (talleres, lavadero); en
     * los demás no se piden y el frontend los oculta.
     */
    private function validar(Request $request): array
    {
        $conVehiculo = in_array($this->tipoNegocio($request), ['taller_motos', 'taller_carros', 'taller_general', 'lavadero'], true);

        return $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'empleado_id' => ['nullable', 'exists:users,id'],
            'tipo_vehiculo' => [$conVehiculo ? 'required' : 'nullable', 'in:moto,carro'],
            'placa' => [$conVehiculo ? 'required' : 'nullable', 'string', 'max:20'],
            'inicio' => ['required', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);
    }

    /**
     * Si el usuario tiene rol Lavador, limita la consulta a las citas donde
     * es el empleado asignado.
     */
    private function limitarALavador(Request $request, Builder $query): void
    {
        $user = $request->user();
        if (! $user?->esLavador()) {
            return;
        }
        $query->where('empleado_id', $user->id);
    }

    /** Aborta con 403 si un Lavador intenta acceder a una cita que no tiene asignada. */
    private function autorizarLavador(Cita $cita): void
    {
        $user = request()->user();
        if (! $user?->esLavador()) {
            return;
        }
        if ($cita->empleado_id !== $user->id) {
            abort(403, 'Solo puedes ver las citas que tienes asignadas.');
        }
    }
}
