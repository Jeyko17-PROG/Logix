import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client'

const ESTADO_COLOR = {
  PENDIENTE: 'bg-amber-600', CONFIRMADA: 'bg-emerald-600', CANCELADA: 'bg-red-600',
  REPROGRAMADA: 'bg-sky-600', COMPLETADA: 'bg-slate-500', NO_ASISTIO: 'bg-rose-800',
}
const ESTADO_LABEL = {
  PENDIENTE: 'Pendiente', CONFIRMADA: 'Confirmada', CANCELADA: 'Cancelada',
  REPROGRAMADA: 'Reprogramada', COMPLETADA: 'Completada', NO_ASISTIO: 'No asistió',
}
const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']

function Leyenda() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-xs text-slate-400">
      {Object.entries(ESTADO_LABEL).map(([k, label]) => (
        <span key={k} className="flex items-center gap-1.5">
          <span className={`h-2.5 w-2.5 rounded-full ${ESTADO_COLOR[k]}`} />{label}
        </span>
      ))}
    </div>
  )
}

const ymd = (d) => d.toISOString().slice(0, 10)
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x }
const startOfWeek = (d) => addDays(d, -d.getDay())
const fmtHora = (iso) => new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })

export default function Agenda() {
  const [vista, setVista] = useState('dia')
  const [fecha, setFecha] = useState(new Date())
  const [citas, setCitas] = useState([])
  const [clientes, setClientes] = useState([])
  const [servicios, setServicios] = useState([])
  const [nueva, setNueva] = useState(false)

  // Rango visible según la vista
  const [desde, hasta] = useMemo(() => {
    if (vista === 'dia') return [fecha, fecha]
    if (vista === 'semana') { const s = startOfWeek(fecha); return [s, addDays(s, 6)] }
    const first = new Date(fecha.getFullYear(), fecha.getMonth(), 1)
    const last = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0)
    return [first, last]
  }, [vista, fecha])

  async function cargar() {
    const data = await api(`/citas?desde=${ymd(desde)}&hasta=${ymd(hasta)}`)
    setCitas(data)
  }
  useEffect(() => { cargar() }, [desde, hasta]) // eslint-disable-line
  useEffect(() => {
    api('/clientes').then((d) => setClientes(d.data ?? d))
    api('/servicios').then(setServicios)
  }, [])

  const citasDe = (d) => citas.filter((c) => c.inicio.slice(0, 10) === ymd(d))

  async function accion(id, acc) {
    await api(`/citas/${id}/${acc}`, { method: 'POST' })
    cargar()
  }

  function navegar(dir) {
    if (vista === 'dia') setFecha(addDays(fecha, dir))
    else if (vista === 'semana') setFecha(addDays(fecha, dir * 7))
    else setFecha(new Date(fecha.getFullYear(), fecha.getMonth() + dir, 1))
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 className="text-2xl font-bold">Agenda y Citas</h1>
        <div className="flex gap-2">
          {['dia', 'semana', 'mes'].map((v) => (
            <button key={v} onClick={() => setVista(v)}
              className={`px-3 py-1.5 rounded-lg text-sm capitalize ${vista === v ? 'bg-emerald-600' : 'bg-slate-700'}`}>{v}</button>
          ))}
          <button onClick={() => setNueva(true)} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-1.5 text-sm font-semibold">+ Cita</button>
        </div>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <button onClick={() => navegar(-1)} className="px-3 py-1 rounded bg-slate-700">‹</button>
        <button onClick={() => setFecha(new Date())} className="px-3 py-1 rounded bg-slate-700 text-sm">Hoy</button>
        <button onClick={() => navegar(1)} className="px-3 py-1 rounded bg-slate-700">›</button>
        <span className="text-slate-300 font-medium">
          {vista === 'mes'
            ? fecha.toLocaleDateString('es', { month: 'long', year: 'numeric' })
            : `${desde.toLocaleDateString('es', { day: 'numeric', month: 'short' })}${vista === 'semana' ? ' – ' + hasta.toLocaleDateString('es', { day: 'numeric', month: 'short' }) : ''}`}
        </span>
      </div>

      <Leyenda />

      {/* Vista DÍA */}
      {vista === 'dia' && (
        <div className="space-y-2">
          {citasDe(fecha).length === 0 && <p className="text-slate-500">Sin citas este día.</p>}
          {citasDe(fecha).map((c) => (
            <div key={c.id} className="flex items-stretch gap-3 overflow-hidden rounded-lg border border-slate-800 bg-slate-800/50">
              <div className={`w-1.5 shrink-0 ${ESTADO_COLOR[c.estado]}`} />
              <div className="flex flex-1 items-center justify-between p-3">
                <div>
                  <span className="font-mono text-emerald-400">{fmtHora(c.inicio)}–{fmtHora(c.fin)}</span>
                  <span className="ml-3">{c.cliente?.nombre_completo}</span>
                  <span className="ml-2 text-slate-400 text-sm">{c.servicio?.nombre ?? ''}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COLOR[c.estado]}`}>{ESTADO_LABEL[c.estado] ?? c.estado}</span>
                  {!['CANCELADA', 'COMPLETADA'].includes(c.estado) && <>
                    <button onClick={() => accion(c.id, 'confirmar')} className="text-emerald-400 text-sm hover:underline">Confirmar</button>
                    <button onClick={() => accion(c.id, 'cancelar')} className="text-red-400 text-sm hover:underline">Cancelar</button>
                  </>}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Vista SEMANA */}
      {vista === 'semana' && (
        <div className="grid grid-cols-2 md:grid-cols-7 gap-2">
          {Array.from({ length: 7 }, (_, i) => addDays(startOfWeek(fecha), i)).map((d) => (
            <div key={d} className="rounded-lg border border-slate-800 bg-slate-800/30 p-2 min-h-28">
              <div className="text-xs text-slate-400 mb-1">{DIAS[d.getDay()]} {d.getDate()}</div>
              {citasDe(d).map((c) => (
                <div key={c.id} className={`text-xs rounded px-1.5 py-1 mb-1 ${ESTADO_COLOR[c.estado]}`}>
                  {fmtHora(c.inicio)} {c.cliente?.nombre_completo}
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      {/* Vista MES */}
      {vista === 'mes' && <VistaMes fecha={fecha} citas={citas} onDia={(d) => { setFecha(d); setVista('dia') }} />}

      {nueva && <NuevaCita clientes={clientes} servicios={servicios} onClose={() => setNueva(false)} onCreada={() => { setNueva(false); cargar() }} />}
    </div>
  )
}

function VistaMes({ fecha, citas, onDia }) {
  const first = new Date(fecha.getFullYear(), fecha.getMonth(), 1)
  const startPad = first.getDay()
  const diasMes = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0).getDate()
  const celdas = [...Array(startPad).fill(null), ...Array.from({ length: diasMes }, (_, i) => i + 1)]
  const citasDia = (dia) => citas.filter((c) => {
    const d = new Date(c.inicio)
    return d.getDate() === dia && d.getMonth() === fecha.getMonth()
  })
  const hoy = new Date()
  const esHoy = (dia) => hoy.getDate() === dia && hoy.getMonth() === fecha.getMonth() && hoy.getFullYear() === fecha.getFullYear()

  return (
    <div className="grid grid-cols-7 gap-1">
      {DIAS.map((d) => <div key={d} className="text-center text-xs text-slate-500 py-1">{d}</div>)}
      {celdas.map((dia, i) => {
        const lista = dia ? citasDia(dia) : []
        return (
          <div key={i} onClick={() => dia && onDia(new Date(fecha.getFullYear(), fecha.getMonth(), dia))}
            className={`min-h-16 rounded border p-1 text-sm ${dia ? 'cursor-pointer border-slate-800 hover:bg-slate-800' : 'border-transparent opacity-30'} ${esHoy(dia) ? 'ring-1 ring-emerald-500' : ''}`}>
            {dia && <>
              <div className={esHoy(dia) ? 'font-bold text-emerald-400' : 'text-slate-400'}>{dia}</div>
              {lista.length > 0 && (
                <div className="mt-1 flex flex-wrap gap-1">
                  {lista.slice(0, 5).map((c) => (
                    <span key={c.id} title={`${fmtHora(c.inicio)} ${c.cliente?.nombre_completo ?? ''}`}
                      className={`h-2 w-2 rounded-full ${ESTADO_COLOR[c.estado]}`} />
                  ))}
                  {lista.length > 5 && <span className="text-[10px] text-slate-500">+{lista.length - 5}</span>}
                </div>
              )}
            </>}
          </div>
        )
      })}
    </div>
  )
}

function NuevaCita({ clientes, servicios, onClose, onCreada }) {
  const [lista, setLista] = useState(clientes)        // clientes (incluye los creados aquí)
  const [cliente_id, setCliente] = useState('')
  const [servicio_id, setServicio] = useState('')
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10))
  const [slots, setSlots] = useState([])
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  // Crear cliente en línea (para usuarios que aún no tienen clientes).
  const [nuevoCliente, setNuevoCliente] = useState('')
  const [creandoCli, setCreandoCli] = useState(false)

  async function crearCliente() {
    if (!nuevoCliente.trim()) return
    setCreandoCli(true); setError('')
    try {
      const c = await api('/clientes', { method: 'POST', body: { nombre_completo: nuevoCliente.trim() } })
      setLista([c, ...lista])
      setCliente(String(c.id))
      setNuevoCliente('')
    } catch (err) { setError(err.message) }
    finally { setCreandoCli(false) }
  }

  async function buscar() {
    setError('')
    const q = `/citas/disponibilidad?fecha=${fecha}${servicio_id ? `&servicio_id=${servicio_id}` : ''}`
    const data = await api(q)
    setSlots(data.slots)
  }

  async function reservar(inicio) {
    if (!cliente_id) { setError('Selecciona o crea un cliente primero.'); return }
    setError(''); setGuardando(true)
    try {
      await api('/citas', { method: 'POST', body: { cliente_id, servicio_id: servicio_id || null, inicio } })
      onCreada()
    } catch (err) { setError(err.message) } finally { setGuardando(false) }
  }

  return (
    <div onClick={onClose} className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div onClick={(e) => e.stopPropagation()} className="bg-slate-800 rounded-2xl p-6 max-w-md w-full max-h-[85vh] overflow-y-auto">
        <h2 className="text-xl font-bold mb-4">Nueva cita</h2>
        {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-red-300 text-sm mb-3">{error}</div>}
        <div className="space-y-3">
          <select required value={cliente_id} onChange={(e) => setCliente(e.target.value)} className="input">
            <option value="">Cliente…</option>
            {lista.map((c) => <option key={c.id} value={c.id}>{c.nombre_completo}</option>)}
          </select>

          {/* Crear cliente sin salir de la cita */}
          <div className="flex gap-2">
            <input value={nuevoCliente} onChange={(e) => setNuevoCliente(e.target.value)} placeholder="o escribe un cliente nuevo…" className="input" />
            <button type="button" onClick={crearCliente} disabled={creandoCli || !nuevoCliente.trim()}
              className="rounded-lg bg-slate-600 hover:bg-slate-500 disabled:opacity-50 px-3 text-sm whitespace-nowrap">{creandoCli ? '…' : '+ Crear'}</button>
          </div>

          <select value={servicio_id} onChange={(e) => setServicio(e.target.value)} className="input">
            <option value="">Servicio (opcional)…</option>
            {servicios.map((s) => <option key={s.id} value={s.id}>{s.nombre} ({s.duracion_min} min)</option>)}
          </select>

          <div className="grid gap-2">
            <input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} className="input" />
          </div>
          <button onClick={buscar} className="w-full rounded-lg bg-sky-600 hover:bg-sky-500 px-4 py-2 text-sm">Ver horarios sugeridos</button>
        </div>

        {slots.length > 0 && (
          <div className="mt-4">
            <p className="text-sm text-slate-400 mb-2">Toca un horario disponible para reservar:</p>
            <div className="grid grid-cols-3 gap-2">
              {slots.map((s, i) => (
                <button key={i} disabled={!s.disponible || !cliente_id || guardando}
                  onClick={() => reservar(s.inicio)}
                  className={`text-sm rounded-lg py-2 ${s.disponible ? 'bg-emerald-700 hover:bg-emerald-600' : 'bg-slate-700 opacity-40 cursor-not-allowed line-through'}`}>
                  {fmtHora(s.inicio)}
                </button>
              ))}
            </div>
            {!cliente_id && <p className="text-amber-400 text-xs mt-2">Selecciona o crea un cliente primero.</p>}
          </div>
        )}
        <button onClick={onClose} className="mt-4 rounded-lg bg-slate-700 px-4 py-2 text-sm w-full">Cerrar</button>
      </div>
    </div>
  )
}
