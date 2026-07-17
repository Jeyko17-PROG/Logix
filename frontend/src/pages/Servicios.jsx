import { useEffect, useState } from 'react'
import { api } from '../api/client'

const VACIO = { nombre: '', descripcion: '', precio: '', duracion_min: 30, activo: true }

export default function Servicios() {
  const [servicios, setServicios] = useState([])
  const [form, setForm] = useState(VACIO)
  const [editando, setEditando] = useState(null) // id del servicio en edición, o null para "nuevo"
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  async function cargar() {
    try { setServicios(await api('/servicios')) } catch { /* sesión expirada: client.js redirige al login */ }
  }
  useEffect(() => { cargar() }, [])

  function editar(servicio) {
    setEditando(servicio.id)
    setForm({ ...VACIO, ...servicio, precio: String(servicio.precio) })
  }

  function cancelar() {
    setEditando(null)
    setForm(VACIO)
  }

  async function guardar(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      if (editando) await api(`/servicios/${editando}`, { method: 'PUT', body: form })
      else await api('/servicios', { method: 'POST', body: form })
      cancelar()
      cargar()
    } catch (err) { setError(err.message) } finally { setGuardando(false) }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar este servicio?')) return
    await api(`/servicios/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Servicios / Cortes</h1>
      <p className="text-slate-400 text-sm mb-6">Define tus servicios (nombre, precio, duración estimada). Los clientes los eligen al reservar desde el portal público y tu equipo los asigna en las órdenes.</p>

      <form onSubmit={guardar} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 mb-6 space-y-3">
        <h2 className="font-semibold">{editando ? 'Editar servicio' : 'Nuevo servicio'}</h2>
        {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}
        <div className="grid gap-3 sm:grid-cols-2">
          <input required placeholder="Nombre (ej. Corte Clásico, Corte + Barba)" value={form.nombre} onChange={set('nombre')} className="input sm:col-span-2" />
          <input required type="number" min="0" step="0.01" placeholder="Precio" value={form.precio} onChange={set('precio')} className="input" />
          <input required type="number" min="5" placeholder="Duración (min)" value={form.duracion_min} onChange={set('duracion_min')} className="input" />
        </div>
        <textarea placeholder="Descripción (opcional)" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input" rows={2} />
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.activo} onChange={set('activo')} /> Activo</label>
        <div className="flex gap-2">
          <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
            {guardando ? 'Guardando…' : editando ? 'Guardar cambios' : '+ Crear servicio'}
          </button>
          {editando && <button type="button" onClick={cancelar} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>}
        </div>
      </form>

      <div className="space-y-2">
        {servicios.length === 0 && <p className="text-slate-500 text-sm">Aún no tienes servicios creados.</p>}
        {servicios.map((s) => (
          <div key={s.id} className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-800/50 p-3">
            <div>
              <p className="font-medium">💈 {s.nombre} {!s.activo && <span className="text-xs text-slate-500">(inactivo)</span>}</p>
              <p className="text-slate-400 text-sm">${Number(s.precio).toLocaleString()} · {s.duracion_min} min</p>
            </div>
            <div className="flex gap-3">
              <button onClick={() => editar(s)} className="text-sky-400 text-sm hover:underline">Editar</button>
              <button onClick={() => eliminar(s.id)} className="text-red-400 text-sm hover:underline">Eliminar</button>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
