import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api/client'

const fmtHora = (iso) => new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
const fmtFecha = (iso) => new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' })

// Ícono del tipo de vehículo elegido en la cita/reserva.
const iconoVehiculo = (tipo) => (tipo === 'moto' ? '🏍️' : tipo === 'carro' ? '🚗' : '')

// Ícono por defecto según el rubro del negocio, mientras no suba su propio logo.
const ICONO_TIPO_NEGOCIO = { lavadero: '🧼', barberia: '💈', spa: '💆' }

// Ícono decorativo por categoría de servicio (spa/estética), por palabra clave; genérico si no calza ninguna.
function iconoCategoria(nombre) {
  const n = (nombre || '').toLowerCase()
  if (n.includes('uña')) return '💅'
  if (n.includes('cabello') || n.includes('pelo')) return '💇'
  if (n.includes('pestañ') || n.includes('ceja')) return '👁️'
  if (n.includes('facial') || n.includes('corporal')) return '🧖'
  if (n.includes('depila')) return '🪒'
  if (n.includes('masaje')) return '💆'
  return '✨'
}

// Página PÚBLICA de reservas (destino del QR). No requiere iniciar sesión.
// La URL incluye el slug del negocio: /reservar/:slug (cada usuario tiene el suyo).
export default function Reserva() {
  const { slug } = useParams()
  const base = slug ? `/publico/${slug}` : '/publico'
  const [tab, setTab] = useState('reservar')
  const [negocio, setNegocio] = useState(null)
  const [noExiste, setNoExiste] = useState(false)

  useEffect(() => {
    if (!slug) return
    api(`${base}/negocio`).then(setNegocio).catch(() => setNoExiste(true))
  }, [slug, base])

  const esLavadero = negocio?.tipo_negocio === 'lavadero'

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 px-4 py-8">
      <div className="max-w-md mx-auto">
        {negocio?.logo_url ? (
          <img src={negocio.logo_url} alt={negocio.nombre || 'Logix'}
            className="h-16 w-16 object-contain mx-auto mb-3 rounded-lg drop-shadow-lg"
            onError={(e) => { e.currentTarget.style.display = 'none' }} />
        ) : (negocio?.logo_emoji || ICONO_TIPO_NEGOCIO[negocio?.tipo_negocio]) ? (
          <div className="h-16 w-16 mx-auto mb-3 rounded-lg bg-slate-800 flex items-center justify-center text-4xl drop-shadow-lg">
            {negocio.logo_emoji || ICONO_TIPO_NEGOCIO[negocio.tipo_negocio]}
          </div>
        ) : (
          <img src="/logo.svg" alt="Logix"
            className="h-16 w-16 object-contain mx-auto mb-3 drop-shadow-lg"
            onError={(e) => { e.currentTarget.style.display = 'none' }} />
        )}
        <h1 className="text-2xl font-bold text-center">Reserva tu cita</h1>
        <p className="text-slate-400 text-center text-sm mb-6">{negocio?.nombre || 'Logix'}</p>

        {noExiste ? (
          <div className="rounded-xl bg-red-500/10 border border-red-500/40 p-4 text-center text-red-300">
            Este enlace de reservas no es válido.
          </div>
        ) : (
          <>
            <div className="flex gap-2 mb-6">
              <button onClick={() => setTab('reservar')} className={`flex-1 py-2 rounded-lg text-sm ${tab === 'reservar' ? 'bg-emerald-600' : 'bg-slate-800'}`}>Reservar</button>
              <button onClick={() => setTab('mis')} className={`flex-1 py-2 rounded-lg text-sm ${tab === 'mis' ? 'bg-emerald-600' : 'bg-slate-800'}`}>Mis citas</button>
            </div>
            {tab === 'reservar' ? <FormReserva base={base} esLavadero={esLavadero} /> : <MisCitas base={base} />}
          </>
        )}
      </div>
    </div>
  )
}

