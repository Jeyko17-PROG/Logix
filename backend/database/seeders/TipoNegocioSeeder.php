<?php

namespace Database\Seeders;

use App\Models\TipoNegocio;
use Illuminate\Database\Seeder;

/**
 * Catálogo de tipos de negocio con sus módulos por defecto.
 * modulos_default = null significa "todos los módulos disponibles".
 * El super-admin puede ajustar los módulos de cada tipo desde su panel.
 */
class TipoNegocioSeeder extends Seeder
{
    public function run(): void
    {
        // Módulos comunes a cualquier negocio con ventas.
        $comunes = [
            'dashboard', 'notificaciones', 'notas', 'calculadora',
            'clientes', 'facturacion', 'pdf', 'correos', 'exportacion', 'reportes', 'caja',
        ];

        $tipos = [
            ['clave' => 'taller_motos', 'nombre' => 'Taller de motos', 'orden' => 1, 'modulos_default' => null],
            ['clave' => 'taller_carros', 'nombre' => 'Taller de carros', 'orden' => 2, 'modulos_default' => null],
            ['clave' => 'taller_general', 'nombre' => 'Taller / servicio técnico general', 'orden' => 3, 'modulos_default' => null],
            ['clave' => 'lavadero', 'nombre' => 'Lavadero de vehículos', 'orden' => 4,
                'modulos_default' => array_merge($comunes, ['servicios', 'agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            ['clave' => 'tienda', 'nombre' => 'Tienda / comercio', 'orden' => 5,
                'modulos_default' => array_merge($comunes, ['productos', 'inventario', 'proveedores', 'documental', 'ocr', 'firma'])],
            ['clave' => 'restaurante', 'nombre' => 'Restaurante / comidas', 'orden' => 6,
                'modulos_default' => array_merge($comunes, ['productos', 'inventario', 'proveedores', 'agenda', 'reservas', 'qr'])],
            ['clave' => 'barberia', 'nombre' => 'Barbería / belleza', 'orden' => 7,
                'modulos_default' => array_merge($comunes, ['servicios', 'agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            ['clave' => 'otro', 'nombre' => 'Otro negocio', 'orden' => 99, 'modulos_default' => null],
        ];

        foreach ($tipos as $t) {
            TipoNegocio::updateOrCreate(
                ['clave' => $t['clave']],
                ['nombre' => $t['nombre'], 'orden' => $t['orden'], 'modulos_default' => $t['modulos_default'], 'activo' => true]
            );
        }
    }
}
