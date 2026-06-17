import { useRef, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

export default function Perfil() {
  const { user, setUser } = useAuth()
  const fileInput = useRef(null)
  const [name, setName] = useState(user?.name ?? '')
  const [telefono, setTelefono] = useState(user?.telefono ?? '')
  const [tipoDoc, setTipoDoc] = useState(user?.tipo_documento ?? 'CC')
  const [numDoc, setNumDoc] = useState(user?.numero_documento ?? '')
  const [msg, setMsg] = useState('')
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [subiendo, setSubiendo] = useState(false)

  // Cambio de contraseña
  const [pwd, setPwd] = useState({ actual: '', nueva: '', confirmar: '' })
  const [pwdMsg, setPwdMsg] = useState('')
  const [pwdError, setPwdError] = useState('')
  const [cambiandoPwd, setCambiandoPwd] = useState(false)

  async function guardarDatos(e) {
    e.preventDefault()
    setMsg(''); setError(''); setGuardando(true)
    try {
      const actualizado = await api('/perfil', { method: 'PUT', body: { name, telefono, tipo_documento: tipoDoc, numero_documento: numDoc } })
      setUser(actualizado)
      setMsg('Datos actualizados.')
    } catch (err) {
      setError(err.message || 'No se pudo guardar.')
    } finally {
      setGuardando(false)
    }
  }

  async function cambiarPassword(e) {
    e.preventDefault()
    setPwdMsg(''); setPwdError('')
    if (pwd.nueva !== pwd.confirmar) { setPwdError('Las contraseñas nuevas no coinciden.'); return }
    setCambiandoPwd(true)
    try {
      await api('/perfil/password', { method: 'PUT', body: {
        password_actual: pwd.actual, password: pwd.nueva, password_confirmation: pwd.confirmar,
      } })
      setPwd({ actual: '', nueva: '', confirmar: '' })
      setPwdMsg('Contraseña actualizada correctamente.')
    } catch (err) {
      setPwdError(err.message || 'No se pudo cambiar la contraseña.')
    } finally {
      setCambiandoPwd(false)
    }
  }

  async function subirFoto(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setMsg(''); setError(''); setSubiendo(true)
    try {
      const form = new FormData()
      form.append('foto', file)
      const data = await api('/perfil/foto', { method: 'POST', body: form, isForm: true })
      setUser(data.user)
      setMsg('Foto actualizada.')
    } catch (err) {
      setError(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendo(false)
    }
  }

  return (
    <div className="max-w-lg mx-auto">
      <h1 className="text-2xl font-bold text-white mb-6">Mi perfil</h1>

      {msg && <div className="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{msg}</div>}
      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {/* Foto de perfil */}
      <div className="flex items-center gap-4 mb-8">
        <div className="h-20 w-20 rounded-full bg-slate-700 overflow-hidden flex items-center justify-center text-2xl text-slate-300">
          {user?.foto_perfil_url
            ? <img src={user.foto_perfil_url} alt="Foto de perfil" className="h-full w-full object-cover" />
            : (user?.name?.[0] ?? '?')}
        </div>
        <div>
          {/* capture="user" abre la cámara frontal en móviles (PWA) */}
          <input
            ref={fileInput}
            type="file"
            accept="image/*"
            capture="user"
            onChange={subirFoto}
            className="hidden"
          />
          <button
            type="button"
            onClick={() => fileInput.current?.click()}
            disabled={subiendo}
            className="rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white text-sm px-4 py-2"
          >
            {subiendo ? 'Subiendo…' : 'Cambiar foto'}
          </button>
          <p className="text-xs text-slate-500 mt-1">Archivo o cámara · máx. 5 MB</p>
        </div>
      </div>

      {/* Datos básicos */}
      <form onSubmit={guardarDatos} className="space-y-4">
        <div>
          <label className="block text-sm text-slate-300 mb-1">Nombre</label>
          <input value={name} onChange={(e) => setName(e.target.value)}
            className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Celular</label>
          <input value={telefono} onChange={(e) => setTelefono(e.target.value)}
            className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
        </div>
        <div className="grid grid-cols-3 gap-2">
          <div>
            <label className="block text-sm text-slate-300 mb-1">Tipo doc.</label>
            <select value={tipoDoc} onChange={(e) => setTipoDoc(e.target.value)}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
              <option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option>
            </select>
          </div>
          <div className="col-span-2">
            <label className="block text-sm text-slate-300 mb-1">N° documento</label>
            <input value={numDoc} onChange={(e) => setNumDoc(e.target.value)}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
          </div>
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Correo</label>
          <input value={user?.email ?? ''} disabled
            className="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-slate-400" />
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Rol</label>
          <input value={user?.rol?.nombre ?? 'Sin rol'} disabled
            className="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-slate-400" />
        </div>
        <button type="submit" disabled={guardando}
          className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold px-5 py-2">
          {guardando ? 'Guardando…' : 'Guardar cambios'}
        </button>
      </form>

      {/* Cambio de contraseña */}
      <div className="mt-10 pt-8 border-t border-slate-800">
        <h2 className="text-lg font-bold text-white mb-4">Cambiar contraseña</h2>
        {pwdMsg && <div className="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{pwdMsg}</div>}
        {pwdError && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{pwdError}</div>}
        <form onSubmit={cambiarPassword} className="space-y-4">
          <div>
            <label className="block text-sm text-slate-300 mb-1">Contraseña actual</label>
            <input type="password" value={pwd.actual} onChange={(e) => setPwd({ ...pwd, actual: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required />
          </div>
          <div>
            <label className="block text-sm text-slate-300 mb-1">Nueva contraseña</label>
            <input type="password" value={pwd.nueva} onChange={(e) => setPwd({ ...pwd, nueva: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required minLength={8} />
          </div>
          <div>
            <label className="block text-sm text-slate-300 mb-1">Repite la nueva contraseña</label>
            <input type="password" value={pwd.confirmar} onChange={(e) => setPwd({ ...pwd, confirmar: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required minLength={8} />
          </div>
          <button type="submit" disabled={cambiandoPwd}
            className="rounded-lg bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white font-semibold px-5 py-2">
            {cambiandoPwd ? 'Actualizando…' : 'Cambiar contraseña'}
          </button>
        </form>
      </div>
    </div>
  )
}
