<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;

class HorarioLaboral extends Model
{
    use PerteneceAUsuario;

    protected $table = 'horarios_laborales';

    protected $fillable = ['owner_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'activo'];

    protected $casts = [
        'dia_semana' => 'integer',
        'activo' => 'boolean',
    ];
}
