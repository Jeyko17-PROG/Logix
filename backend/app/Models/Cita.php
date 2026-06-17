<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cita extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'citas';

    protected $fillable = [
        'owner_id',
        'cliente_id', 'servicio_id', 'empleado_id',
        'inicio', 'fin', 'estado', 'observaciones', 'origen', 'created_by',
    ];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'empleado_id');
    }

    /** Estados que ocupan un horario (no liberan el slot). */
    public const ESTADOS_ACTIVOS = ['PENDIENTE', 'CONFIRMADA', 'REPROGRAMADA', 'COMPLETADA'];
}
