<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'owner_id',
        'categoria_id',
        'sku',
        'codigo_barras',
        'nombre',
        'descripcion',
        'precio_costo',
        'precio_venta',
        'imagen_url',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'precio_costo' => 'decimal:2',
        'precio_venta' => 'decimal:2',
        'activo' => 'boolean',
    ];

    protected $appends = ['stock_total'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(Proveedor::class, 'producto_proveedor', 'producto_id', 'proveedor_id')
            ->withPivot('precio_compra_acordado')
            ->withTimestamps();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockBodega::class, 'producto_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    /**
     * Stock total sumando todas las bodegas.
     */
    public function getStockTotalAttribute(): float
    {
        // Usa la relación ya cargada si existe para evitar consultas extra.
        if ($this->relationLoaded('stocks')) {
            return (float) $this->stocks->sum('cantidad');
        }
        return (float) $this->stocks()->sum('cantidad');
    }
}
