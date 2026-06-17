<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\StockBodega;
use Illuminate\Http\Request;

class BodegaController extends Controller
{
    public function index()
    {
        return Bodega::with('responsable:id,name')
            ->orderByDesc('es_principal')->orderBy('nombre')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        // La primera bodega del usuario queda como principal automáticamente.
        if (Bodega::count() === 0) {
            $data['es_principal'] = true;
        }

        return response()->json(Bodega::create($data), 201);
    }

    public function update(Request $request, Bodega $bodega)
    {
        $bodega->update($this->validar($request));
        return $bodega;
    }

    public function destroy(Bodega $bodega)
    {
        // Solo se pueden eliminar bodegas vacías (sin existencias).
        $tieneStock = StockBodega::where('bodega_id', $bodega->id)->where('cantidad', '>', 0)->exists();
        if ($tieneStock) {
            return response()->json(['message' => 'No se puede eliminar una bodega con existencias. Traslada el inventario primero.'], 422);
        }

        $bodega->delete();
        return response()->json(['message' => 'Bodega eliminada.']);
    }

    /** Define esta bodega como la principal del usuario (las demás dejan de serlo). */
    public function definirPrincipal(Bodega $bodega)
    {
        Bodega::where('id', '!=', $bodega->id)->update(['es_principal' => false]);
        $bodega->update(['es_principal' => true]);

        return response()->json($bodega);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'responsable_id' => ['nullable', 'exists:users,id'],
            'activo' => ['boolean'],
        ]);
    }
}
