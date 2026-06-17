<?php

namespace App\Services;

use App\Mail\CorreoLogix;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Centraliza el envío de notificaciones internas y por correo.
 * El canal de correo usa el driver configurado (por defecto 'log' en desarrollo).
 * Estructura lista para añadir WhatsApp en el futuro.
 */
class Notificador
{
    /** Crea una notificación interna para usuarios con ciertos roles. */
    public function aRoles(array $roles, string $tipo, string $titulo, ?string $mensaje = null): void
    {
        $ids = User::whereHas('rol', fn ($q) => $q->whereIn('nombre', $roles))->pluck('id');
        foreach ($ids as $id) {
            Notificacion::create([
                'user_id' => $id, 'tipo' => $tipo, 'titulo' => $titulo,
                'mensaje' => $mensaje, 'canal' => 'INTERNA',
            ]);
        }
    }

    /** Notificación interna para un usuario específico. */
    public function aUsuario(int $userId, string $tipo, string $titulo, ?string $mensaje = null): void
    {
        Notificacion::create([
            'user_id' => $userId, 'tipo' => $tipo, 'titulo' => $titulo,
            'mensaje' => $mensaje, 'canal' => 'INTERNA',
        ]);
    }

    /**
     * Envía un correo con plantilla. NO crea una notificación interna:
     * un correo no es una notificación de panel y, si se guardara con user_id null,
     * se filtraría a TODOS los usuarios. Solo deja traza en el log si falla.
     */
    public function correo(string $para, string $asunto, string $titulo, array $lineas, ?string $adjuntoPath = null, string $tipo = 'ADMIN'): void
    {
        try {
            Mail::to($para)->send(new CorreoLogix($asunto, $titulo, $lineas, $adjuntoPath));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de Logix', ['para' => $para, 'error' => $e->getMessage()]);
        }
    }
}
