<?php

namespace App\Http\Controllers;

use App\Models\NegocioVinculado;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Mis negocios": una misma persona puede tener varias cuentas (una por cada
 * negocio que registró, cada una con su propio email). Aquí se vinculan esas
 * cuentas entre sí y se permite alternar entre ellas sin volver a escribir
 * la contraseña cada vez. No toca el aislamiento multi-inquilino: cada
 * negocio sigue siendo una cuenta (owner_id/empresa_id) totalmente aparte.
 */
class CuentaController extends Controller
{
    public function __construct(private AuthController $auth) {}

    /** Serializa una cuenta/negocio para el selector "Mis negocios". */
    private function serializar(User $u, User $actual): array
    {
        $empresa = $u->empresaDeCobro();

        return [
            'id' => $u->id,
            'nombre' => $u->name,
            'email' => $u->email,
            'negocio' => $empresa?->nombre ?? $u->name,
            'tipo_negocio' => $empresa?->tipoNegocio?->nombre,
            'logo_emoji' => $empresa?->logo_emoji,
            'estado' => $u->estado,
            'ultimo_acceso' => $u->ultimo_acceso?->toIso8601String(),
            'es_actual' => $u->id === $actual->id,
        ];
    }

    /** Lista la cuenta actual + todas las vinculadas ("Mis negocios"). */
    public function misNegocios(Request $request): JsonResponse
    {
        $actual = $request->user();
        $ids = $actual->negociosVinculadosIds();

        $cuentas = collect([$actual])
            ->merge(User::whereIn('id', $ids)->with('empresa.tipoNegocio')->get())
            ->map(fn (User $u) => $this->serializar($u, $actual))
            ->values();

        return response()->json($cuentas);
    }

    /**
     * Vincula la cuenta actual con otro negocio propio, confirmando la
     * contraseña de esa otra cuenta (prueba de que es el mismo dueño).
     */
    public function vincular(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $actual = $request->user();
        $otro = User::where('email', $data['email'])->first();

        if (! $otro || ! Hash::check($data['password'], $otro->password)) {
            throw ValidationException::withMessages(['email' => ['Las credenciales de esa cuenta no son correctas.']]);
        }
        if ($otro->id === $actual->id) {
            throw ValidationException::withMessages(['email' => ['Esa ya es tu cuenta actual.']]);
        }
        if (! $otro->esPropietario()) {
            throw ValidationException::withMessages(['email' => ['Solo puedes vincular cuentas dueñas de un negocio, no empleados.']]);
        }

        NegocioVinculado::firstOrCreate(['user_id' => $actual->id, 'vinculado_user_id' => $otro->id]);

        return response()->json(['message' => "Vinculado con {$otro->name}.", 'negocios' => $this->misNegocios($request)->getData()]);
    }

    /**
     * "Mis negocios" → "Crear otro negocio": el dueño ya logueado registra un
     * negocio adicional sin volver a llenar el formulario público completo
     * (reutiliza su nombre/documento/teléfono). Igual que cualquier registro,
     * la cuenta nace en PENDIENTE_ACTIVACION — solo el super-admin puede
     * activarla con el código de 6 dígitos (mismo control que hoy, sin
     * atajos); apenas se activa queda vinculada y visible en "Mis negocios".
     */
    public function nuevoNegocio(Request $request): JsonResponse
    {
        $actual = $request->user();

        $data = $request->validate([
            'nombre_empresa' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[\pL\d\s.\'\-&]+$/u'],
            'tipo_negocio_id' => ['required', 'exists:tipos_negocio,id'],
            'email' => ['required', 'email:rfc,dns', 'unique:users,email'],
            // Opcional: si no la escribe, se genera una temporal (igual que al crear un empleado).
            'password' => ['nullable', 'string', 'min:8'],
            'telefono' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
        ]);

        $passwordPlano = $data['password'] ?? Str::password(12);

        [$nuevo] = $this->auth->crearNegocio([
            'name' => $actual->name,
            'tipo_documento' => $actual->tipo_documento,
            'numero_documento' => $actual->numero_documento,
            'telefono' => $data['telefono'] ?? $actual->telefono,
            'email' => $data['email'],
            'password' => $passwordPlano,
            'nombre_empresa' => $data['nombre_empresa'],
            'tipo_negocio_id' => $data['tipo_negocio_id'],
        ]);

        // Vínculo directo: quien lo crea ya demostró ser el dueño (sesión activa).
        NegocioVinculado::firstOrCreate(['user_id' => $actual->id, 'vinculado_user_id' => $nuevo->id]);

        return response()->json([
            'message' => 'Negocio creado. Pendiente de activación: un asesor de Fénix te compartirá el código de 6 dígitos.',
            'password_temporal' => $data['password'] ? null : $passwordPlano,
            'negocios' => $this->misNegocios($request)->getData(),
        ], 201);
    }

    /** Deja de alternar hacia esa cuenta (no la elimina, solo quita el acceso directo). */
    public function desvincular(Request $request, User $negocio): JsonResponse
    {
        $actual = $request->user();
        NegocioVinculado::where(function ($q) use ($actual, $negocio) {
            $q->where('user_id', $actual->id)->where('vinculado_user_id', $negocio->id);
        })->orWhere(function ($q) use ($actual, $negocio) {
            $q->where('user_id', $negocio->id)->where('vinculado_user_id', $actual->id);
        })->delete();

        return response()->json(['message' => 'Negocio desvinculado.']);
    }

    /** Cambia la sesión activa a otro negocio vinculado (sin pedir contraseña de nuevo). */
    public function entrar(Request $request, User $negocio): JsonResponse
    {
        $actual = $request->user();

        if ($negocio->id !== $actual->id && ! $actual->negociosVinculadosIds()->contains($negocio->id)) {
            abort(403, 'Ese negocio no está vinculado a tu cuenta.');
        }
        if ($negocio->estado !== 'ACTIVO' || ! $negocio->activo) {
            throw ValidationException::withMessages(['negocio' => ['Esa cuenta no está activa en este momento.']]);
        }

        $negocio->forceFill(['ultimo_acceso' => now()])->increment('veces_login');
        $token = $negocio->createToken('logix')->plainTextToken;

        return response()->json([
            'user' => $negocio->load('rol', 'plan'),
            'token' => $token,
        ]);
    }
}
