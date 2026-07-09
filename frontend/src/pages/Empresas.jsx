import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
}

/**
 * Panel del super-admin: EMPRESAS registradas en la plataforma (tenants).
 * Cada empresa agrupa a su dueño y empleados; el plan, la membresía y los
 * módulos se administran aquí a nivel de empresa.
 */
export default function Empresas() {
  const navigate = useNavigate()
  const [empresas, setEmpresas] = useState([])
  const [planes, setPlanes] = useState([])
  const [buscar, setBuscar] = useState('')
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')

  async function cargar() {
    setCargando(true); setError('')
    try {
      const [e, p] = await Promise.all([
        api(`/admin/empresas?buscar=${encodeURIComponent(buscar)}`),
        api('/planes'),
      ])
      setEmpresas(e); setPlanes(p)
    } catch (err) {
      setError(err.message || 'No se pudieron cargar las empresas.')
    } finally { setCargando(false) }
  }

  useEffect(() => {
    const t = setTimeout(cargar, 300)
    return () => clearTimeout(t)
  }, [buscar]) // eslint-disable-line react-hooks/exhaustive-deps

  async function accion(fn) {
    try { await fn(); await cargar() }
    catch (err) { alert(err.message || 'Error en la operación.') }
  }

  const cambiarPlan = (e, plan_id) => accion(() => api(`/admin/empresas/${e.id}/plan`, { method: 'POST', body: { plan_id: Number(plan_id) } }))
  const cambiarEstado = (e, estado) => accion(() => api(`/admin/empresas/${e.id}/estado`, { method: 'POST', body: { estado } }))

  async function cambiarLimite(e) {
    const v = prompt(`Límite manual de clientes para ${e.nombre} (vacío = usar el del plan):`, e.limite_manual ?? '')
    if (v === null) return
    accion(() => api(`/admin/empresas/${e.id}/limite`, { method: 'POST', body: { limite_clientes: v === '' ? null : Number(v) } }))
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <h1 className="text-2xl font-bold">Empresas registradas</h1>
        <input value={buscar} onChange={(ev) => setBuscar(ev.target.value)} placeholder="Buscar empresa, correo, NIT…"
          className="input !mt-0 w-64" />
      </div>
      <p className="text-slate-400 text-sm mb-6">
        Cada empresa es una cuenta aislada del SaaS: agrupa a su dueño y empleados, y define plan, membresía y módulos.
      </p>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm min-w-[900px]">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Empresa</th>
                <th className="text-left p-3">Tipo de negocio</th>
                <th className="text-left p-3">Dueño</th>
                <th className="text-left p-3">Plan</th>
                <th className="text-left p-3">Membresía</th>
                <th className="text-left p-3">Clientes</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {empresas.map((e) => (
                <tr key={e.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3">
                    <p className="font-medium">{e.nombre}</p>
                    <p className="text-xs text-slate-500">{e.usuarios} usuario(s){e.numero_documento ? ` · ${e.tipo_documento} ${e.numero_documento}` : ''}</p>
                  </td>
                  <td className="p-3 text-slate-400">{e.tipo_negocio?.nombre ?? '—'}</td>
                  <td className="p-3">
                    <p className="text-slate-300">{e.dueno?.name ?? '—'}</p>
                    <p className="text-xs text-slate-500">{e.dueno?.email}</p>
                  </td>
                  <td className="p-3">
                    <select value={e.plan?.id ?? ''} onChange={(ev) => cambiarPlan(e, ev.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs">
                      <option value="" disabled>—</option>
                      {planes.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                    </select>
                    <p className="text-xs text-slate-500 mt-1">{e.modo_cobro === 'prepago' ? '💰 pago por uso' : '📅 membresía'}</p>
                  </td>
                  <td className="p-3">
                    {e.membresia_vence_at
                      ? <span className={e.membresia_vencida ? 'text-red-400 font-semibold' : 'text-slate-300'}>
                          {new Date(e.membresia_vence_at).toLocaleDateString('es-CO')}{e.membresia_vencida ? ' ⚠️ vencida' : ''}
                        </span>
                      : <span className="text-slate-500">sin control</span>}
                  </td>
                  <td className="p-3">
                    <span className={e.limite_clientes && e.clientes_usados >= e.limite_clientes ? 'text-red-400' : 'text-slate-300'}>
                      {e.clientes_usados} / {e.limite_clientes ?? '∞'}
                    </span>
                    <button onClick={() => cambiarLimite(e)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                  </td>
                  <td className="p-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[e.estado] ?? ''}`}>{e.estado}</span></td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-1 justify-end">
                      <button onClick={() => navigate(`/funcionalidades?e=${e.id}`)}
                        className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">🧩 Módulos</button>
                      {e.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(e, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                      {e.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(e, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                      {e.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(e, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                    </div>
                  </td>
                </tr>
              ))}
              {empresas.length === 0 && <tr><td colSpan="8" className="p-6 text-center text-slate-500">Sin empresas registradas.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
