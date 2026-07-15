import { useEffect, useState } from 'react'
import { API_BASE, api, getToken } from '../api/client'
import FirmaPad from '../components/FirmaPad'
import { useFeatures } from '../context/FeaturesContext'
import { aNumero } from '../utils/numero'

const FIRMA_KEY = 'logix_firma'

const ESTADO_COLOR = { EMITIDA: 'bg-emerald-600', PAGADA: 'bg-sky-600', ANULADA: 'bg-red-600', BORRADOR: 'bg-slate-600' }
const IVA_FIJOS = [0, 5, 19]
const LINEA_VACIA = { producto_id: '', descripcion: '', cantidad: 1, precio_unitario: '', impuesto_porcentaje: 19, ivaCustom: false }

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 })

export default function Facturacion() {
  const { activa } = useFeatures()
  const [facturas, setFacturas] = useState([])
  const [clientes, setClientes] = useState([])
  const [productos, setProductos] = useState([])
  const [abierto, setAbierto] = useState(false)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  const [cab, setCab] = useState({ cliente_id: '', fecha: new Date().toISOString().slice(0, 10), notas: '' })
  const [medioPago, setMedioPago] = useState('EFECTIVO')
  const [currency, setCurrency] = useState('COP')
  const [exchangeRate, setExchangeRate] = useState('')
  const [lineas, setLineas] = useState([{ ...LINEA_VACIA }])
  const [firma, setFirma] = useState(null)

  async function cargar() {
    const data = await api('/facturas')
    setFacturas(data.data ?? data)
  }
  useEffect(() => {
    cargar()
    api('/clientes').then((d) => setClientes(d.data ?? d))
    api('/productos').then((d) => setProductos(d.data ?? d)).catch(() => setProductos([]))
  }, [])

  // --- Manejo de líneas ---
  const updateLinea = (i, patch) => setLineas(lineas.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const addLinea = () => setLineas([...lineas, { ...LINEA_VACIA }])
  const quitarLinea = (i) => setLineas(lineas.length > 1 ? lineas.filter((_, j) => j !== i) : lineas)

  const setIva = (i, val) => {
    if (val === 'custom') updateLinea(i, { ivaCustom: true })
    else updateLinea(i, { ivaCustom: false, impuesto_porcentaje: Number(val) })
  }

  const setProducto = (i, productoId) => {
    const producto = productos.find((p) => String(p.id) === String(productoId))
    updateLinea(i, {
      producto_id: productoId,
      descripcion: producto ? producto.nombre : '',
      precio_unitario: producto ? producto.precio_venta : '',
    })
  }

  // --- Totales en vivo ---
  // aNumero entiende el formato colombiano: "400.000" = 400000 (no 400).
  const baseLinea = (l) => aNumero(l.cantidad) * aNumero(l.precio_unitario)
  const ivaLinea = (l) => baseLinea(l) * (aNumero(l.impuesto_porcentaje) || 0) / 100
  const subtotal = lineas.reduce((s, l) => s + baseLinea(l), 0)
  const ivaTotal = lineas.reduce((s, l) => s + ivaLinea(l), 0)
  const total = subtotal + ivaTotal

  function abrir() {
    setCab({ cliente_id: '', fecha: new Date().toISOString().slice(0, 10), notas: '' })
    setLineas([{ ...LINEA_VACIA }])
    setFirma(localStorage.getItem(FIRMA_KEY) || null) // precarga "mi firma" guardada
    setMedioPago('EFECTIVO')
    setError('')
    setAbierto(true)
  }

  // Venta de mostrador: preselecciona el cliente genérico "Consumidor Final"
  // (se crea automáticamente con la cuenta) para cobrar sin pedir datos.
  function ventaRapida() {
    abrir()
    const generico = clientes.find((c) => (c.nombre_completo || '').toLowerCase().includes('consumidor final'))
    if (generico) setCab((prev) => ({ ...prev, cliente_id: String(generico.id) }))
    else alert('Crea un cliente llamado "Consumidor Final" para usar la venta rápida.')
  }

  // Lector de código de barras / SKU: Enter agrega el producto como línea nueva.
  function escanear(e) {
    if (e.key !== 'Enter') return
    e.preventDefault()
    const codigo = e.target.value.trim().toLowerCase()
    if (!codigo) return
    const p = productos.find((x) => (x.sku || '').toLowerCase() === codigo)
      || productos.find((x) => (x.nombre || '').toLowerCase().includes(codigo))
    if (!p) { setError(`No se encontró un producto con el código "${e.target.value.trim()}".`); return }
    setError('')
    const nueva = { ...LINEA_VACIA, producto_id: String(p.id), descripcion: p.nombre, precio_unitario: p.precio_venta }
    // Reemplaza la primera línea si está vacía; si no, agrega al final.
    setLineas((prev) => (prev.length === 1 && !prev[0].descripcion ? [nueva] : [...prev, nueva]))
    e.target.value = ''
  }

  function guardarMiFirma() {
    if (firma) { localStorage.setItem(FIRMA_KEY, firma); alert('Firma guardada. Aparecerá automáticamente en tus próximas facturas.') }
  }
  function olvidarMiFirma() {
    localStorage.removeItem(FIRMA_KEY); setFirma(null)
  }

  async function crear(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      await api('/facturas', { method: 'POST', body: {
        cliente_id: cab.cliente_id,
        fecha: cab.fecha,
        notas: cab.notas || null,
        currency: currency || 'COP',
        exchange_rate: exchangeRate || null,
        metodo_pago: medioPago,
        lineas: lineas.map((l) => ({
          descripcion: l.descripcion,
          producto_id: l.producto_id ? Number(l.producto_id) : null,
          cantidad: aNumero(l.cantidad),
          precio_unitario: aNumero(l.precio_unitario),
          impuesto_porcentaje: aNumero(l.impuesto_porcentaje) || 0,
        })),
        firma: firma || null,
      } })
      setAbierto(false); cargar()
    } catch (err) { setError(err.message) } finally { setGuardando(false) }
  }

  async function generarPdf(f) {
    await api(`/facturas/${f.id}/pdf`, { method: 'POST' })
    const headers = { Accept: 'application/pdf' }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
    const res = await fetch(`${API_BASE}/api/facturas/${f.id}/pdf`, { headers })
    if (!res.ok) throw new Error('No se pudo abrir el PDF.')
    const blob = await res.blob()
    const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
    window.open(url, '_blank', 'noopener,noreferrer')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  }
  async function enviar(f) {
    const email = prompt('Enviar factura al correo:')
    if (!email) return
    const r = await api(`/facturas/${f.id}/enviar`, { method: 'POST', body: { email } })
    alert(r.mensaje)
  }
  async function enviarWhatsApp(f) {
    const r = await api(`/facturas/${f.id}/whatsapp`, { method: 'POST' })
    window.open(r.whatsapp_url, '_blank', 'noopener,noreferrer')
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Facturación</h1>
          <p className="text-sm text-slate-400">Genera, descarga y envía tus facturas de venta.</p>
        </div>
        {!abierto && (
          <div className="flex gap-2">
            <button onClick={ventaRapida} className="rounded-lg bg-sky-600 hover:bg-sky-500 px-4 py-2 text-sm font-semibold" title="Venta de mostrador a Consumidor Final">⚡ Venta rápida</button>
            <button onClick={abrir} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nueva factura</button>
          </div>
        )}
      </div>

      {abierto && (
        <form onSubmit={crear} className="mb-8 rounded-2xl border border-slate-800 bg-slate-900/60 shadow-xl overflow-hidden">
          {/* Encabezado del formulario */}
          <div className="flex items-center justify-between border-b border-slate-800 bg-slate-800/40 px-6 py-4">
            <h2 className="text-lg font-semibold">Nueva factura</h2>
            <span className="text-xs text-slate-400">El número se asigna automáticamente</span>
          </div>

          {error && <div className="mx-6 mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-300">{error}</div>}

          {/* Sección: datos generales */}
          <section className="px-6 py-5 border-b border-slate-800">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Datos de la factura</h3>
            <div className="grid sm:grid-cols-2 gap-4">
              <label className="block">
                <span className="mb-1 block text-sm text-slate-300">Cliente</span>
                <select required value={cab.cliente_id} onChange={(e) => setCab({ ...cab, cliente_id: e.target.value })} className="input">
                  <option value="">Seleccione un cliente…</option>
                  {clientes.map((c) => <option key={c.id} value={c.id}>{c.nombre_completo}</option>)}
                </select>
              </label>
              <label className="block">
                <span className="mb-1 block text-sm text-slate-300">Fecha de emisión</span>
                <input type="date" value={cab.fecha} onChange={(e) => setCab({ ...cab, fecha: e.target.value })} className="input" />
              </label>
            </div>

            {/* Medio de pago (obligatorio para el cierre de caja por método) */}
            <div className="mt-3">
              <span className="mb-1 block text-sm text-slate-300">Medio de pago</span>
              <div className="flex flex-wrap gap-2">
                {[['EFECTIVO', '💵 Efectivo'], ['TARJETA', '💳 Tarjeta'], ['NEQUI', '📱 Nequi'], ['DAVIPLATA', '📱 Daviplata'], ['TRANSFERENCIA', '🏦 Transferencia']].map(([v, label]) => (
                  <button type="button" key={v} onClick={() => setMedioPago(v)}
                    className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${medioPago === v ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
                    {label}
                  </button>
                ))}
              </div>
            </div>
              <div className="mt-3 grid sm:grid-cols-2 gap-4">
                <label className="block">
                  <span className="mb-1 block text-sm text-slate-300">Divisa</span>
                  <select value={currency} onChange={(e) => setCurrency(e.target.value)} className="input">
                    <option value="COP">Pesos (COP)</option>
                    <option value="USD">USD (Dólares)</option>
                  </select>
                </label>
                {currency === 'USD' && (
                  <label className="block">
                    <span className="mb-1 block text-sm text-slate-300">Tipo de cambio (opcional)</span>
                    <input type="number" step="0.000001" value={exchangeRate} onChange={(e) => setExchangeRate(e.target.value)} className="input" placeholder="Ej: 0.00027" />
                  </label>
                )}
              </div>
          </section>

          {/* Sección: productos / servicios */}
          <section className="px-6 py-5 border-b border-slate-800">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Productos y servicios</h3>

            {/* Lector de código de barras / búsqueda rápida por SKU */}
            <div className="mb-3 flex items-center gap-2 rounded-lg border border-sky-800/60 bg-sky-500/5 px-3 py-2">
              <span>📷</span>
              <input onKeyDown={escanear} placeholder="Escanea el código de barras o escribe el SKU y presiona Enter…"
                className="w-full bg-transparent text-sm focus:outline-none placeholder-slate-500" />
            </div>

            {/* Cabecera de columnas (solo escritorio) */}
            <div className="hidden md:grid grid-cols-[1fr_90px_140px_150px_120px_36px] gap-3 px-1 pb-2 text-xs font-medium text-slate-500">
              <span>Descripción</span>
              <span className="text-right">Cantidad</span>
              <span className="text-right">Valor unitario</span>
              <span>IVA</span>
              <span className="text-right">Importe</span>
              <span></span>
            </div>

            <div className="space-y-3 md:space-y-2">
              {lineas.map((l, i) => (
                <div key={i} className="grid grid-cols-2 md:grid-cols-[1fr_90px_140px_150px_120px_36px] gap-3 rounded-xl border border-slate-800 bg-slate-800/30 p-3 md:border-0 md:bg-transparent md:p-1 md:items-center">
                  <div className="col-span-2 md:col-span-1 grid gap-2">
                    <select value={l.producto_id} onChange={(e) => setProducto(i, e.target.value)} className="input">
                      <option value="">Servicio / texto libre</option>
                      {productos.map((p) => <option key={p.id} value={p.id}>{p.nombre} - stock {p.stock_total ?? 0}</option>)}
                    </select>
                    <input required placeholder="Producto o servicio" value={l.descripcion} onChange={(e) => updateLinea(i, { descripcion: e.target.value, producto_id: '' })} className="input" />
                  </div>
                  <input type="text" inputMode="decimal" placeholder="Cant." value={l.cantidad} onChange={(e) => updateLinea(i, { cantidad: e.target.value })} className="input md:text-right" />
                  <input type="text" inputMode="decimal" placeholder="Ej: 400.000" value={l.precio_unitario} onChange={(e) => updateLinea(i, { precio_unitario: e.target.value })} className="input md:text-right" />
                  <div className="flex gap-1">
                    <select value={l.ivaCustom ? 'custom' : String(l.impuesto_porcentaje)} onChange={(e) => setIva(i, e.target.value)} className="input">
                      {IVA_FIJOS.map((v) => <option key={v} value={v}>{v}%</option>)}
                      <option value="custom">Personalizado</option>
                    </select>
                    {l.ivaCustom && (
                      <input type="number" min="0" max="100" step="0.01" placeholder="% IVA" value={l.impuesto_porcentaje} onChange={(e) => updateLinea(i, { impuesto_porcentaje: e.target.value })} className="input w-20" title="Ingrese el porcentaje de IVA" />
                    )}
                  </div>
                  <div className="col-span-2 md:col-span-1 flex items-center justify-between md:justify-end text-sm">
                    <span className="text-slate-500 md:hidden">Importe</span>
                    <span className="font-medium text-white">{money(baseLinea(l))}</span>
                  </div>
                  <button type="button" onClick={() => quitarLinea(i)} disabled={lineas.length === 1}
                    className="justify-self-end text-slate-500 hover:text-red-400 disabled:opacity-30 disabled:hover:text-slate-500" title="Quitar línea">✕</button>
                </div>
              ))}
            </div>

            <button type="button" onClick={addLinea} className="mt-3 text-sm font-medium text-emerald-400 hover:text-emerald-300">+ Agregar línea</button>
          </section>

          {/* Sección: firma digital (solo planes con firma digital) */}
          {activa('firma') && (
            <section className="px-6 py-5 border-b border-slate-800">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">Firma digital</h3>
                <div className="flex gap-3 text-xs">
                  <button type="button" onClick={guardarMiFirma} disabled={!firma} className="text-emerald-400 hover:text-emerald-300 disabled:opacity-40">Guardar como mi firma</button>
                  <button type="button" onClick={olvidarMiFirma} className="text-slate-400 hover:text-slate-300">Olvidar firma guardada</button>
                </div>
              </div>
              <FirmaPad value={firma} onChange={setFirma} />
            </section>
          )}

          {/* Sección: notas + totales */}
          <section className="grid gap-6 px-6 py-5 md:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Notas</span>
              <textarea rows="4" placeholder="Observaciones de la factura (opcional)" value={cab.notas} onChange={(e) => setCab({ ...cab, notas: e.target.value })} className="input resize-none" />
            </label>

            <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
              <div className="flex items-center justify-between py-1 text-sm text-slate-300">
                <span>Subtotal</span><span>{money(subtotal)}</span>
              </div>
              <div className="flex items-center justify-between py-1 text-sm text-slate-300">
                <span>IVA</span><span>{money(ivaTotal)}</span>
              </div>
              <div className="mt-2 flex items-center justify-between border-t border-slate-700 pt-3 text-lg font-bold">
                <span>Total</span><span className="text-emerald-400">{money(total)}</span>
              </div>
            </div>
          </section>

          {/* Acciones */}
          <div className="flex justify-end gap-2 border-t border-slate-800 bg-slate-800/40 px-6 py-4">
            <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-semibold disabled:opacity-50">
              {guardando ? 'Emitiendo…' : 'Emitir factura'}
            </button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="text-left p-3">Número</th><th className="text-left p-3">Cliente</th><th className="text-left p-3">Fecha</th><th className="text-right p-3">Total</th><th className="text-left p-3">Estado</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {facturas.map((f) => (
              <tr key={f.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                <td className="p-3 font-mono">{f.numero}</td>
                <td className="p-3">{f.cliente?.nombre_completo}</td>
                <td className="p-3 text-slate-400">{f.fecha}</td>
                <td className="p-3 text-right">{money(f.total)}</td>
                <td className="p-3"><span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COLOR[f.estado]}`}>{f.estado}</span></td>
                <td className="p-3 text-right whitespace-nowrap">
                  <button onClick={() => generarPdf(f)} className="text-sky-400 hover:underline mr-3">PDF</button>
                  <button onClick={() => enviarWhatsApp(f)} className="text-lime-400 hover:underline mr-3">WhatsApp</button>
                  <button onClick={() => enviar(f)} className="text-emerald-400 hover:underline">Enviar</button>
                </td>
              </tr>
            ))}
            {facturas.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-slate-500">Sin facturas aún.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
