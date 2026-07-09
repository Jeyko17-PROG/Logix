import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../api/client'

const VACIO = {
  name: '', tipo_documento: 'CC', numero_documento: '', telefono: '',
  email: '', password: '', password_confirmation: '',
  nombre_empresa: '', tipo_negocio_id: '',
}

export default function Login() {
  const { login, register, forgotPassword } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  // Modo inicial según el botón pulsado en la pantalla de bienvenida (?modo=registro).
  const [modo, setModo] = useState(params.get('modo') === 'registro' ? 'registro' : 'login') // 'login' | 'registro' | 'recuperar'
  const [form, setForm] = useState(VACIO)
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [tiposNegocio, setTiposNegocio] = useState([])

  // Catálogo de tipos de negocio para el registro (define los módulos del POS).
  useEffect(() => {
    api('/tipos-negocio').then(setTiposNegocio).catch(() => {})
  }, [])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  function cambiarModo(m) {
    setModo(m); setError(''); setOk('')
  }

  async function submit(e) {
    e.preventDefault()
    setError(''); setOk(''); setEnviando(true)
    try {
      if (modo === 'login') {
        await login(form.email, form.password)
        navigate('/')
      } else if (modo === 'registro') {
        if (form.password !== form.password_confirmation) throw { message: 'Las contraseñas no coinciden.' }
        await register({
          name: form.name,
          tipo_documento: form.tipo_documento,
          numero_documento: form.numero_documento,
          telefono: form.telefono,
          email: form.email,
          password: form.password,
          password_confirmation: form.password_confirmation,
          nombre_empresa: form.nombre_empresa || form.name,
          tipo_negocio_id: form.tipo_negocio_id || null,
        })
        navigate('/')
      } else if (modo === 'recuperar') {
        await forgotPassword(form.email)
        setOk('Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.')
      }
    } catch (err) {
      setError(err.message || 'No se pudo completar la acción.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 px-4 py-8">
      <div className="w-full max-w-sm">
        {/* Logo */}
        <div className="flex flex-col items-center mb-6">
          <img
            src="/logo.png"
            alt="Logix"
            className="h-20 w-20 object-contain drop-shadow-lg"
            onError={(e) => {
              if (!e.currentTarget.dataset.fb) { e.currentTarget.dataset.fb = '1'; e.currentTarget.src = '/logo.svg'; return }
              e.currentTarget.style.display = 'none'; e.currentTarget.nextSibling.style.display = 'block'
            }}
          />
          <span style={{ display: 'none' }} className="text-3xl font-extrabold text-white tracking-wide">LOGIX</span>
        </div>

        {/* Tarjeta */}
        <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
          {/* Encabezado con los dos botones */}
          <div className="bg-gradient-to-r from-blue-700 to-blue-500 px-6 pt-6 pb-4">
            <h1 className="text-white text-xl font-bold text-center mb-4">
              {modo === 'recuperar' ? 'Recuperar contraseña' : 'Mi cuenta'}
            </h1>
            {modo !== 'recuperar' && (
              <div className="flex bg-white/15 rounded-xl p-1">
                <button onClick={() => cambiarModo('login')}
                  className={`flex-1 py-2 rounded-lg text-sm font-semibold transition ${modo === 'login' ? 'bg-white text-blue-700' : 'text-white'}`}>
                  Iniciar sesión
                </button>
                <button onClick={() => cambiarModo('registro')}
                  className={`flex-1 py-2 rounded-lg text-sm font-semibold transition ${modo === 'registro' ? 'bg-white text-blue-700' : 'text-white'}`}>
                  Crear cuenta
                </button>
              </div>
            )}
          </div>

          {/* Formulario */}
          <form onSubmit={submit} className="px-6 py-6 space-y-4">
            {error && (
              <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">{error}</div>
            )}
            {ok && (
              <div className="rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-700">{ok}</div>
            )}

            {modo === 'registro' && (
              <>
                <Campo icono="👤" placeholder="Nombre completo" value={form.name} onChange={set('name')} required />
                <div className="flex gap-2">
                  <select value={form.tipo_documento} onChange={set('tipo_documento')}
                    className="border-b border-slate-200 focus:border-blue-500 text-slate-700 text-sm bg-transparent py-1.5 focus:outline-none">
                    <option value="CC">CC</option>
                    <option value="CE">CE</option>
                    <option value="NIT">NIT</option>
                    <option value="PAS">Pasaporte</option>
                  </select>
                  <div className="flex-1">
                    <Campo icono="🪪" placeholder="Número de documento" value={form.numero_documento} onChange={set('numero_documento')} />
                  </div>
                </div>
                <Campo icono="📱" placeholder="Celular" value={form.telefono} onChange={set('telefono')} />
                <Campo icono="🏪" placeholder="Nombre de tu negocio" value={form.nombre_empresa} onChange={set('nombre_empresa')} />
                <div className="flex items-center gap-2 border-b border-slate-200 focus-within:border-blue-500 transition pb-1">
                  <span className="text-slate-400 text-sm">🧰</span>
                  <select value={form.tipo_negocio_id} onChange={set('tipo_negocio_id')}
                    className="w-full py-1.5 text-slate-800 bg-transparent focus:outline-none text-sm">
                    <option value="">Tipo de negocio…</option>
                    {tiposNegocio.map((t) => <option key={t.id} value={t.id}>{t.nombre}</option>)}
                  </select>
                </div>
              </>
            )}

            <Campo icono="✉️" type="email" placeholder="Correo electrónico" value={form.email} onChange={set('email')} required />

            {modo !== 'recuperar' && (
              <Campo icono="🔒" type="password" placeholder="Contraseña" value={form.password} onChange={set('password')} required />
            )}
            {modo === 'registro' && (
              <Campo icono="🔒" type="password" placeholder="Repite la contraseña" value={form.password_confirmation} onChange={set('password_confirmation')} required />
            )}

            {modo === 'login' && (
              <div className="text-right">
                <button type="button" onClick={() => cambiarModo('recuperar')}
                  className="text-xs text-blue-600 hover:underline">¿Olvidaste tu contraseña?</button>
              </div>
            )}

            <button type="submit" disabled={enviando}
              className="w-full rounded-xl bg-gradient-to-r from-blue-700 to-blue-500 hover:opacity-95 disabled:opacity-50 text-white font-semibold py-2.5 shadow-lg transition">
              {enviando ? 'Procesando…' : (modo === 'login' ? 'Entrar' : modo === 'registro' ? 'Registrarme' : 'Enviar enlace')}
            </button>

            {modo === 'recuperar' && (
              <div className="text-center">
                <button type="button" onClick={() => cambiarModo('login')}
                  className="text-xs text-blue-600 hover:underline">← Volver a iniciar sesión</button>
              </div>
            )}
          </form>
        </div>

        <div className="text-center mt-5">
          <button type="button" onClick={() => navigate('/bienvenida')} className="text-slate-400 hover:text-white text-xs">← Volver al inicio</button>
        </div>
        <p className="text-center text-slate-500 text-xs mt-3">Logix · Plataforma de gestión</p>
      </div>
    </div>
  )
}

function Campo({ icono, ...props }) {
  return (
    <div className="flex items-center gap-2 border-b border-slate-200 focus-within:border-blue-500 transition pb-1">
      <span className="text-slate-400 text-sm">{icono}</span>
      <input {...props}
        className="w-full py-1.5 text-slate-800 placeholder-slate-400 focus:outline-none bg-transparent" />
    </div>
  )
}
