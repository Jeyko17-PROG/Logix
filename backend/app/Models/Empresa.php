<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa (tenant del SaaS): dueña de todos los datos de negocio.
 * Los campos de cobro (plan, membresía, billetera) viven aquí; el modelo
 * User conserva fachadas retrocompatibles que delegan en esta clase.
 */
class Empresa extends Model
{
    use SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'nombre',
        'tipo_documento',
        'numero_documento',
        'telefono',
        'email',
        'direccion',
        'logo_url',
        'tipo_negocio_id',
        'owner_user_id',
        'plan_id',
        'modo_cobro',
        'membresia_vence_at',
        'estado',
        'activo',
        'limite_clientes',
        'reservas_slug',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'membresia_vence_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function tipoNegocio(): BelongsTo
    {
        return $this->belongsTo(TipoNegocio::class, 'tipo_negocio_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'empresa_id');
    }

    /** Overrides de módulos definidos por el super-admin para esta empresa. */
    public function modulos(): HasMany
    {
        return $this->hasMany(EmpresaModulo::class, 'empresa_id');
    }

    /** ¿La membresía mensual está vencida? (solo aplica en modo membresía con fecha registrada) */
    public function membresiaVencida(): bool
    {
        return $this->modo_cobro === 'membresia'
            && $this->membresia_vence_at !== null
            && $this->membresia_vence_at->isPast();
    }

    /** Renueva la membresía N meses (desde hoy o desde el vencimiento futuro) y reactiva la empresa. */
    public function renovarMembresia(int $meses = 1): void
    {
        $base = ($this->membresia_vence_at && $this->membresia_vence_at->isFuture())
            ? $this->membresia_vence_at
            : now();

        $this->forceFill([
            'membresia_vence_at' => $base->copy()->addMonths($meses),
            'modo_cobro' => 'membresia',
            'estado' => 'ACTIVO',
            'activo' => true,
        ])->save();
    }

    /** Límite efectivo de clientes: override manual o el del plan. */
    public function limiteClientesEfectivo(): int
    {
        if (! is_null($this->limite_clientes)) {
            return (int) $this->limite_clientes;
        }
        return (int) ($this->plan?->limite_clientes ?? 0);
    }

    /** Clientes registrados por la empresa (sin el scope global, para el panel admin). */
    public function clientesUsados(): int
    {
        return Cliente::withoutGlobalScopes()->where('empresa_id', $this->id)->count();
    }

    /** Genera (si falta) el slug público único del portal de reservas. */
    public function generarReservasSlug(): string
    {
        if ($this->reservas_slug) {
            return $this->reservas_slug;
        }

        $base = \Illuminate\Support\Str::slug($this->nombre) ?: 'negocio';
        $slug = $base;
        if (static::where('reservas_slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base . '-' . $this->id;
        }

        $this->forceFill(['reservas_slug' => $slug])->save();
        return $slug;
    }
}
