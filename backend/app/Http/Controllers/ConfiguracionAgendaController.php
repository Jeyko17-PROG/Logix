<?php

namespace App\Http\Controllers;

use App\Models\AjusteAgenda;
use App\Models\BloqueoAgenda;
use App\Models\HorarioLaboral;
use Illuminate\Http\Request;

class ConfiguracionAgendaController extends Controller
{
    /** Devuelve toda la configuración de la agenda. */
    public function index()
    {
        return response()->json([
            'ajustes' => AjusteAgenda::actual(),
            'horarios' => HorarioLaboral::orderBy('dia_semana')->get(),
            'bloqueos' => BloqueoAgenda::orderBy('inicio')->get(),
        ]);
    }

    /** Actualiza duración y buffer entre citas. */
    public function guardarAjustes(Request $request)
    {
        $data = $request->validate([
            'duracion_cita_min' => ['required', 'integer', 'min:5'],
            'buffer_min' => ['required', 'integer', 'min:0'],
        ]);
        $ajustes = AjusteAgenda::actual();
        $ajustes->update($data);
        return $ajustes;
    }

    /** Reemplaza el horario laboral semanal completo. */
    public function guardarHorarios(Request $request)
    {
        $data = $request->validate([
            'horarios' => ['present', 'array'],
            'horarios.*.dia_semana' => ['required', 'integer', 'between:0,6'],
            'horarios.*.hora_inicio' => ['required', 'date_format:H:i'],
            'horarios.*.hora_fin' => ['required', 'date_format:H:i', 'after:horarios.*.hora_inicio'],
        ]);

        HorarioLaboral::query()->delete();
        foreach ($data['horarios'] as $h) {
            HorarioLaboral::create([...$h, 'activo' => true]);
        }

        return HorarioLaboral::orderBy('dia_semana')->get();
    }

    public function crearBloqueo(Request $request)
    {
        $data = $request->validate([
            'inicio' => ['required', 'date'],
            'fin' => ['required', 'date', 'after:inicio'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        return response()->json(BloqueoAgenda::create($data), 201);
    }

    public function eliminarBloqueo(BloqueoAgenda $bloqueo)
    {
        $bloqueo->delete();
        return response()->json(['message' => 'Bloqueo eliminado.']);
    }
}
