<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use App\Notifications\RestablecerPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'tipo_documento',
        'numero_documento',
        'reservas_slug',
        'email',
        'password',
        'rol_id',
        'plan_id',
        'limite_clientes',
        'foto_perfil_url',
        'telefono',
        'activo',
        'estado',
        'es_super_admin',
        'workspace_owner_id',
        'bodega_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Relaciones
     */
    public function credits()
    {
        return $this->hasMany(\App\Models\UserCredit::class, 'user_id');
    }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'ultimo_acceso' => 'datetime',
        'password' => 'hashed',
        'activo' => 'boolean',
        'es_super_admin' => 'boolean',
    ];

    /** El administrador principal que supervisa todas las cuentas. */
    public function esSuperAdmin(): bool
    {
        return (bool) $this->es_super_admin;
    }

    /**
     * Propietario de su propio espacio de trabajo: tiene acceso total a SUS datos.
     * (Roles "Administrador" y "Usuario" administran su workspace completo.)
     */
    public function esPropietario(): bool
    {
        return $this->esSuperAdmin() || $this->tieneRol('Administrador', 'Usuario');
    }

    /** Dueño operativo del workspace: el usuario mismo o el administrador que creó al empleado. */
    public function workspaceOwnerId(): int
    {
        return (int) ($this->workspace_owner_id ?: $this->id);
    }

    /** Un empleado queda limitado a la bodega/local asignado. */
    public function estaLimitadoABodega(): bool
    {
        return ! $this->esSuperAdmin()
            && ! $this->tieneRol('Administrador', 'Usuario')
            && ! empty($this->bodega_id);
    }

    /** Plan de suscripción del usuario. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function workspaceOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'workspace_owner_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /**
     * Límite efectivo de clientes: override manual del usuario, o el del plan.
     * El super-admin no tiene límite (PHP_INT_MAX).
     */
    public function limiteClientesEfectivo(): int
    {
        if ($this->esSuperAdmin()) {
            return PHP_INT_MAX;
        }
        if (! is_null($this->limite_clientes)) {
            return (int) $this->limite_clientes;
        }
        return (int) ($this->plan?->limite_clientes ?? 0);
    }

    /** Cantidad de clientes que ha registrado este usuario (su workspace). */
    public function clientesUsados(): int
    {
        return Cliente::withoutGlobalScopes()->where('owner_id', $this->id)->count();
    }

    /** Id del negocio principal (super-admin) para el portal público de reservas. */
    public static function negocioPrincipalId(): ?int
    {
        return static::where('es_super_admin', true)->value('id')
            ?? static::orderBy('id')->value('id');
    }

    /**
     * Genera (si falta) un slug público único para el portal de reservas del usuario.
     * Ej: "Barbería Luis" -> "barberia-luis" (con sufijo -id si ya existe).
     */
    public function generarReservasSlug(): string
    {
        if ($this->reservas_slug) {
            return $this->reservas_slug;
        }

        $base = \Illuminate\Support\Str::slug($this->name) ?: 'negocio';
        $slug = $base;
        if (static::where('reservas_slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base . '-' . $this->id;
        }

        $this->forceFill(['reservas_slug' => $slug])->save();
        return $slug;
    }

    /**
     * Rol al que pertenece el usuario (RBAC).
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    /**
     * Indica si el usuario tiene alguno de los roles dados (por nombre).
     */
    public function tieneRol(string ...$nombres): bool
    {
        return $this->rol !== null && in_array($this->rol->nombre, $nombres, true);
    }

    /**
     * Envía el correo de recuperación con un enlace al frontend (SPA).
     */
    public function sendPasswordResetNotification($token): void
    {
        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $url = $frontend . '/restablecer?token=' . $token . '&email=' . urlencode($this->email);

        $this->notify(new RestablecerPasswordNotification($url));
    }
}
