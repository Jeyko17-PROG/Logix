<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;

class BloqueoAgenda extends Model
{
    use PerteneceAUsuario;

    protected $table = 'bloqueos_agenda';

    protected $fillable = ['owner_id', 'inicio', 'fin', 'motivo'];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
    ];
}
