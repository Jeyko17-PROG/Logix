<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Producto;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductoController extends Controller
{
    public function __construct(private CloudinaryUploader $cloudinary) {}

    public function index(Request $request)
    {
        $buscar = $request->query('buscar');
        $filtro = function ($q) use ($buscar) {
            if ($buscar) {
                $q->where(function ($w) use ($buscar) {
                    $w->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('sku', 'like', "%{$buscar}%")
                        ->orWhere('codigo_barras', 'like', "%{$buscar}%");
                });
            }
        };

        $paginado = Producto::with(['categoria:id,nombre', 'stocks'])
            ->withSum('movimientosSalida as salidas_sum', 'cantidad')
            ->tap($filtro)
            ->orderBy('nombre')->paginate(20);

        // Valor total del inventario (todos los productos que coinciden con el filtro,
        // no solo la página actual): se suma en SQL para no cargar todo a PHP.
        $valorTotal = Producto::query()->tap($filtro)
            ->leftJoin('stock_por_bodega', 'stock_por_bodega.producto_id', '=', 'productos.id')
            ->selectRaw('COALESCE(SUM(stock_por_bodega.cantidad * productos.precio_costo), 0) as total')
            ->value('total');

        $respuesta = $paginado->toArray();
        $respuesta['valor_total_inventario'] = round((float) $valorTotal, 2);
        return response()->json($respuesta);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validar($request);
        $data['created_by'] = $user->id;
        $data['owner_id'] = $user->workspaceOwnerId();
        $data['empresa_id'] = $user->empresaId();

        // El producto necesita existir primero: la imagen se sube bajo un public_id
        // basado en su id, para que futuras resubidas reemplacen la misma imagen.
        $producto = Producto::create($data);

        if ($url = $this->guardarImagen($request, $producto)) {
            $producto->update(['imagen_url' => $url]);
        }

        return response()->json($producto->load(['categoria:id,nombre', 'stocks']), 201);
    }

    public function show(Producto $producto)
    {
        return $producto->load(['categoria:id,nombre', 'stocks.bodega:id,nombre', 'proveedores'])
            ->loadSum('movimientosSalida as salidas_sum', 'cantidad');
    }

    public function update(Request $request, Producto $producto)
    {
        $user = $request->user();
        $data = $this->validar($request, $producto->id);
        $data['owner_id'] = $producto->owner_id ?? $user->workspaceOwnerId();
        $data['empresa_id'] = $producto->empresa_id ?? $user->empresaId();

        // overwrite:true en Cloudinary ya reemplaza la imagen anterior en el mismo public_id,
        // no hace falta borrarla aparte.
        if ($nueva = $this->guardarImagen($request, $producto)) {
            $data['imagen_url'] = $nueva;
        }

        $producto->update($data);
        return $producto->load(['categoria:id,nombre', 'stocks']);
    }

    public function destroy(Producto $producto)
    {
        $producto->delete();
        return response()->json(['message' => 'Producto eliminado.']);
    }

    /**
     * Sube la imagen del producto a Cloudinary (si viene) y la registra en archivos.
     * Si Cloudinary falla, no bloquea el guardado de los datos del producto — solo
     * se queda sin imagen y se registra el error en el log para diagnosticarlo.
     */
    private function guardarImagen(Request $request, Producto $producto): ?string
    {
        if (! $request->hasFile('imagen')) {
            return null;
        }
        $request->validate([
            'imagen' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);
        $file = $request->file('imagen');

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), "logix/productos/producto_{$producto->id}");
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir imagen de producto', ['producto_id' => $producto->id, 'error' => $e->getMessage()]);
            return null;
        }

        $url = $resultado['secure_url'];

        Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $resultado['public_id'],
            'url' => $url,
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $request->user()->id,
        ]);

        return $url;
    }

    private function validar(Request $request, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';
        return $request->validate([
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            'sku' => ['required', 'string', 'max:100', "unique:productos,sku{$unique}"],
            'codigo_barras' => ['nullable', 'string', 'max:100', "unique:productos,codigo_barras{$unique}"],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'is_service' => ['boolean'],
            'has_commission' => ['boolean'],
            'commission_type' => ['nullable', 'in:percentage,fixed'],
            'commission_value' => ['nullable', 'numeric', 'min:0'],
            'precio_costo' => ['required', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'activo' => ['boolean'],
        ]);
    }
}
