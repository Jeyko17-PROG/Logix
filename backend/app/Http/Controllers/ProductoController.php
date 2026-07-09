<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        $q = Producto::with(['categoria:id,nombre', 'stocks']);
        if ($buscar = $request->query('buscar')) {
            $q->where(function ($w) use ($buscar) {
                $w->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('sku', 'like', "%{$buscar}%")
                    ->orWhere('codigo_barras', 'like', "%{$buscar}%");
            });
        }
        return $q->orderBy('nombre')->paginate(20);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validar($request);
        $data['created_by'] = $user->id;
        $data['owner_id'] = $user->workspaceOwnerId();
        $data['empresa_id'] = $user->empresaId();
        $data['imagen_url'] = $this->guardarImagen($request, $user->id);
        $producto = Producto::create($data);
        return response()->json($producto->load(['categoria:id,nombre', 'stocks']), 201);
    }

    public function show(Producto $producto)
    {
        return $producto->load(['categoria:id,nombre', 'stocks.bodega:id,nombre', 'proveedores']);
    }

    public function update(Request $request, Producto $producto)
    {
        $user = $request->user();
        $data = $this->validar($request, $producto->id);
        $data['owner_id'] = $producto->owner_id ?? $user->workspaceOwnerId();
        $data['empresa_id'] = $producto->empresa_id ?? $user->empresaId();
        if ($nueva = $this->guardarImagen($request, $user->id)) {
            if ($producto->imagen_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $producto->imagen_url));
            }
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
     * Guarda la imagen del producto (si viene) y la registra en archivos.
     */
    private function guardarImagen(Request $request, int $userId): ?string
    {
        if (! $request->hasFile('imagen')) {
            return null;
        }
        $request->validate([
            'imagen' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);
        $file = $request->file('imagen');
        $path = $file->store('productos', 'public');
        $url = Storage::url($path);

        Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $path,
            'url' => $url,
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $userId,
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
