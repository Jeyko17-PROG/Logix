<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $q = Cliente::query();
        if ($buscar = $request->query('buscar')) {
            // Agrupado para no romper el filtro multi-inquilino (owner_id) con el OR.
            $q->where(function ($sub) use ($buscar) {
                $sub->where('nombre_completo', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('telefono', 'like', "%{$buscar}%")
                    ->orWhere('numero_documento', 'like', "%{$buscar}%");
            });
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }
        return $q->orderBy('nombre_completo')->paginate(20);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Restricción automática por plan: bloquea si se alcanzó el límite de clientes.
        if (! $user->esSuperAdmin()) {
            $limite = $user->limiteClientesEfectivo();
            $usados = $user->clientesUsados();
            if ($usados >= $limite) {
                return response()->json([
                    'message' => 'Ha alcanzado el límite permitido de su plan. Comuníquese con el administrador para ampliar su licencia.',
                    'limite_alcanzado' => true,
                    'usados' => $usados,
                    'limite' => $limite,
                ], 403);
            }
        }

        $data = $this->validar($request);
        $data['created_by'] = $user->id;
        return response()->json(Cliente::create($data), 201);
    }

    /**
     * Ficha completa del cliente con su historial.
     */
    public function show(Cliente $cliente)
    {
        $cliente->load('usuario:id,name,email');

        // Carga el historial sólo de los módulos que ya existen.
        $historial = [];
        if (class_exists(\App\Models\Cita::class)) {
            $historial['citas'] = $cliente->citas()->latest('inicio')->limit(50)->get();
        }
        if (class_exists(\App\Models\Factura::class)) {
            $historial['facturas'] = $cliente->facturas()->latest()->limit(50)->get();
        }
        if (class_exists(\App\Models\Nota::class)) {
            $historial['notas'] = $cliente->notas()->latest()->get();
        }

        return response()->json(array_merge($cliente->toArray(), $historial));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $cliente->update($this->validar($request));
        return $cliente;
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();
        return response()->json(['message' => 'Cliente eliminado.']);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre_completo' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['nullable', 'in:NIT,CC,CE'],
            'numero_documento' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:ACTIVO,POTENCIAL,INACTIVO'],
            'seguimiento_comercial' => ['nullable', 'string'],
        ]);
    }
}