function FormReserva({ base, esLavadero }) {
  const [sucursales, setSucursales] = useState([])
  const [sucursalId, setSucursalId] = useState('')
  const [servicios, setServicios] = useState([])
  const [categoriaSel, setCategoriaSel] = useState(null) // nombre de categoría elegida, o null
  const [planes, setPlanes] = useState([])
  const [form, setForm] = useState({ nombre_completo: '', email: '', telefono: '', servicio_id: '', plan_lavado_id: '', tipo_vehiculo: '', placa: '' })
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10))
  const [slots, setSlots] = useState([])
  const [seleccion, setSeleccion] = useState(null)
  const [confirmada, setConfirmada] = useState(null)
  const [error, setError] = useState('')

  // Multisucursal: si el negocio tiene más de una sede, primero se elige dónde.
  useEffect(() => {
    api(`${base}/sucursales`).then((lista) => {
      setSucursales(lista)
    }).catch(() => {})
  }, [base])

  // Con 0 o 1 sucursal no se pide elegir (retrocompatible: no se manda bodega_id).
  // Con 2+ sucursales, el cliente debe elegir explícitamente dónde.
  const sucursalElegida = sucursales.length < 2 || sucursalId

  useEffect(() => {
    if (!sucursalElegida) return
    const q = sucursalId ? `?bodega_id=${sucursalId}` : ''
    if (esLavadero) api(`${base}/planes-lavado${q}`).then(setPlanes).catch(() => {})
    else api(`${base}/servicios${q}`).then(setServicios).catch(() => {})
  }, [base, esLavadero, sucursalId, sucursalElegida])

  const servicioSeleccionado = servicios.find((s) => String(s.id) === String(form.servicio_id))

  // Agrupa los servicios por categoría para el catálogo visual (estilo spa/estética).
  const categorias = servicios.reduce((acc, s) => {
    const nombre = s.categoria?.nombre ?? 'Otros servicios'
    ;(acc[nombre] ??= []).push(s)
    return acc
  }, {})

  async function buscar() {
    setError(''); setSeleccion(null)
    const params = esLavadero
      ? (form.plan_lavado_id ? `&plan_lavado_id=${form.plan_lavado_id}` : '')
      : (form.servicio_id ? `&servicio_id=${form.servicio_id}` : '')
    const bodega = sucursalId ? `&bodega_id=${sucursalId}` : ''
    const data = await api(`${base}/disponibilidad?fecha=${fecha}${params}${bodega}`)
    setSlots(data.slots)
  }

  async function reservar(e) {
    e.preventDefault(); setError('')
    if (!seleccion) { setError('Selecciona un horario.'); return }
    if (esLavadero && (!form.tipo_vehiculo || !form.placa)) { setError('Indica el tipo de vehículo y la placa.'); return }
    try {
      const r = await api(`${base}/reservar`, {
        method: 'POST',
        body: {
          ...form,
          servicio_id: form.servicio_id || null,
          plan_lavado_id: form.plan_lavado_id || null,
          bodega_id: sucursalId || null,
          tipo_vehiculo: form.tipo_vehiculo || null,
          placa: form.placa || null,
          inicio: seleccion,
        },
      })
      setConfirmada(r.cita)
    } catch (err) { setError(err.message) }
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  if (confirmada) {
    return (
      <div className="rounded-2xl bg-emerald-600/20 border border-emerald-500/40 p-6 text-center">
        <div className="text-4xl mb-2">✅</div>
        <h2 className="text-xl font-bold">¡Reserva confirmada!</h2>
        <p className="mt-2 text-slate-200">{fmtFecha(confirmada.inicio)}</p>
        {confirmada.bodega && <p className="text-slate-400 text-sm">📍 {confirmada.bodega.nombre}</p>}
        {confirmada.plan_lavado && <p className="text-slate-400 text-sm">{confirmada.plan_lavado.icono ? `${confirmada.plan_lavado.icono} ` : ''}{confirmada.plan_lavado.nombre}</p>}
        {confirmada.servicio && <p className="text-slate-400 text-sm">{confirmada.servicio.icono ? `${confirmada.servicio.icono} ` : ''}{confirmada.servicio.nombre}</p>}
        {confirmada.tipo_vehiculo && (
          <p className="text-slate-400 text-sm mt-1">{iconoVehiculo(confirmada.tipo_vehiculo)} {confirmada.tipo_vehiculo === 'moto' ? 'Moto' : 'Carro'} · Placa {confirmada.placa}</p>
        )}
        <button onClick={() => {
          setConfirmada(null); setSlots([]); setCategoriaSel(null)
          setForm({ nombre_completo: '', email: '', telefono: '', servicio_id: '', plan_lavado_id: '', tipo_vehiculo: '', placa: '' })
        }} className="mt-4 rounded-lg bg-slate-700 px-4 py-2 text-sm">Hacer otra reserva</button>
      </div>
    )
  }

  // Paso 0 (solo si hay más de una sucursal): elegir dónde.
  if (!sucursalElegida) {
    return (
      <div>
        <h2 className="text-lg font-semibold mb-3">📍 Elige tu sucursal</h2>
        <div className="grid gap-2">
          {sucursales.map((s) => (
            <button key={s.id} onClick={() => setSucursalId(String(s.id))}
              className="text-left rounded-xl border border-slate-700 bg-slate-800/50 hover:bg-slate-800 p-4 transition">
              <p className="font-semibold">{s.nombre}</p>
              <p className="text-sm text-slate-400">{[s.direccion, s.ciudad].filter(Boolean).join(' · ') || 'Sin dirección registrada'}</p>
              {s.telefono && <p className="text-xs text-slate-500 mt-0.5">📞 {s.telefono}</p>}
            </button>
          ))}
          {sucursales.length === 0 && <p className="text-slate-500 text-sm">Cargando sucursales…</p>}
        </div>
      </div>
    )
  }

  return (
    <form onSubmit={reservar} className="space-y-3">
      {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {sucursales.length > 1 && (
        <div className="flex items-center justify-between rounded-lg border border-slate-700 bg-slate-800/40 px-3 py-2 text-sm">
          <span>📍 {sucursales.find((s) => String(s.id) === sucursalId)?.nombre}</span>
          <button type="button" onClick={() => setSucursalId('')} className="text-xs text-sky-400 hover:underline">Cambiar</button>
        </div>
      )}

      <input required placeholder="Nombre completo" value={form.nombre_completo} onChange={set('nombre_completo')} className="input" />
      <input required type="email" placeholder="Correo" value={form.email} onChange={set('email')} className="input" />
      <input required placeholder="Teléfono" value={form.telefono} onChange={set('telefono')} className="input" />

      {esLavadero ? (
        <>
          <select value={form.plan_lavado_id} onChange={set('plan_lavado_id')} className="input">
            <option value="">Selecciona un plan de lavado…</option>
            {planes.map((p) => <option key={p.id} value={p.id}>{p.icono ? `${p.icono} ` : ''}{p.nombre} · {p.duracion_min} min · ${Number(p.precio).toLocaleString()}</option>)}
          </select>
          <div className="flex gap-2">
            <select required value={form.tipo_vehiculo} onChange={set('tipo_vehiculo')} className="input">
              <option value="">Tipo de vehículo…</option>
              <option value="moto">🏍️ Moto</option>
              <option value="carro">🚗 Carro</option>
            </select>
            <input required placeholder="Placa" value={form.placa} onChange={(e) => setForm({ ...form, placa: e.target.value.toUpperCase() })} className="input uppercase" />
          </div>
        </>
      ) : servicioSeleccionado ? (
        <div className="flex items-center justify-between rounded-lg border border-emerald-600/50 bg-emerald-500/10 px-3 py-2.5">
          <div className="text-sm">
            <p className="font-semibold">{servicioSeleccionado.icono ? `${servicioSeleccionado.icono} ` : ''}{servicioSeleccionado.nombre}</p>
            <p className="text-xs text-slate-400">{servicioSeleccionado.duracion_min} min · ${Number(servicioSeleccionado.precio).toLocaleString()}</p>
          </div>
          <button type="button" onClick={() => setForm({ ...form, servicio_id: '' })} className="text-xs text-slate-400 hover:text-white">Cambiar</button>
        </div>
      ) : categoriaSel ? (
        <div>
          <button type="button" onClick={() => setCategoriaSel(null)} className="text-xs text-sky-400 hover:underline mb-2">← Todas las categorías</button>
          <p className="text-sm font-semibold mb-2">{iconoCategoria(categoriaSel)} {categoriaSel}</p>
          <div className="grid grid-cols-2 gap-2">
            {(categorias[categoriaSel] ?? []).map((s) => (
              <button type="button" key={s.id} onClick={() => setForm({ ...form, servicio_id: String(s.id) })}
                className="text-left rounded-xl border border-slate-700 bg-slate-800/50 hover:bg-slate-800 overflow-hidden transition">
                {s.imagen && <img src={s.imagen} alt="" className="w-full h-20 object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
                <div className="p-2.5">
                  <p className="text-sm font-medium leading-tight">{s.icono ? `${s.icono} ` : ''}{s.nombre}</p>
                  <p className="text-xs text-slate-400 mt-0.5">{s.duracion_min} min · ${Number(s.precio).toLocaleString()}</p>
                </div>
              </button>
            ))}
          </div>
        </div>
      ) : (
        <div>
          <p className="text-sm font-semibold mb-2">Elige una categoría</p>
          <div className="grid grid-cols-2 gap-2">
            {Object.keys(categorias).map((nombre) => (
              <button type="button" key={nombre} onClick={() => setCategoriaSel(nombre)}
                className="rounded-xl border border-slate-700 bg-gradient-to-br from-slate-800 to-slate-800/60 hover:from-slate-700 p-4 text-center transition">
                <div className="text-3xl mb-1">{iconoCategoria(nombre)}</div>
                <p className="text-sm font-medium">{nombre}</p>
                <p className="text-xs text-slate-500">{categorias[nombre].length} servicio(s)</p>
              </button>
            ))}
            {servicios.length === 0 && <p className="text-slate-500 text-sm col-span-2">Aún no hay servicios disponibles en esta sucursal.</p>}
          </div>
        </div>
      )}

      <div className="flex gap-2">
        <input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} className="input" />
        <button type="button" onClick={buscar} className="rounded-lg bg-sky-600 hover:bg-sky-500 px-4 text-sm whitespace-nowrap">Ver horarios</button>
      </div>

      {slots.length > 0 && (
        <div className="grid grid-cols-3 gap-2">
          {slots.map((s, i) => (
            <button type="button" key={i} disabled={!s.disponible}
              onClick={() => setSeleccion(s.inicio)}
              className={`text-sm rounded-lg py-2 ${
                seleccion === s.inicio ? 'bg-emerald-500 ring-2 ring-white'
                : s.disponible ? 'bg-emerald-700 hover:bg-emerald-600' : 'bg-slate-700 opacity-40 line-through cursor-not-allowed'}`}>
              {fmtHora(s.inicio)}
            </button>
          ))}
        </div>
      )}
      {slots.length > 0 && <button className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 py-2.5 font-semibold">Confirmar reserva</button>}
    </form>
  )
}

function MisCitas({ base }) {
  const [email, setEmail] = useState('')
  const [citas, setCitas] = useState(null)

  async function buscar(e) {
    e.preventDefault()
    const data = await api(`${base}/mis-citas?email=${encodeURIComponent(email)}`)
    setCitas(data.citas)
  }
  async function cancelar(id) {
    if (!confirm('¿Cancelar esta cita?')) return
    await api(`${base}/citas/${id}/cancelar`, { method: 'POST', body: { email } })
    buscar({ preventDefault() {} })
  }

  return (
    <div>
      <form onSubmit={buscar} className="flex gap-2 mb-4">
        <input required type="email" placeholder="Tu correo" value={email} onChange={(e) => setEmail(e.target.value)} className="input" />
        <button className="rounded-lg bg-sky-600 hover:bg-sky-500 px-4 text-sm">Buscar</button>
      </form>
      {citas && citas.length === 0 && <p className="text-slate-500 text-sm">No se encontraron citas.</p>}
      <div className="space-y-2">
        {citas?.map((c) => (
          <div key={c.id} className="rounded-lg border border-slate-800 bg-slate-800/50 p-3 flex justify-between items-center">
            <div>
              <p>{fmtFecha(c.inicio)}</p>
              <p className="text-slate-400 text-sm">
                {(c.plan_lavado?.icono || c.servicio?.icono) ? `${c.plan_lavado?.icono || c.servicio?.icono} ` : ''}
                {c.plan_lavado?.nombre ?? c.servicio?.nombre ?? 'Cita'} · {c.estado}
              </p>
              {c.bodega && <p className="text-slate-500 text-xs">📍 {c.bodega.nombre}</p>}
              {c.tipo_vehiculo && <p className="text-slate-500 text-xs">{iconoVehiculo(c.tipo_vehiculo)} {c.tipo_vehiculo === 'moto' ? 'Moto' : 'Carro'} · Placa {c.placa}</p>}
            </div>
            {!['CANCELADA', 'COMPLETADA'].includes(c.estado) &&
              <button onClick={() => cancelar(c.id)} className="text-red-400 text-sm hover:underline">Cancelar</button>}
          </div>
        ))}
      </div>
    </div>
  )
}
