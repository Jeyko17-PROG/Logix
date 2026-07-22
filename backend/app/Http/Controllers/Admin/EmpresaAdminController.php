<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarEmpresaRequest;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\Modulo;
use App\Models\TipoNegocio;
use App\Support\Funcionalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel del Super Administrador: gestión de EMPRESAS (tenants del SaaS).
 * Estado, plan, límite, tipo de negocio y módulos por empresa.
 * Todas las rutas van protegidas por el middleware 'superadmin'.
 */
class EmpresaAdminController extends Controller
{
    /** Serializa una empresa con su dueño, plan y consumo. */
    private function serializar(Empresa $e): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'tipo_documento' => $e->tipo_documento,
            'numero_documento' => $e->numero_documento,
            'telefono' => $e->telefono,
            'email' => $e->email,
            'email_facturacion' => $e->email_facturacion,
            'tipo_negocio' => $e->tipoNegocio?->only(['id', 'clave', 'nombre']),
            'dueno' => $e->owner?->only(['id', 'name', 'email']),
            'usuarios' => $e->usuarios()->count(),
            'plan' => $e->plan ? ['id' => $e->plan->id, 'nombre' => $e->plan->nombre] : null,
            'modo_cobro' => $e->modo_cobro,
            'membresia_vence_at' => $e->membresia_vence_at?->toIso8601String(),
            'membresia_vencida' => $e->membresiaVencida(),
            'estado' => $e->estado,
            'limite_clientes' => $e->limiteClientesEfectivo() ?: null,
            'limite_manual' => $e->limite_clientes,
            'clientes_usados' => $e->clientesUsados(),
            'limite_citas' => $e->limiteCitasEfectivo() ?: null,
            'limite_citas_manual' => $e->limite_citas,
            'citas_usadas' => $e->citasUsadas(),
            'fecha_registro' => $e->created_at?->toIso8601String(),
        ];
    }

    /** Listado de empresas registradas. */
    public function index(Request $request): JsonResponse
    {
        $q = Empresa::with('owner:id,name,email', 'plan:id,nombre', 'tipoNegocio:id,clave,nombre');

        if ($buscar = $request->query('buscar')) {
            $q->where(function ($sub) use ($buscar) {
                $sub->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('numero_documento', 'like', "%{$buscar}%");
            });
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }
        if ($tipo = $request->query('tipo_negocio_id')) {
            $q->where('tipo_negocio_id', $tipo);
        }

        return response()->json(
            $q->orderByDesc('id')->get()->map(fn ($e) => $this->serializar($e))->values()
        );
    }

    /** Actualiza los datos básicos de la empresa. */
    public function update(ActualizarEmpresaRequest $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validated();

        $empresa->update($data);
        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA', 'EDITAR', null, $empresa->nombre);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Cambia el estado de la empresa (bloquea/permite el acceso de todo su equipo). */
    public function cambiarEstado(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['estado' => ['required', 'in:ACTIVO,SUSPENDIDO,DESACTIVADO']]);

        $anterior = $empresa->estado;
        $empresa->update(['estado' => $data['estado'], 'activo' => $data['estado'] === 'ACTIVO']);

        // Sincroniza el estado del dueño y revoca sesiones si queda inactiva.
        $empresa->owner?->update(['estado' => $data['estado'], 'activo' => $data['estado'] === 'ACTIVO']);
        if ($data['estado'] !== 'ACTIVO') {
            foreach ($empresa->usuarios as $u) {
                $u->tokens()->delete();
            }
        }

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_ESTADO', null, $anterior, $data['estado']);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Cambia el plan de la empresa. */
    public function cambiarPlan(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);

        $anterior = $empresa->plan?->nombre;
        $empresa->update(['plan_id' => $data['plan_id']]);
        $empresa->owner?->update(['plan_id' => $data['plan_id']]); // espejo legado

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_PLAN', null, $anterior, $empresa->fresh('plan')->plan?->nombre);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Cambia el límite manual de clientes y/o citas (null = usar el del plan). */
    public function cambiarLimite(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate([
            'limite_clientes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limite_citas' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        if ($request->has('limite_clientes')) {
            $empresa->update(['limite_clientes' => $data['limite_clientes'] ?? null]);
            $empresa->owner?->update(['limite_clientes' => $data['limite_clientes'] ?? null]); // espejo legado
            Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_LIMITE', null, null, (string) ($data['limite_clientes'] ?? 'plan'));
        }
        if ($request->has('limite_citas')) {
            $empresa->update(['limite_citas' => $data['limite_citas'] ?? null]);
            $empresa->owner?->update(['limite_citas' => $data['limite_citas'] ?? null]); // espejo legado
            Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_LIMITE_CITAS', null, null, (string) ($data['limite_citas'] ?? 'plan'));
        }

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Matriz de módulos de la empresa (estado efectivo + override + origen). */
    public function modulos(Empresa $empresa): JsonResponse
    {
        $overrides = EmpresaModulo::where('empresa_id', $empresa->id)
            ->join('modulos', 'modulos.id', '=', 'empresa_modulos.modulo_id')
            ->pluck('empresa_modulos.estado', 'modulos.clave');

        $defaultsTipo = $empresa->tipoNegocio?->modulos_default;

        $items = [];
        foreach (Funcionalidades::CATALOGO as $clave => $label) {
            $items[] = [
                'clave' => $clave,
                'label' => $label,
                'estado' => Funcionalidades::estadoEfectivoEmpresa($empresa, $clave),
                'override' => $overrides[$clave] ?? null,
                'por_plan' => Funcionalidades::estadoPorPlan($empresa->plan?->nombre, $clave),
                'permitido_por_tipo' => ! is_array($defaultsTipo) || in_array($clave, $defaultsTipo, true),
            ];
        }

        return response()->json([
            'empresa' => [
                'id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'plan' => $empresa->plan?->nombre,
                'tipo_negocio' => $empresa->tipoNegocio?->nombre,
            ],
            'estados' => Funcionalidades::ESTADOS,
            'items' => $items,
        ]);
    }

    /** Guarda el override de un módulo para la empresa. */
    public function guardarModulos(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate([
            'clave' => ['required', 'string', 'in:' . implode(',', array_keys(Funcionalidades::CATALOGO))],
            'estado' => ['required', 'in:' . implode(',', Funcionalidades::ESTADOS)],
        ]);

        $modulo = Modulo::where('clave', $data['clave'])->firstOrFail();
        $anterior = Funcionalidades::estadoEfectivoEmpresa($empresa, $data['clave']);

        EmpresaModulo::updateOrCreate(
            ['empresa_id' => $empresa->id, 'modulo_id' => $modulo->id],
            ['estado' => $data['estado']],
        );

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_MODULO', $data['clave'], $anterior, $data['estado']);

        return $this->modulos($empresa->fresh(['plan', 'tipoNegocio']));
    }

    /** Elimina los overrides: la empresa vuelve a los módulos de su plan/tipo de negocio. */
    public function aplicarPlanModulos(Request $request, Empresa $empresa): JsonResponse
    {
        EmpresaModulo::where('empresa_id', $empresa->id)->delete();
        // Limpia también los overrides legados por usuario dueño.
        \App\Models\UserFuncionalidad::where('user_id', $empresa->owner_user_id)->delete();

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_MODULO', '*', 'override', 'plan:' . ($empresa->plan?->nombre ?? '-'));

        return $this->modulos($empresa->fresh(['plan', 'tipoNegocio']));
    }

    // ===== Catálogo de tipos de negocio =====

    /** CRUD del catálogo de tipos de negocio (solo super-admin). */
    public function tiposNegocio(): JsonResponse
    {
        return response()->json(
            TipoNegocio::orderBy('orden')->get()
        );
    }

    public function guardarTipoNegocio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'exists:tipos_negocio,id'],
            'clave' => ['required', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modulos_default' => ['nullable', 'array'],
            'modulos_default.*' => ['string', 'in:' . implode(',', array_keys(Funcionalidades::CATALOGO))],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        $tipo = TipoNegocio::updateOrCreate(
            ['clave' => $data['clave']],
            [
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'modulos_default' => $data['modulos_default'] ?? null,
                'activo' => $data['activo'] ?? true,
                'orden' => $data['orden'] ?? 0,
            ]
        );

        Auditoria::registrar($request->user()->id, null, 'TIPO_NEGOCIO', 'GUARDAR', null, $tipo->clave);

        return response()->json($tipo, $tipo->wasRecentlyCreated ? 201 : 200);
    }
}
