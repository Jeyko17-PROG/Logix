<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OwnerScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Hace que un modelo pertenezca a un usuario (multi-inquilino):
 *  - aplica el filtro global OwnerScope (cada quien ve lo suyo),
 *  - asigna owner_id automáticamente al crear.
 */
trait PerteneceAUsuario
{
    public static function bootPerteneceAUsuario(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(function ($model) {
            if (empty($model->owner_id) && Auth::check()) {
                $model->owner_id = Auth::id();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
