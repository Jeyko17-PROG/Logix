<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Filtro global de multi-inquilino.
 *
 * Para cualquier usuario autenticado que NO sea super-admin, limita las
 * consultas a sus propios registros (owner_id = id del usuario).
 * El super-admin (luisgarciab193@gmail.com) ve todo.
 * Sin usuario autenticado (CLI, portal público), no filtra.
 */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && ! $user->esSuperAdmin()) {
            $builder->where($model->getTable() . '.owner_id', $user->id);
        }
    }
}
