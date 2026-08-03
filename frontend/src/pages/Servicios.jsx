import { useEffect, useState } from 'react'
import { api } from '../api/client'

const VACIO = { nombre: '', descripcion: '', categoria_id: '', icono: '', precio: '', duracion_min: 30, activo: true }

export default function Servicios() {
  const [servicios, setServicios] = useState([])
  const [categorias, setCategorias] = useState([])
  const [form, setForm] = useState(VACIO)
  const [imagenActual, setImagenActual] = useState(null) // URL ya guardada (al editar)
  const [imagen, setImagen] = useState(null) // archivo nuevo elegido, o null
  const [editando, setEditando] = useState(null) // id del servicio en edición, o null para "nuevo"
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [galeria, setGaleria] = useState([]) // fotos de referencia del servicio en edición (ej. cortes de una barbería)
  const [subiendoFoto, setSubiendoFoto] = useState(false)

  async function cargar() {
    try {
      const [s, c] = await Promise.all([api('/servicios'), api('/categorias')])
      setServicios(s); setCategorias(c)
    } catch { /* sesión expirada: client.js redirige al login */ }
  }
  useEffect(() => { cargar() }, [])

  function editar(servicio) {
    setEditando(servicio.id)
    setForm({ ...VACIO, ...servicio, categoria_id: servicio.categoria_id ?? '', precio: String(servicio.precio) })
    setImagenActual(servicio.imagen ?? null)
    setImagen(null)
    setGaleria(servicio.galeria ?? [])
  }

  function cancelar() {
    setEditando(null)
    setForm(VACIO)
    setImagenActual(null)
    setImagen(null)
    setGaleria([])
  }

  // Fotos de referencia (varias por servicio, ej. distintos cortes de una barbería
  // que el cliente puede elegir al agendar su cita). Se suben una por una y solo
  // cuando el servicio ya existe (necesitan su id).
  async function agregarFotoGaleria(file) {
    if (!file || !editando) return
    setSubiendoFoto(true); setError('')
    try {
      const fd = new FormData()
      fd.append('imagen', file)
      const item = await api(`/servicios/${editando}/galeria`, { method: 'POST', body: fd, isForm: true })
      setGaleria((g) => [...g, item])
    } catch (err) {
      setError(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendoFoto(false)
    }
  }

  async function quitarFotoGaleria(imagenId) {
    if (!editando) return
    try {
      await api(`/servicios/${editando}/galeria/${imagenId}`, { method: 'DELETE' })
      setGaleria((g) => g.filter((f) => f.id !== imagenId))
    } catch (err) { alert(err.message || 'No se pudo quitar la foto.') }
  }

  async function guardar(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      // multipart/form-data: la foto se sube directo a Cloudinary (no se
      // pega un enlace) — evita el error de URL demasiado larga y permite
      // elegirla desde la galería o la cámara del celular.
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => {
        if (k === 'activo') fd.append(k, v ? '1' : '0')
        else if (v !== null && v !== '') fd.append(k, v)
      })
      if (imagen) fd.append('imagen', imagen)

      if (editando) await api(`/servicios/${editando}/update`, { method: 'POST', body: fd, isForm: true })
      else await api('/servicios', { method: 'POST', body: fd, isForm: true })
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
      <h1 className="text-2xl font-bold mb-2">Servicios</h1>
      <p className="text-slate-400 text-sm mb-6">Define tus servicios agrupados por categoría (nombre, precio, duración). Los clientes los eligen al reservar desde el portal público y tu equipo los asigna en las órdenes.</p>

      <form onSubmit={guardar} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 mb-6 space-y-3">
        <h2 className="font-semibold">{editando ? 'Editar servicio' : 'Nuevo servicio'}</h2>
        {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}
        <div className="grid gap-3 sm:grid-cols-2">
          <input required placeholder="Nombre (ej. Corte Clásico, Manicure)" value={form.nombre} onChange={set('nombre')} className="input sm:col-span-2" />
          <select value={form.categoria_id} onChange={set('categoria_id')} className="input">
            <option value="">Sin categoría</option>
            {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          <input placeholder="Emoji (ej. 💅, opcional)" value={form.icono ?? ''} onChange={set('icono')} className="input" maxLength={10} />
          <div className="sm:col-span-2 flex items-center gap-3">
            {(imagen || imagenActual) && (
              <img src={imagen ? URL.createObjectURL(imagen) : imagenActual} alt=""
                className="h-14 w-14 rounded-lg object-cover border border-slate-700 shrink-0" />
            )}
            <label className="flex-1 text-sm text-slate-300">
              Foto del servicio (opcional)
              <input type="file" accept="image/*" onChange={(e) => setImagen(e.target.files?.[0] ?? null)} className="input mt-1" />
              <span className="block text-xs text-slate-500 mt-1">Elige desde la galería o toma una foto con la cámara de tu celular.</span>
            </label>
          </div>
          <input required type="number" min="0" step="0.01" placeholder="Precio" value={form.precio} onChange={set('precio')} className="input" />
          <input required type="number" min="5" placeholder="Duración (min)" value={form.duracion_min} onChange={set('duracion_min')} className="input" />
        </div>
        <textarea placeholder="Descripción (opcional)" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input" rows={2} />

        {editando && (
          <div>
            <p className="text-sm text-slate-300 mb-1">Galería de referencias (ej. cortes de este servicio)</p>
            <p className="text-xs text-slate-500 mb-2">El cliente podrá elegir una de estas fotos al agendar su cita.</p>
            <div className="flex flex-wrap gap-2">
              {galeria.map((f) => (
                <div key={f.id} className="relative group">
                  <img src={f.url} alt="" className="h-16 w-16 rounded-lg object-cover border border-slate-700" />
                  <button type="button" onClick={() => quitarFotoGaleria(f.id)}
                    className="absolute -top-1.5 -right-1.5 h-5 w-5 rounded-full bg-red-600 hover:bg-red-500 text-white text-xs leading-5">✕</button>
                </div>
              ))}
              <label className="h-16 w-16 rounded-lg border border-dashed border-slate-600 flex items-center justify-center text-slate-500 hover:text-slate-300 hover:border-slate-500 cursor-pointer text-xs text-center">
                {subiendoFoto ? '…' : '+ Foto'}
                <input type="file" accept="image/*" className="hidden" disabled={subiendoFoto}
                  onChange={(e) => { const f = e.target.files?.[0]; if (f) agregarFotoGaleria(f); e.target.value = '' }} />
              </label>
            </div>
          </div>
        )}
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
            <div className="flex items-center gap-3">
              {s.imagen && <img src={s.imagen} alt="" className="h-10 w-10 rounded-lg object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
              <div>
                <p className="font-medium">{s.icono ? `${s.icono} ` : ''}{s.nombre} {!s.activo && <span className="text-xs text-slate-500">(inactivo)</span>}</p>
                <p className="text-slate-400 text-sm">${Number(s.precio).toLocaleString()} · {s.duracion_min} min{s.categoria && ` · ${s.categoria.nombre}`}</p>
              </div>
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
