<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servicio extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'servicios';

    protected $fillable = ['owner_id', 'nombre', 'descripcion', 'duracion_min', 'precio', 'activo'];

    protected $casts = [
        'duracion_min' => 'integer',
        'precio' => 'decimal:2',
        'activo' => 'boolean',
    ];
}
