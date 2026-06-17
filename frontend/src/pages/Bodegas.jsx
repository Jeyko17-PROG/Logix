import { useEffect, useState } from 'react'
import { api } from '../api/client'

export default function Bodegas() {
  const [lista, setLista] = useState([])
  const [categorias, setCategorias] = useState([])
  const [nombre, setNombre] = useState('')
  const [direccion, setDireccion] = useState('')
  const [catNombre, setCatNombre] = useState('')
  const [error, setError] = useState('')

  async function cargar() {
    setLista(await api('/bodegas'))
    setCategorias(await api('/categorias'))
  }
  useEffect(() => { cargar() }, [])

  async function crearBodega(e) {
    e.preventDefault(); setError('')
    try {
      await api('/bodegas', { method: 'POST', body: { nombre, direccion, activo: true } })
      setNombre(''); setDireccion(''); cargar()
    } catch (err) { setError(err.message) }
  }

  async function crearCategoria(e) {
    e.preventDefault()
    await api('/categorias', { method: 'POST', body: { nombre: catNombre } })
    setCatNombre(''); cargar()
  }

  async function definirPrincipal(b) {
    await api(`/bodegas/${b.id}/principal`, { method: 'POST' }); cargar()
  }
  async function renombrar(b) {
    const nuevo = prompt('Nuevo nombre de la bodega:', b.nombre)
    if (!nuevo || nuevo === b.nombre) return
    await api(`/bodegas/${b.id}`, { method: 'PUT', body: { nombre: nuevo, direccion: b.direccion, activo: b.activo } })
    cargar()
  }
  async function eliminar(b) {
    if (!confirm(`¿Eliminar la bodega "${b.nombre}"?`)) return
    try { await api(`/bodegas/${b.id}`, { method: 'DELETE' }); cargar() }
    catch (err) { alert(err.message || 'No se pudo eliminar.') }
  }

  return (
    <div className="grid md:grid-cols-2 gap-8">
      <div>
        <h1 className="text-2xl font-bold mb-4">Bodegas</h1>
        <form onSubmit={crearBodega} className="flex flex-col gap-2 mb-4">
          {error && <div className="text-red-300 text-sm">{error}</div>}
          <input required placeholder="Nombre de la bodega" value={nombre} onChange={(e) => setNombre(e.target.value)} className="input" />
          <input placeholder="Dirección (opcional)" value={direccion} onChange={(e) => setDireccion(e.target.value)} className="input" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold self-start">Agregar bodega</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {lista.map((b) => (
            <li key={b.id} className="p-3 flex items-center justify-between gap-2">
              <div className="min-w-0">
                <span className="font-medium">{b.nombre}</span>
                {b.es_principal && <span className="ml-2 text-xs rounded-full bg-emerald-500/15 text-emerald-400 px-2 py-0.5">Principal</span>}
                {b.direccion && <div className="text-slate-500 text-xs truncate">{b.direccion}</div>}
              </div>
              <div className="flex gap-1 shrink-0">
                {!b.es_principal && <button onClick={() => definirPrincipal(b)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Principal</button>}
                <button onClick={() => renombrar(b)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Renombrar</button>
                <button onClick={() => eliminar(b)} className="text-xs rounded bg-red-900 hover:bg-red-800 px-2 py-1">Eliminar</button>
              </div>
            </li>
          ))}
          {lista.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin bodegas.</li>}
        </ul>
      </div>

      <div>
        <h1 className="text-2xl font-bold mb-4">Categorías</h1>
        <form onSubmit={crearCategoria} className="flex gap-2 mb-4">
          <input required placeholder="Nueva categoría" value={catNombre} onChange={(e) => setCatNombre(e.target.value)} className="input" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold whitespace-nowrap">Agregar</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {categorias.map((c) => <li key={c.id} className="p-3">{c.nombre}</li>)}
          {categorias.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin categorías.</li>}
        </ul>
      </div>
    </div>
  )
}
