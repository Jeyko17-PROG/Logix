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

    protected $appends = ['icono_vehiculo'];

    protected $fillable = [
        'owner_id',
        'cliente_id', 'servicio_id', 'empleado_id', 'bodega_id',
        'tipo_vehiculo', 'placa', 'plan_lavado_id',
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

    public function planLavado(): BelongsTo
    {
        return $this->belongsTo(PlanLavado::class, 'plan_lavado_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'empleado_id');
    }

    /** Sucursal (bodega) donde se agendó la cita. Nulo = negocio de una sola sede. */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /** Ícono del tipo de vehículo, para pintar en agenda/portal/QR (🏍️ moto, 🚗 carro). */
    public function getIconoVehiculoAttribute(): ?string
    {
        return match ($this->tipo_vehiculo) {
            'moto' => '🏍️',
            'carro' => '🚗',
            default => null,
        };
    }

    /** Estados que ocupan un horario (no liberan el slot). */
    public const ESTADOS_ACTIVOS = ['PENDIENTE', 'CONFIRMADA', 'REPROGRAMADA', 'COMPLETADA'];
}
