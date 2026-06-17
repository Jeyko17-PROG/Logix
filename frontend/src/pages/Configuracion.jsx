import { useEffect, useState } from 'react'
import { api } from '../api/client'

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']

export default function Configuracion() {
  const [servicios, setServicios] = useState([])
  const [horarios, setHorarios] = useState([])
  const [ajustes, setAjustes] = useState({ duracion_cita_min: 30, buffer_min: 0 })
  const [bloqueos, setBloqueos] = useState([])
  const [nuevoServ, setNuevoServ] = useState({ nombre: '', duracion_min: 30, precio: '' })
  const [bloqueo, setBloqueo] = useState({ inicio: '', fin: '', motivo: '' })
  const [msg, setMsg] = useState('')

  async function cargar() {
    setServicios(await api('/servicios'))
    const cfg = await api('/agenda/configuracion')
    setAjustes({ duracion_cita_min: cfg.ajustes.duracion_cita_min, buffer_min: cfg.ajustes.buffer_min })
    setBloqueos(cfg.bloqueos)
    // Mapa de horarios por día (un rango por día para la UI simple)
    const porDia = {}
    cfg.horarios.forEach((h) => { porDia[h.dia_semana] = h })
    setHorarios(DIAS.map((_, d) => ({
      dia_semana: d,
      activo: !!porDia[d],
      hora_inicio: porDia[d]?.hora_inicio?.slice(0, 5) ?? '08:00',
      hora_fin: porDia[d]?.hora_fin?.slice(0, 5) ?? '18:00',
    })))
  }
  useEffect(() => { cargar() }, [])

  function flash(t) { setMsg(t); setTimeout(() => setMsg(''), 2500) }

  async function guardarAjustes() {
    await api('/agenda/ajustes', { method: 'PUT', body: ajustes })
    flash('Ajustes guardados.')
  }
  async function guardarHorarios() {
    const payload = horarios.filter((h) => h.activo).map((h) => ({ dia_semana: h.dia_semana, hora_inicio: h.hora_inicio, hora_fin: h.hora_fin }))
    await api('/agenda/horarios', { method: 'PUT', body: { horarios: payload } })
    flash('Horario laboral guardado.')
  }
  async function crearServicio(e) {
    e.preventDefault()
    await api('/servicios', { method: 'POST', body: { ...nuevoServ, precio: Number(nuevoServ.precio) || 0 } })
    setNuevoServ({ nombre: '', duracion_min: 30, precio: '' }); cargar()
  }
  async function eliminarServicio(id) {
    await api(`/servicios/${id}`, { method: 'DELETE' }); cargar()
  }
  async function crearBloqueo(e) {
    e.preventDefault()
    await api('/agenda/bloqueos', { method: 'POST', body: bloqueo })
    setBloqueo({ inicio: '', fin: '', motivo: '' }); cargar()
  }
  async function eliminarBloqueo(id) {
    await api(`/agenda/bloqueos/${id}`, { method: 'DELETE' }); cargar()
  }

  const setHorario = (i, k, v) => setHorarios(horarios.map((h, j) => j === i ? { ...h, [k]: v } : h))

  return (
    <div className="space-y-10">
      <h1 className="text-2xl font-bold">Configuración</h1>
      {msg && <div className="rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{msg}</div>}

      {/* Ajustes de citas */}
      <section>
        <h2 className="font-semibold mb-3">Duración y tiempos</h2>
        <div className="flex flex-wrap gap-3 items-end">
          <label className="text-sm">Duración cita (min)
            <input type="number" value={ajustes.duracion_cita_min} onChange={(e) => setAjustes({ ...ajustes, duracion_cita_min: Number(e.target.value) })} className="input mt-1" />
          </label>
          <label className="text-sm">Buffer entre citas (min)
            <input type="number" value={ajustes.buffer_min} onChange={(e) => setAjustes({ ...ajustes, buffer_min: Number(e.target.value) })} className="input mt-1" />
          </label>
          <button onClick={guardarAjustes} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
        </div>
      </section>

      {/* Horario laboral */}
      <section>
        <h2 className="font-semibold mb-3">Horario laboral (días y horas)</h2>
        <div className="space-y-2">
          {horarios.map((h, i) => (
            <div key={i} className="flex items-center gap-3">
              <label className="flex items-center gap-2 w-28">
                <input type="checkbox" checked={h.activo} onChange={(e) => setHorario(i, 'activo', e.target.checked)} />
                {DIAS[h.dia_semana]}
              </label>
              <input type="time" value={h.hora_inicio} disabled={!h.activo} onChange={(e) => setHorario(i, 'hora_inicio', e.target.value)} className="input !w-auto disabled:opacity-40" />
              <span className="text-slate-500">a</span>
              <input type="time" value={h.hora_fin} disabled={!h.activo} onChange={(e) => setHorario(i, 'hora_fin', e.target.value)} className="input !w-auto disabled:opacity-40" />
            </div>
          ))}
        </div>
        <button onClick={guardarHorarios} className="mt-3 rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar horario</button>
      </section>

      {/* Servicios */}
      <section>
        <h2 className="font-semibold mb-3">Servicios</h2>
        <form onSubmit={crearServicio} className="flex flex-wrap gap-2 mb-3">
          <input required placeholder="Nombre" value={nuevoServ.nombre} onChange={(e) => setNuevoServ({ ...nuevoServ, nombre: e.target.value })} className="input !w-auto" />
          <input type="number" placeholder="Duración (min)" value={nuevoServ.duracion_min} onChange={(e) => setNuevoServ({ ...nuevoServ, duracion_min: Number(e.target.value) })} className="input !w-auto" />
          <input type="number" placeholder="Precio" value={nuevoServ.precio} onChange={(e) => setNuevoServ({ ...nuevoServ, precio: e.target.value })} className="input !w-auto" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Agregar</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {servicios.map((s) => (
            <li key={s.id} className="p-3 flex justify-between items-center">
              <span>{s.nombre} · <span className="text-slate-400">{s.duracion_min} min · ${Number(s.precio).toLocaleString()}</span></span>
              <button onClick={() => eliminarServicio(s.id)} className="text-red-400 text-sm hover:underline">Eliminar</button>
            </li>
          ))}
          {servicios.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin servicios.</li>}
        </ul>
      </section>

      {/* Bloqueos */}
      <section>
        <h2 className="font-semibold mb-3">Fechas bloqueadas (festivos, descanso)</h2>
        <form onSubmit={crearBloqueo} className="flex flex-wrap gap-2 mb-3 items-end">
          <label className="text-sm">Desde<input type="datetime-local" required value={bloqueo.inicio} onChange={(e) => setBloqueo({ ...bloqueo, inicio: e.target.value })} className="input mt-1" /></label>
          <label className="text-sm">Hasta<input type="datetime-local" required value={bloqueo.fin} onChange={(e) => setBloqueo({ ...bloqueo, fin: e.target.value })} className="input mt-1" /></label>
          <input placeholder="Motivo" value={bloqueo.motivo} onChange={(e) => setBloqueo({ ...bloqueo, motivo: e.target.value })} className="input !w-auto" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Bloquear</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {bloqueos.map((b) => (
            <li key={b.id} className="p-3 flex justify-between items-center text-sm">
              <span>{new Date(b.inicio).toLocaleString('es')} → {new Date(b.fin).toLocaleString('es')} {b.motivo && `· ${b.motivo}`}</span>
              <button onClick={() => eliminarBloqueo(b.id)} className="text-red-400 hover:underline">Quitar</button>
            </li>
          ))}
          {bloqueos.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin bloqueos.</li>}
        </ul>
      </section>
    </div>
  )
}
