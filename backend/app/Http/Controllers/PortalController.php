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

    /**
     * Resuelve el id del usuario dueño del portal a partir del slug público.
     * Multiempresa: primero busca el slug en empresas (canónico) y cae al
     * slug antiguo de users para no romper QRs/enlaces existentes.
     */
    private function negocioId(?string $slug = null): int
    {
        if ($slug) {
            $id = \App\Models\Empresa::where('reservas_slug', $slug)->value('owner_user_id')
                ?? User::where('reservas_slug', $slug)->value('id');
            abort_if(! $id, 404, 'Portal de reservas no encontrado.');
            return (int) $id;
        }
        return User::negocioPrincipalId() ?? abort(404, 'Portal de reservas no disponible.');
    }

    /** Datos básicos del negocio (para el encabezado del portal). */
    public function negocio(?string $slug = null)
    {
        $id = $this->negocioId($slug);
        $u = User::find($id);
        $empresa = \App\Models\Empresa::with('tipoNegocio:id,clave,nombre')->where('owner_user_id', $id)->first();
        return response()->json([
            'nombre' => $empresa?->nombre ?? $u?->name,
            'slug' => $empresa?->reservas_slug ?? $u?->reservas_slug,
            'logo_url' => $empresa?->logo_url,
            'logo_emoji' => $empresa?->logo_emoji,
            'tipo_negocio' => $empresa?->tipoNegocio?->clave,
        ]);
    }

    /** Sucursales activas del negocio (multisucursal); el portal las ofrece como primer paso. */
    public function sucursales(?string $slug = null)
    {
        return \App\Models\Bodega::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)
            ->orderByDesc('es_principal')->orderBy('nombre')
            ->get(['id', 'nombre', 'direccion', 'telefono', 'ciudad']);
    }

    /**
     * Servicios activos que el cliente puede reservar, agrupados por categoría.
     * Si se indica bodega_id, solo trae los servicios de esa sucursal (un servicio
     * sin sucursales asignadas se considera disponible en todas).
     */
    public function servicios(Request $request, ?string $slug = null)
    {
        $bodegaId = $request->query('bodega_id');

        return Servicio::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)
            ->when($bodegaId, fn ($q) => $q->where(
                fn ($w) => $w->whereDoesntHave('bodegas')->orWhereHas('bodegas', fn ($b) => $b->where('bodegas.id', $bodegaId))
            ))
            ->with('categoria:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'categoria_id', 'nombre', 'descripcion', 'imagen', 'icono', 'duracion_min', 'precio']);
    }

    /** Planes de lavado activos que el cliente puede reservar (Lavadero). */
    public function planesLavado(?string $slug = null)
    {
        return \App\Models\PlanLavado::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion', 'duracion_min', 'precio', 'aplica_moto', 'aplica_carro', 'icono']);
    }

    /** Horarios disponibles en tiempo real para una fecha. */
    public function disponibilidad(Request $request, ?string $slug = null)
    {
        $negocio = $this->negocioId($slug);

        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'bodega_id' => ['nullable', \Illuminate\Validation\Rule::exists('bodegas', 'id')->where('owner_id', $negocio)],
        ]);

        $duracion = 30;
        if (! empty($data['plan_lavado_id']) && $p = \App\Models\PlanLavado::find($data['plan_lavado_id'])) {
            $duracion = $p->duracion_min;
        } elseif (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
            $duracion = $s->duracion_min;
        }

        $slots = $this->agenda->slotsDisponibles(Carbon::parse($data['fecha']), $duracion, $negocio, $data['bodega_id'] ?? null);
        return response()->json(['slots' => $slots]);
    }

    /** El cliente reserva una cita desde el portal/QR. */
    public function reservar(Request $request, ?string $slug = null)
    {
        $negocio = $this->negocioId($slug);
        $conVehiculo = $this->tipoNegocioDe($negocio) === 'lavadero';

        $data = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'telefono' => ['required', 'string', 'max:50'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'bodega_id' => ['nullable', \Illuminate\Validation\Rule::exists('bodegas', 'id')->where('owner_id', $negocio)],
            'tipo_vehiculo' => [$conVehiculo ? 'required' : 'nullable', 'in:moto,carro'],
            'placa' => [$conVehiculo ? 'required' : 'nullable', 'string', 'max:20'],
            'inicio' => ['required', 'date'],
        ]);

        $duracion = 30;
        if (! empty($data['plan_lavado_id']) && $p = \App\Models\PlanLavado::find($data['plan_lavado_id'])) {
            $duracion = $p->duracion_min;
        } elseif (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
            $duracion = $s->duracion_min;
        }

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($duracion);

        // Garantía anti doble-reserva (misma lógica que el panel admin; scoped por sucursal).
        $this->agenda->asegurarDisponible($inicio, $fin, null, $negocio, $data['bodega_id'] ?? null);

        // El portal es público (sin sesión), así que hay que fijar empresa_id a
        // mano: el dueño autenticado filtra su Agenda/Clientes por empresa_id,
        // y sin esto las reservas del QR quedarían invisibles para él.
        $empresaId = $this->empresaIdDe($negocio);

        return DB::transaction(function () use ($data, $inicio, $fin, $negocio, $empresaId) {
            // Reutiliza el cliente del negocio por email, o lo crea como POTENCIAL.
            $cliente = Cliente::firstOrCreate(
                ['email' => $data['email'], 'owner_id' => $negocio],
                ['nombre_completo' => $data['nombre_completo'], 'telefono' => $data['telefono'], 'estado' => 'POTENCIAL', 'empresa_id' => $empresaId]
            );
            if ($empresaId && ! $cliente->empresa_id) {
                $cliente->update(['empresa_id' => $empresaId]);
            }

            $cita = Cita::create([
                'owner_id' => $negocio,
                'empresa_id' => $empresaId,
                'cliente_id' => $cliente->id,
                'servicio_id' => $data['servicio_id'] ?? null,
                'plan_lavado_id' => $data['plan_lavado_id'] ?? null,
                'bodega_id' => $data['bodega_id'] ?? null,
                'tipo_vehiculo' => $data['tipo_vehiculo'] ?? null,
                'placa' => $data['placa'] ?? null,
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
                'cita' => $cita->load('servicio:id,nombre,icono', 'planLavado:id,nombre,icono', 'bodega:id,nombre'),
            ], 201);
        });
    }

    /** Clave del tipo de negocio del dueño resuelto por negocioId(). */
    private function tipoNegocioDe(int $negocioId): ?string
    {
        return \App\Models\Empresa::with('tipoNegocio:id,clave')
            ->where('owner_user_id', $negocioId)->first()?->tipoNegocio?->clave;
    }

    /**
     * Id de la empresa (tenant) del dueño resuelto por negocioId(). El portal
     * es público (sin usuario autenticado), así que PerteneceAUsuario NO
     * asigna empresa_id automáticamente al crear; hay que fijarlo a mano o el
     * dueño (que sí filtra por empresa_id) nunca vería estas citas/clientes.
     */
    private function empresaIdDe(int $negocioId): ?int
    {
        return \App\Models\Empresa::where('owner_user_id', $negocioId)->value('id');
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
            ->with('servicio:id,nombre,icono', 'planLavado:id,nombre,icono', 'bodega:id,nombre')
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
