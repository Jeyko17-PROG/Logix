import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVADA: 'text-emerald-400',
  RESTRINGIDA: 'text-amber-400',
  DESACTIVADA: 'text-red-400',
}

export default function ControlFuncionalidades() {
  const [usuarios, setUsuarios] = useState([])
  const [sel, setSel] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState('')
  const [params] = useSearchParams()

  useEffect(() => {
    api('/admin/usuarios').then((u) => {
      const lista = u.filter((x) => !x.es_super_admin)
      setUsuarios(lista)
      // Preselecciona el usuario si viene ?u=id (desde "Configurar Funcionalidades").
      const pre = params.get('u')
      if (pre && lista.some((x) => String(x.id) === pre)) cargarMatriz(pre)
    }).catch(() => {})
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function cargarMatriz(id) {
    setSel(id); setData(null); setError('')
    if (!id) return
    try { setData(await api(`/admin/usuarios/${id}/funcionalidades`)) }
    catch (err) { setError(err.message || 'No se pudo cargar.') }
  }

  async function cambiar(clave, estado) {
    setGuardando(clave)
    try {
      const r = await api(`/admin/usuarios/${sel}/funcionalidades`, { method: 'PUT', body: { clave, estado } })
      setData(r)
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
    finally { setGuardando('') }
  }

  async function aplicarPlan() {
    if (!confirm('¿Restablecer todas las funcionalidades a los valores por defecto del plan del usuario?')) return
    try { setData(await api(`/admin/usuarios/${sel}/funcionalidades/aplicar-plan`, { method: 'POST', body: {} })) }
    catch (err) { alert(err.message || 'Error.') }
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Control de Funcionalidades</h1>
      <p className="text-slate-400 text-sm mb-6">Define qué módulos puede usar cada usuario. <span className="text-emerald-400">Activada</span> = uso normal · <span className="text-amber-400">Restringida</span> = solo ver · <span className="text-red-400">Desactivada</span> = oculta.</p>

      <div className="flex flex-wrap items-center gap-3 mb-6">
        <select value={sel} onChange={(e) => cargarMatriz(e.target.value)} className="input max-w-sm">
          <option value="">Selecciona un usuario…</option>
          {usuarios.map((u) => <option key={u.id} value={u.id}>{u.name} — {u.email} ({u.plan?.nombre ?? 'sin plan'})</option>)}
        </select>
        {data && <button onClick={aplicarPlan} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Restablecer a plan</button>}
      </div>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {data && (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Funcionalidad</th>
                <th className="text-left p-3">Por plan</th>
                <th className="text-left p-3">Estado actual</th>
                <th className="text-left p-3">Cambiar a</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((it) => (
                <tr key={it.clave} className="border-t border-slate-800">
                  <td className="p-3 font-medium">{it.label}</td>
                  <td className="p-3 text-slate-500 text-xs">{it.por_plan}</td>
                  <td className={`p-3 font-semibold ${ESTADO_COLOR[it.estado]}`}>{it.estado}{it.override && <span className="text-slate-500 text-xs font-normal"> (manual)</span>}</td>
                  <td className="p-3">
                    <select value={it.estado} disabled={guardando === it.clave}
                      onChange={(e) => cambiar(it.clave, e.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs">
                      {data.estados.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
