<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints PÚBLICOS del portal de clientes (sin autenticación).
 * Es el destino del QR y del enlace público de reservas.
 *
 * El portal es POR USUARIO: la URL incluye el slug del negocio
 * (ej. /publico/{slug}/...), de modo que cada QR lleva al calendario correcto
 * y las reservas quedan en la cuenta de su propietario. Si no se indica slug,
 * se usa el negocio principal (compatibilidad con el enlace antiguo).
 */
class PortalController extends Controller
{
    public function __construct(private AgendaService $agenda, private Notificador $notificador) {}

    /** Resuelve el id del negocio dueño del portal a partir del slug público. */
    private function negocioId(?string $slug = null): int
    {
        if ($slug) {
            $id = User::where('reservas_slug', $slug)->value('id');
            abort_if(! $id, 404, 'Portal de reservas no encontrado.');
            return $id;
        }
        return User::negocioPrincipalId() ?? abort(404, 'Portal de reservas no disponible.');
    }

    /** Datos básicos del negocio (para el encabezado del portal). */
    public function negocio(?string $slug = null)
    {
        $id = $this->negocioId($slug);
        $u = User::find($id);
        return response()->json(['nombre' => $u?->name, 'slug' => $u?->reservas_slug]);
    }

    /** Servicios activos que el cliente puede reservar. */
    public function servicios(?string $slug = null)
    {
        return Servicio::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion', 'duracion_min', 'precio']);
    }

    /** Horarios disponibles en tiempo real para una fecha. */
    public function disponibilidad(Request $request, ?string $slug = null)
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
        ]);

        $duracion = 30;
        if (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
            $duracion = $s->duracion_min;
        }

        $slots = $this->agenda->slotsDisponibles(Carbon::parse($data['fecha']), $duracion, $this->negocioId($slug));
        return response()->json(['slots' => $slots]);
    }

    /** El cliente reserva una cita desde el portal/QR. */
    public function reservar(Request $request, ?string $slug = null)
    {
        $data = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'telefono' => ['required', 'string', 'max:50'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'inicio' => ['required', 'date'],
        ]);

        $duracion = 30;
        if (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
            $duracion = $s->duracion_min;
        }

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($duracion);
        $negocio = $this->negocioId($slug);

        // Garantía anti doble-reserva (misma lógica que el panel admin).
        $this->agenda->asegurarDisponible($inicio, $fin, null, $negocio);

        return DB::transaction(function () use ($data, $inicio, $fin, $negocio) {
            // Reutiliza el cliente del negocio por email, o lo crea como POTENCIAL.
            $cliente = Cliente::firstOrCreate(
                ['email' => $data['email'], 'owner_id' => $negocio],
                ['nombre_completo' => $data['nombre_completo'], 'telefono' => $data['telefono'], 'estado' => 'POTENCIAL']
            );

            $cita = Cita::create([
                'owner_id' => $negocio,
                'cliente_id' => $cliente->id,
                'servicio_id' => $data['servicio_id'] ?? null,
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => 'PENDIENTE',
                'origen' => 'PORTAL',
            ]);

            // Notificación interna SOLO para el dueño del negocio + correo al cliente.
            $this->notificador->aUsuario($negocio, 'RESERVA',
                'Nueva reserva desde el portal', "{$cliente->nombre_completo} · {$inicio->format('d/m/Y H:i')}");
            $this->notificador->correo($cliente->email, 'Confirmación de tu reserva - Logix',
                '¡Reserva confirmada!', [
                    "Hola {$cliente->nombre_completo},",
                    "Tu cita quedó agendada para el {$inicio->format('d/m/Y')} a las {$inicio->format('H:i')}.",
                    'Si necesitas cancelar, ingresa al portal con tu correo.',
                ], null, 'RESERVA');

            return response()->json([
                'mensaje' => 'Reserva confirmada.',
                'cita' => $cita->load('servicio:id,nombre'),
            ], 201);
        });
    }

    /** Consulta de citas del cliente por su correo (sin login, apto móvil). */
    public function misCitas(Request $request, ?string $slug = null)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $cliente = Cliente::where('owner_id', $this->negocioId($slug))
            ->where('email', $data['email'])->first();
        if (! $cliente) {
            return response()->json(['citas' => []]);
        }

        $citas = $cliente->citas()
            ->with('servicio:id,nombre')
            ->orderByDesc('inicio')
            ->get();

        return response()->json(['cliente' => $cliente->nombre_completo, 'citas' => $citas]);
    }

    /** El cliente cancela su propia cita (verificando el correo). */
    public function cancelar(Request $request, Cita $cita)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if (! $cita->cliente || strtolower($cita->cliente->email) !== strtolower($data['email'])) {
            return response()->json(['message' => 'No autorizado para cancelar esta cita.'], 403);
        }
        if (in_array($cita->estado, ['CANCELADA', 'COMPLETADA'])) {
            return response()->json(['message' => 'La cita no se puede cancelar.'], 422);
        }

        $cita->update(['estado' => 'CANCELADA']);
        return response()->json(['mensaje' => 'Cita cancelada.']);
    }
}
