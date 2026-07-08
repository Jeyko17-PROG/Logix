<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CajaSesion extends Model
{
    use PerteneceAUsuario;

    protected $table = 'caja_sesiones';

    protected $fillable = [
        'owner_id',
        'user_id',
        'bodega_id',
        'estado',
        'monto_apertura',
        'monto_esperado',
        'monto_cierre',
        'descuadre',
        'notas_apertura',
        'notas_cierre',
        'abierta_at',
        'cerrada_at',
    ];

    protected $casts = [
        'monto_apertura' => 'decimal:2',
        'monto_esperado' => 'decimal:2',
        'monto_cierre' => 'decimal:2',
        'descuadre' => 'decimal:2',
        'abierta_at' => 'datetime',
        'cerrada_at' => 'datetime',
    ];

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class, 'caja_sesion_id');
    }

    /** Total vendido (facturas emitidas) durante el turno, en la misma bodega si aplica. */
    public function totalVentas(): float
    {
        $q = Factura::where('created_at', '>=', $this->abierta_at)
            ->where('created_at', '<=', $this->cerrada_at ?? now());

        if ($this->bodega_id) {
            $q->where('bodega_id', $this->bodega_id);
        }

        return (float) $q->sum('total');
    }

    /** Total de gastos registrados en el turno. */
    public function totalGastos(): float
    {
        return (float) $this->gastos()->sum('monto');
    }
}
