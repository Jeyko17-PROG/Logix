<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Actualiza los datos básicos del perfil del usuario autenticado.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tipo_documento' => ['sometimes', 'nullable', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
        ]);

        $user->update($data);

        return response()->json($user->fresh()->load('rol', 'plan'));
    }

    /**
     * Cambio de contraseña del usuario autenticado (requiere la contraseña actual).
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if (! Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    /**
     * Sube/actualiza la foto de perfil.
     * Acepta tanto un archivo del computador como una captura de la cámara (PWA).
     */
    public function uploadFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'], // 5 MB
        ]);

        $user = $request->user();

        // Guarda en storage/app/public/perfiles (accesible vía /storage gracias a storage:link).
        $path = $request->file('foto')->store('perfiles', 'public');
        $url = Storage::url($path);

        // Registra el archivo en la tabla central de archivos.
        $archivo = Archivo::create([
            'nombre_original' => $request->file('foto')->getClientOriginalName(),
            'ruta' => $path,
            'url' => $url,
            'tipo_mime' => $request->file('foto')->getClientMimeType(),
            'tamano_bytes' => $request->file('foto')->getSize(),
            'subido_por' => $user->id,
        ]);

        // Borra la foto anterior del disco si existía.
        if ($user->foto_perfil_url) {
            $anterior = str_replace('/storage/', '', $user->foto_perfil_url);
            Storage::disk('public')->delete($anterior);
        }

        $user->update(['foto_perfil_url' => $url]);

        return response()->json([
            'foto_perfil_url' => $url,
            'archivo_id' => $archivo->id,
            'user' => $user->fresh()->load('rol'),
        ]);
    }

    /**
     * Sube/actualiza el logo del negocio (empresa del usuario autenticado).
     * Se muestra en el portal público de reservas y en el QR de reserva.
     */
    public function subirLogoEmpresa(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->esPropietario()) {
            return response()->json(['message' => 'Solo el propietario del negocio puede cambiar el logo.'], 403);
        }

        $empresa = $user->empresaDeCobro();
        if (! $empresa) {
            return response()->json(['message' => 'Tu cuenta aún no tiene una empresa asociada.'], 422);
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'], // 5 MB
        ]);

        // Guarda en storage/app/public/logos (accesible vía /storage gracias a storage:link).
        $path = $request->file('logo')->store('logos', 'public');
        $url = Storage::url($path);

        $archivo = Archivo::create([
            'nombre_original' => $request->file('logo')->getClientOriginalName(),
            'ruta' => $path,
            'url' => $url,
            'tipo_mime' => $request->file('logo')->getClientMimeType(),
            'tamano_bytes' => $request->file('logo')->getSize(),
            'subido_por' => $user->id,
        ]);

        // Borra el logo anterior del disco si existía.
        if ($empresa->logo_url) {
            $anterior = str_replace('/storage/', '', $empresa->logo_url);
            Storage::disk('public')->delete($anterior);
        }

        $empresa->update(['logo_url' => $url]);

        return response()->json([
            'logo_url' => $url,
            'archivo_id' => $archivo->id,
        ]);
    }
}
