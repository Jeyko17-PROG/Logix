<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea las funciones operativas del POS cuando la membresía mensual venció.
 *
 * - Aplica al dueño del workspace (los empleados quedan bloqueados si su dueño no pagó).
 * - Solo bloquea en modo 'membresia' con fecha de vencimiento pasada; el modo 'prepago'
 *   se cobra por factura y no se bloquea aquí.
 * - Deja pasar las rutas de cuenta/pago para que el usuario pueda renovar
 *   (perfil, planes, créditos/recargas, notificaciones, logout).
 * - Responde 402 con codigo MEMBRESIA_VENCIDA: el frontend debe mostrar la pasarela de pago.
 */
class VerificarMembresia
{
    /** Prefijos de ruta (relativos a api/) que siguen disponibles con la membresía vencida. */
    private const RUTAS_PERMITIDAS = [
        'api/me',
        'api/logout',
        'api/perfil',
        'api/planes',
        'api/credit-packages',
        'api/credits',
        'api/mis-funcionalidades',
        'api/notificaciones',
        'api/admin', // panel del super-admin (gestiona las licencias)
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->esSuperAdmin()) {
            return $next($request);
        }

        foreach (self::RUTAS_PERMITIDAS as $prefijo) {
            if ($request->is($prefijo) || $request->is($prefijo . '/*')) {
                return $next($request);
            }
        }

        $owner = $user->billingOwner();
        if ($owner->esSuperAdmin() || ! $owner->membresiaVencida()) {
            return $next($request);
        }

        return response()->json([
            'codigo' => 'MEMBRESIA_VENCIDA',
            'message' => 'Tu membresía venció. Renueva tu plan para seguir usando el POS.',
            'vencio_el' => $owner->membresia_vence_at?->toDateString(),
            'plan' => $owner->plan?->nombre,
        ], 402);
    }
}
