<?php

namespace App\Http\Controllers;

use App\Models\Servicio;
use Illuminate\Http\Request;

class ServicioController extends Controller
{
    public function index()
    {
        return Servicio::with('categoria:id,nombre')->orderBy('nombre')->get();
    }

    public function store(Request $request)
    {
        return response()->json(Servicio::create($this->validar($request)), 201);
    }

    public function update(Request $request, Servicio $servicio)
    {
        $servicio->update($this->validar($request));
        return $servicio;
    }

    public function destroy(Servicio $servicio)
    {
        $servicio->delete();
        return response()->json(['message' => 'Servicio eliminado.']);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            'imagen' => ['nullable', 'string', 'max:2048'],
            'duracion_min' => ['required', 'integer', 'min:5'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['boolean'],
        ]);
    }
}
