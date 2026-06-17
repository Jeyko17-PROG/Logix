import { useEffect, useState } from 'react'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
}

export default function Licencias() {
  const [licencias, setLicencias] = useState([])
  const [planes, setPlanes] = useState([])
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')

  async function cargar() {
    setCargando(true); setError('')
    try {
      const [l, p] = await Promise.all([api('/admin/licencias'), api('/planes')])
      setLicencias(l); setPlanes(p)
    } catch (err) {
      setError(err.message || 'No se pudieron cargar las licencias.')
    } finally { setCargando(false) }
  }

  useEffect(() => { cargar() }, [])

  async function accion(fn) {
    try { await fn(); await cargar() }
    catch (err) { alert(err.message || 'Error en la operación.') }
  }

  const cambiarPlan = (u, plan_id) => accion(() => api(`/admin/usuarios/${u.id}/plan`, { method: 'POST', body: { plan_id: Number(plan_id) } }))
  const cambiarEstado = (u, estado) => accion(() => api(`/admin/usuarios/${u.id}/estado`, { method: 'POST', body: { estado } }))

  async function cambiarLimite(u) {
    const v = prompt(`Límite manual de clientes para ${u.name} (vacío = usar el del plan):`, u.limite_manual ?? '')
    if (v === null) return
    accion(() => api(`/admin/usuarios/${u.id}/limite`, { method: 'POST', body: { limite_clientes: v === '' ? null : Number(v) } }))
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Administración de licencias</h1>
      <p className="text-slate-400 text-sm mb-6">Controla el plan, el límite de clientes y el estado de cada cuenta.</p>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Usuario</th>
                <th className="text-left p-3">Correo</th>
                <th className="text-left p-3">Plan</th>
                <th className="text-left p-3">Clientes / Límite</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {licencias.map((u) => (
                <tr key={u.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3 font-medium">{u.name} {u.es_super_admin && <span className="text-xs text-amber-400">★</span>}</td>
                  <td className="p-3 text-slate-400">{u.email}</td>
                  <td className="p-3">
                    <select value={u.plan?.id ?? ''} disabled={u.es_super_admin}
                      onChange={(e) => cambiarPlan(u, e.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs disabled:opacity-50">
                      <option value="" disabled>—</option>
                      {planes.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                    </select>
                  </td>
                  <td className="p-3">
                    {u.clientes_disponibles === null
                      ? <span className="text-slate-400">{u.clientes_usados} / ∞</span>
                      : <div>
                          <span className={u.clientes_usados >= (u.limite_clientes ?? 0) ? 'text-red-400' : 'text-slate-300'}>
                            {u.clientes_usados} / {u.limite_clientes}
                          </span>
                          <button onClick={() => cambiarLimite(u)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                        </div>}
                  </td>
                  <td className="p-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[u.estado] ?? ''}`}>{u.estado}</span></td>
                  <td className="p-3">
                    {!u.es_super_admin && (
                      <div className="flex flex-wrap gap-1 justify-end">
                        {u.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(u, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                        {u.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(u, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                        {u.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(u, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
              {licencias.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-slate-500">Sin licencias.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
