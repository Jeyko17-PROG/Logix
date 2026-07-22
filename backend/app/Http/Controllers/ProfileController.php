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
     * Disco para archivos públicos (fotos de perfil, logos): usa S3 (u otro
     * proveedor S3-compatible: R2, Backblaze B2, Spaces...) cuando hay
     * credenciales configuradas, porque persiste entre despliegues. Si no,
     * cae al disco local 'public' (válido en desarrollo; en Render ese disco
     * es efímero y el archivo se pierde en el siguiente reinicio/deploy).
     */
    private function discoPublico(): string
    {
        return filled(config('filesystems.disks.s3.key')) ? 's3' : 'public';
    }

    /**
     * Elimina el archivo anterior del disco correspondiente a su URL, sin
     * lanzar error si ya no existe o si viene de un disco distinto al actual
     * (p. ej. quedó en el disco local antes de migrar a S3).
     */
    private function borrarAnterior(?string $urlAnterior): void
    {
        if (! $urlAnterior) {
            return;
        }
        foreach (['s3', 'public'] as $disco) {
            $base = rtrim((string) config("filesystems.disks.$disco.url"), '/');
            if ($base && str_starts_with($urlAnterior, $base . '/')) {
                Storage::disk($disco)->delete(substr($urlAnterior, strlen($base) + 1));
                return;
            }
        }
    }

    /**
     * Sube/actualiza la foto de perfil.
     * Acepta tanto un archivo del computador como una captura de la cámara (PWA).
     */
    public function uploadFoto(Request $request): JsonResponse
    {
        $request->validate([
            // El frontend comprime la imagen a ~417 KB antes de subirla (misma resolución, menos peso);
            // este límite es solo una red de seguridad por si llega sin comprimir (navegador viejo, API, etc).
            'foto' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:1024'], // 1 MB
        ]);

        $user = $request->user();
        $disco = $this->discoPublico();

        $path = $request->file('foto')->store('perfiles', ['disk' => $disco, 'visibility' => 'public']);
        $url = Storage::disk($disco)->url($path);

        // Registra el archivo en la tabla central de archivos.
        $archivo = Archivo::create([
            'nombre_original' => $request->file('foto')->getClientOriginalName(),
            'ruta' => $path,
            'url' => $url,
            'tipo_mime' => $request->file('foto')->getClientMimeType(),
            'tamano_bytes' => $request->file('foto')->getSize(),
            'subido_por' => $user->id,
        ]);

        // Borra la foto anterior si existía.
        $this->borrarAnterior($user->foto_perfil_url);

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
            'logo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'], // 10 MB
        ]);

        $disco = $this->discoPublico();
        $path = $request->file('logo')->store('logos', ['disk' => $disco, 'visibility' => 'public']);
        $url = Storage::disk($disco)->url($path);

        $archivo = Archivo::create([
            'nombre_original' => $request->file('logo')->getClientOriginalName(),
            'ruta' => $path,
            'url' => $url,
            'tipo_mime' => $request->file('logo')->getClientMimeType(),
            'tamano_bytes' => $request->file('logo')->getSize(),
            'subido_por' => $user->id,
        ]);

        // Borra el logo anterior si existía.
        $this->borrarAnterior($empresa->logo_url);

        $empresa->update(['logo_url' => $url]);

        return response()->json([
            'logo_url' => $url,
            'archivo_id' => $archivo->id,
        ]);
    }

    /**
     * Alternativa al logo real: el dueño elige un emoji como "marca" del negocio
     * (ej. 💅 para un spa de uñas). Se usa en el portal público y el QR mientras
     * no haya un logo_url cargado. Enviar logo_emoji vacío lo quita.
     */
    public function actualizarLogoEmoji(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->esPropietario()) {
            return response()->json(['message' => 'Solo el propietario del negocio puede cambiar el logo.'], 403);
        }

        $empresa = $user->empresaDeCobro();
        if (! $empresa) {
            return response()->json(['message' => 'Tu cuenta aún no tiene una empresa asociada.'], 422);
        }

        $data = $request->validate([
            'logo_emoji' => ['nullable', 'string', 'max:20'],
        ]);

        $empresa->update(['logo_emoji' => $data['logo_emoji'] ?? null]);

        return response()->json(['logo_emoji' => $empresa->logo_emoji]);
    }
}
