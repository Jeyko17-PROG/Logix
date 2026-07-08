<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Notificador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registro de un nuevo usuario (cuenta SaaS aislada).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['nullable', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Todo usuario nuevo es "Usuario": propietario de su propio espacio aislado.
        $rolId = Role::where('nombre', 'Usuario')->value('id')
            ?? Role::where('nombre', 'Administrador')->value('id');

        // Plan por defecto: Gratuito.
        $planId = Plan::where('nombre', 'Gratuito')->value('id');

        $user = User::create([
            'name' => $data['name'],
            'tipo_documento' => $data['tipo_documento'] ?? null,
            'numero_documento' => $data['numero_documento'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'],
            'password' => $data['password'], // hasheado por el cast
            'rol_id' => $rolId,
            'plan_id' => $planId,
            'activo' => true,
            'estado' => 'ACTIVO',
        ]);

        $this->prepararEspacioDeTrabajo($user);
        $this->notificarNuevoRegistro($user);
        $this->darBienvenida($user);

        $token = $user->createToken('logix')->plainTextToken;

        return response()->json([
            'user' => $user->load('rol', 'plan'),
            'token' => $token,
        ], 201);
    }

    /** Provisiona la configuración inicial del nuevo inquilino (horarios y ajustes de agenda). */
    private function prepararEspacioDeTrabajo(User $user): void
    {
        // Horario laboral por defecto: Lunes(1) a Sábado(6), 08:00–18:00.
        foreach (range(1, 6) as $dia) {
            \App\Models\HorarioLaboral::create([
                'owner_id' => $user->id,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00:00',
                'hora_fin' => '18:00:00',
                'activo' => true,
            ]);
        }

        // Ajustes por defecto de la agenda (duración de cita y buffer).
        \App\Models\AjusteAgenda::create([
            'owner_id' => $user->id,
            'duracion_cita_min' => 30,
            'buffer_min' => 0,
        ]);

        // Bodegas por defecto del inquilino (Principal queda como principal).
        foreach (['Principal' => true, 'Centro' => false, 'Norte' => false] as $nombre => $principal) {
            \App\Models\Bodega::create([
                'owner_id' => $user->id,
                'nombre' => $nombre,
                'activo' => true,
                'es_principal' => $principal,
            ]);
        }

        // Slug público único para su portal de reservas (QR personalizado).
        $user->generarReservasSlug();
    }

    /** Notificación de bienvenida para el propio usuario (solo él la ve). */
    private function darBienvenida(User $user): void
    {
        $mensaje = "Bienvenido a tu sistema de inventario, agenda y control Logix. "
            . "Tu cuenta ha sido creada correctamente y ya puedes gestionar clientes, inventario, "
            . "productos, facturación, agenda de citas y reservas mediante QR. ¡Gracias por confiar en Logix!";

        app(Notificador::class)->aUsuario($user->id, 'BIENVENIDA', "Bienvenido(a) {$user->name}", $mensaje);
    }

    /**
     * Avisa al Super Administrador (notificación interna + correo opcional) de un nuevo registro.
     */
    private function notificarNuevoRegistro(User $user): void
    {
        $superAdmin = User::where('es_super_admin', true)->first();
        if (! $superAdmin) {
            return;
        }

        $cuando = now()->format('d/m/Y H:i');
        $plan = $user->plan?->nombre ?? 'Sin plan';
        $mensaje = "Usuario: {$user->name}\nCorreo: {$user->email}\nFecha: {$cuando}\nPlan: {$plan}";

        $notificador = app(Notificador::class);
        $notificador->aUsuario($superAdmin->id, 'ADMIN', 'Nuevo usuario registrado', $mensaje);

        // Correo opcional al super-admin (en dev queda en el log si MAIL_MAILER=log).
        try {
            $notificador->correo(
                $superAdmin->email,
                'Nuevo usuario registrado — Logix',
                'Nuevo usuario registrado',
                ["Nombre: {$user->name}", "Correo: {$user->email}", "Fecha: {$cuando}", "Plan: {$plan}"],
            );
        } catch (\Throwable $e) {
            // No bloquear el registro si falla el envío de correo.
        }
    }

    /**
     * Inicio de sesión: devuelve un token de acceso (Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->estado === 'SUSPENDIDO') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está suspendida. Contacta al administrador.'],
            ]);
        }

        if ($user->estado === 'DESACTIVADO' || ! $user->activo) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta está desactivada.'],
            ]);
        }

        $user->forceFill(['ultimo_acceso' => now()])->save();

        $token = $user->createToken('logix')->plainTextToken;

        return response()->json([
            'user' => $user->load('rol', 'plan'),
            'token' => $token,
        ]);
    }

    /**
     * Datos del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('rol.permisos', 'plan', 'bodega', 'workspaceOwner');

        // Estado de cobro SaaS del workspace (el del dueño si es un empleado):
        // el frontend usa esto para mostrar la pasarela de pago cuando la membresía vence.
        $owner = $user->billingOwner();
        $user->setAttribute('facturacion_saas', [
            'modo_cobro' => $owner->modo_cobro,
            'membresia_vence_at' => $owner->membresia_vence_at?->toIso8601String(),
            'membresia_vencida' => $owner->membresiaVencida(),
            'creditos_facturacion' => (int) ($owner->credits()->where('module', 'facturacion')->value('credits') ?? 0),
        ]);

        return response()->json($user);
    }

    /**
     * Cierre de sesión: revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Solicita un enlace de recuperación de contraseña (se envía por correo).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // Respuesta neutra: no revela si el correo existe o no.
        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.',
            'status' => $status,
        ]);
    }

    /**
     * Restablece la contraseña con el token recibido por correo.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                // Revoca tokens de sesión activos por seguridad.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => ['El enlace de recuperación no es válido o ya expiró.'],
            ]);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
