import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { useFeatures } from '../context/FeaturesContext'

const COP = (n) => '$' + Number(n).toLocaleString('es-CO')

const PLANTILLA = { id: null, nombre: '', precio_mensual: 0, limite_clientes: 200, incluye: [], funcionalidades: [], activo: true, orden: 99 }

export default function Planes() {
  const { user } = useAuth()
  const { catalogo } = useFeatures()
  const esSuper = user?.es_super_admin
  const [planes, setPlanes] = useState([])
  const [editando, setEditando] = useState(null)
  const [cargando, setCargando] = useState(true)

  async function cargar() {
    setCargando(true)
    try { setPlanes(await api('/planes')) } finally { setCargando(false) }
  }
  useEffect(() => { cargar() }, [])

  async function guardar(p) {
    const body = {
      nombre: p.nombre, precio_mensual: Number(p.precio_mensual),
      limite_clientes: Number(p.limite_clientes), incluye: p.incluye,
      funcionalidades: p.funcionalidades, activo: p.activo, orden: Number(p.orden) || 0,
    }
    try {
      if (p.id) await api(`/admin/planes/${p.id}`, { method: 'PUT', body })
      else await api('/admin/planes', { method: 'POST', body })
      setEditando(null); cargar()
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <h1 className="text-2xl font-bold">Planes de suscripción</h1>
        {esSuper && <button onClick={() => setEditando({ ...PLANTILLA, orden: planes.length })}
          className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Crear plan</button>}
      </div>
      <p className="text-slate-400 text-sm mb-6">{esSuper ? 'Edita precios, límites y funcionalidades de cada plan, o crea nuevos.' : 'Planes disponibles en la plataforma.'}</p>

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {planes.map((p) => (
            <div key={p.id} className={`flex flex-col rounded-2xl border bg-slate-800/40 p-5 ${p.activo ? 'border-slate-800' : 'border-slate-800 opacity-60'}`}>
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold">{p.nombre}</h2>
                {user?.plan?.id === p.id
                  ? <span className="text-xs rounded-full bg-emerald-500/15 text-emerald-400 px-2 py-0.5">Tu plan</span>
                  : !p.activo && <span className="text-xs rounded-full bg-slate-600 px-2 py-0.5">Inactivo</span>}
              </div>
              <p className="text-3xl font-extrabold mt-2">{COP(p.precio_mensual)}<span className="text-sm font-normal text-slate-400">/mes</span></p>
              <p className="text-sm text-slate-400 mt-1">Hasta <span className="text-white font-semibold">{Number(p.limite_clientes).toLocaleString('es-CO')}</span> clientes</p>
              <ul className="mt-4 space-y-1 text-sm text-slate-300 flex-1">
                {(p.incluye ?? []).map((i, k) => <li key={k}>✓ {i}</li>)}
              </ul>
              {esSuper && (
                <button onClick={() => setEditando(p)} className="mt-5 w-full rounded-lg bg-slate-700 hover:bg-slate-600 py-2 text-sm font-semibold">Editar plan</button>
              )}
            </div>
          ))}
        </div>
      )}

      {editando && <ModalPlan plan={editando} catalogo={catalogo} onClose={() => setEditando(null)} onGuardar={guardar} />}
    </div>
  )
}

function ModalPlan({ plan, catalogo, onClose, onGuardar }) {
  const [form, setForm] = useState({ ...plan, incluyeTexto: (plan.incluye ?? []).join('\n'), funcionalidades: plan.funcionalidades ?? [] })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const esNuevo = !plan.id

  function toggleFunc(clave) {
    setForm((f) => ({
      ...f,
      funcionalidades: f.funcionalidades.includes(clave)
        ? f.funcionalidades.filter((c) => c !== clave)
        : [...f.funcionalidades, clave],
    }))
  }

  function submit(e) {
    e.preventDefault()
    onGuardar({
      ...form,
      incluye: form.incluyeTexto.split('\n').map((s) => s.trim()).filter(Boolean),
    })
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">{esNuevo ? 'Crear nuevo plan' : `Editar plan «${plan.nombre}»`}</h2>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm text-slate-300 col-span-2">Nombre
              <input value={form.nombre} onChange={set('nombre')} className="input mt-1" required placeholder="Ej: Premium" />
            </label>
            <label className="block text-sm text-slate-300">Precio mensual (COP)
              <input type="number" min="0" value={form.precio_mensual} onChange={set('precio_mensual')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Límite de clientes
              <input type="number" min="1" value={form.limite_clientes} onChange={set('limite_clientes')} className="input mt-1" required />
            </label>
          </div>

          {/* Funcionalidades activadas por el plan */}
          <div>
            <p className="text-sm text-slate-300 mb-2">Funcionalidades activadas</p>
            <div className="grid grid-cols-2 gap-1.5 rounded-lg border border-slate-800 bg-slate-800/40 p-3 max-h-56 overflow-y-auto">
              {Object.entries(catalogo).map(([clave, label]) => (
                <label key={clave} className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                  <input type="checkbox" checked={form.funcionalidades.includes(clave)} onChange={() => toggleFunc(clave)}
                    className="accent-emerald-500" />
                  {label}
                </label>
              ))}
            </div>
          </div>

          <label className="block text-sm text-slate-300">Texto comercial (una línea por característica)
            <textarea value={form.incluyeTexto} onChange={set('incluyeTexto')} rows="4" className="input mt-1" placeholder="Facturación electrónica&#10;Inventario completo…" />
          </label>

          <div className="flex items-center gap-4">
            <label className="flex items-center gap-2 text-sm text-slate-300">
              <input type="checkbox" checked={form.activo} onChange={(e) => setForm({ ...form, activo: e.target.checked })} className="accent-emerald-500" />
              Plan activo (visible para usuarios)
            </label>
            <label className="text-sm text-slate-300 flex items-center gap-2">Orden
              <input type="number" value={form.orden} onChange={set('orden')} className="input mt-0 w-20" />
            </label>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">{esNuevo ? 'Crear plan' : 'Guardar'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
