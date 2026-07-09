import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { aNumero } from '../utils/numero'

const VACIO = {
  sku: '', codigo_barras: '', nombre: '', descripcion: '',
  precio_costo: '', precio_venta: '', categoria_id: '', activo: true,
}

export default function Productos() {
  const [lista, setLista] = useState([])
  const [categorias, setCategorias] = useState([])
  const [form, setForm] = useState(VACIO)
  const [imagen, setImagen] = useState(null)
  const [editId, setEditId] = useState(null)
  const [error, setError] = useState('')
  const [abierto, setAbierto] = useState(false)

  async function cargar() {
    const data = await api('/productos')
    setLista(data.data ?? data)
  }
  useEffect(() => {
    cargar()
    api('/categorias').then(setCategorias)
  }, [])

  function nuevo() { setForm(VACIO); setImagen(null); setEditId(null); setError(''); setAbierto(true) }
  function editar(p) {
    setForm({ ...VACIO, ...p, categoria_id: p.categoria_id ?? '' })
    setImagen(null); setEditId(p.id); setError(''); setAbierto(true)
  }

  async function guardar(e) {
    e.preventDefault()
    setError('')
    try {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => {
        if (k === 'activo') fd.append(k, v ? '1' : '0')
        // Precios en formato colombiano: "400.000" debe llegar como 400000.
        else if (k === 'precio_costo' || k === 'precio_venta') fd.append(k, aNumero(v))
        else if (v !== null && v !== '') fd.append(k, v)
      })
      if (imagen) fd.append('imagen', imagen)

      if (editId) await api(`/productos/${editId}/update`, { method: 'POST', body: fd, isForm: true })
      else await api('/productos', { method: 'POST', body: fd, isForm: true })
      setAbierto(false); cargar()
    } catch (err) {
      setError(err.message + (err.errors ? ' ' + JSON.stringify(err.errors) : ''))
    }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar producto?')) return
    await api(`/productos/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Productos</h1>
        <button onClick={nuevo} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo</button>
      </div>

      {abierto && (
        <form onSubmit={guardar} className="mb-6 rounded-xl border border-slate-800 bg-slate-800/50 p-5 grid sm:grid-cols-2 gap-3">
          {error && <div className="sm:col-span-2 text-red-300 text-sm">{error}</div>}
          <input required placeholder="SKU" value={form.sku} onChange={set('sku')} className="input" />
          <input placeholder="Código de barras" value={form.codigo_barras ?? ''} onChange={set('codigo_barras')} className="input" />
          <input required placeholder="Nombre" value={form.nombre} onChange={set('nombre')} className="input sm:col-span-2" />
          <select value={form.categoria_id} onChange={set('categoria_id')} className="input">
            <option value="">Sin categoría</option>
            {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          <input type="file" accept="image/*" onChange={(e) => setImagen(e.target.files?.[0] ?? null)} className="input" />
          <input type="text" inputMode="decimal" placeholder="Precio costo (ej: 250.000)" value={form.precio_costo} onChange={set('precio_costo')} className="input" required />
          <input type="text" inputMode="decimal" placeholder="Precio venta (ej: 400.000)" value={form.precio_venta} onChange={set('precio_venta')} className="input" required />
          <textarea placeholder="Descripción" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input sm:col-span-2" />
          <div className="sm:col-span-2 flex gap-2">
            <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
            <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="p-3"></th><th className="text-left p-3">SKU</th><th className="text-left p-3">Nombre</th><th className="text-right p-3">Costo</th><th className="text-right p-3">Venta</th><th className="text-right p-3">Stock</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {lista.map((p) => (
              <tr key={p.id} className="border-t border-slate-800">
                <td className="p-2">
                  <div className="h-10 w-10 rounded bg-slate-700 overflow-hidden">
                    {p.imagen_url && <img src={p.imagen_url} alt="" className="h-full w-full object-cover" />}
                  </div>
                </td>
                <td className="p-3 text-slate-400">{p.sku}</td>
                <td className="p-3">{p.nombre}</td>
                <td className="p-3 text-right text-slate-400">${Number(p.precio_costo).toLocaleString()}</td>
                <td className="p-3 text-right">${Number(p.precio_venta).toLocaleString()}</td>
                <td className="p-3 text-right">{Number(p.stock_total ?? 0)}</td>
                <td className="p-3 text-right whitespace-nowrap">
                  <button onClick={() => editar(p)} className="text-emerald-400 hover:underline mr-3">Editar</button>
                  <button onClick={() => eliminar(p.id)} className="text-red-400 hover:underline">Eliminar</button>
                </td>
              </tr>
            ))}
            {lista.length === 0 && <tr><td colSpan="7" className="p-6 text-center text-slate-500">Sin productos aún.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
