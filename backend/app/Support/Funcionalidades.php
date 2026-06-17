<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserFuncionalidad;

/**
 * Catálogo de funcionalidades (módulos) de la plataforma y resolución
 * del estado efectivo por usuario (Control de Funcionalidades).
 *
 * Estados: ACTIVADA (uso normal), RESTRINGIDA (ve pero no usa), DESACTIVADA (no aparece).
 */
class Funcionalidades
{
    /** Catálogo: clave => etiqueta. */
    public const CATALOGO = [
        'clientes' => 'Clientes',
        'agenda' => 'Agenda y Citas',
        'reservas' => 'Portal de Reservas',
        'qr' => 'Código QR',
        'facturacion' => 'Facturación Electrónica',
        'firma' => 'Firma Digital',
        'proveedores' => 'Proveedores',
        'inventario' => 'Inventario y Bodegas',
        'productos' => 'Productos',
        'documental' => 'Gestión Documental',
        'ocr' => 'OCR de documentos',
        'dashboard' => 'Dashboard',
        'reportes' => 'Reportes',
        'notificaciones' => 'Notificaciones',
        'notas' => 'Bloc de Notas',
        'calculadora' => 'Calculadora',
        'exportacion' => 'Exportación de datos',
        'pdf' => 'Descarga de PDF',
        'correos' => 'Envío de correos',
    ];

    public const ESTADOS = ['ACTIVADA', 'RESTRINGIDA', 'DESACTIVADA'];

    /** Funcionalidades base disponibles en cualquier plan (incluido el Gratuito). */
    private const BASE = ['dashboard', 'notificaciones', 'notas', 'calculadora'];

    /**
     * Funcionalidades ACTIVADAS por defecto según el plan.
     * Primero lee las definidas por el super-admin en la BD; si el plan no las
     * tiene definidas, usa el mapeo por defecto (respaldo en código).
     */
    public static function permitidasPorPlan(?string $plan): array
    {
        static $cache = [];
        $clave = $plan ?? '__none__';
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $definidas = $plan ? \App\Models\Plan::where('nombre', $plan)->value('funcionalidades') : null;
        if (is_array($definidas)) {
            return $cache[$clave] = array_values(array_unique(array_merge(self::BASE, $definidas)));
        }

        return $cache[$clave] = self::respaldoPorPlan($plan);
    }

    /** Mapeo por defecto si la BD aún no define funcionalidades para el plan. */
    private static function respaldoPorPlan(?string $plan): array
    {
        $gratuito = array_merge(self::BASE, ['clientes', 'agenda', 'reservas', 'qr']);
        $normal = array_merge($gratuito, ['facturacion', 'pdf', 'correos', 'proveedores', 'productos', 'documental']);
        $medio = array_merge($normal, ['inventario', 'reportes', 'exportacion', 'firma']);
        $premium = array_keys(self::CATALOGO); // acceso total

        return match ($plan) {
            'Normal' => $normal,
            'Medio' => $medio,
            'Premium' => $premium,
            default => $gratuito, // Gratuito o sin plan
        };
    }

    /** Estado por defecto (según plan) de una funcionalidad. */
    public static function estadoPorPlan(?string $plan, string $clave): string
    {
        return in_array($clave, self::permitidasPorPlan($plan), true) ? 'ACTIVADA' : 'DESACTIVADA';
    }

    /**
     * Estado efectivo de una funcionalidad para un usuario:
     * super-admin = todo ACTIVADA; si hay override del admin se usa; si no, el del plan.
     */
    public static function estadoEfectivo(User $user, string $clave): string
    {
        if ($user->esSuperAdmin()) {
            return 'ACTIVADA';
        }
        $override = UserFuncionalidad::where('user_id', $user->id)->where('clave', $clave)->value('estado');
        if ($override) {
            return $override;
        }
        return self::estadoPorPlan($user->plan?->nombre, $clave);
    }

    /** Mapa completo clave => estado efectivo para un usuario. */
    public static function mapaEfectivo(User $user): array
    {
        $mapa = [];
        foreach (array_keys(self::CATALOGO) as $clave) {
            $mapa[$clave] = self::estadoEfectivo($user, $clave);
        }
        return $mapa;
    }
}
