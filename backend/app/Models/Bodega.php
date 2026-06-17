<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bodega extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'bodegas';

    protected $fillable = [
        'owner_id',
        'nombre',
        'direccion',
        'responsable_id',
        'activo',
        'es_principal',
    ];

    protected $casts = ['activo' => 'boolean', 'es_principal' => 'boolean'];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockBodega::class, 'bodega_id');
    }
}
