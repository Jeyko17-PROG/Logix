import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

const CARACTERISTICAS = [
  { icon: '👥', label: 'Clientes' },
  { icon: '📦', label: 'Inventario' },
  { icon: '🧾', label: 'Facturación' },
  { icon: '📅', label: 'Reservas' },
]

// Pantalla de bienvenida (entrada pública de la app). Permite elegir entre
// Iniciar Sesión y Registrarse, sin reemplazar los formularios existentes.
export default function Bienvenida() {
  const navigate = useNavigate()
  const { user, cargando } = useAuth()

  // Si ya hay sesión activa, ir directo al sistema.
  useEffect(() => {
    if (!cargando && user) navigate('/', { replace: true })
  }, [user, cargando, navigate])

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 px-4 py-10">
      <div className="w-full max-w-md text-center">
        {/* Logo */}
        <img
          src="/logo.svg"
          alt="Logix"
          className="h-24 w-24 mx-auto object-contain drop-shadow-2xl"
          onError={(e) => { e.currentTarget.style.display = 'none' }}
        />

        {/* Nombre y descripción */}
        <h1 className="mt-5 text-4xl font-extrabold tracking-wide text-white">LOGIX</h1>
        <p className="mt-3 text-slate-300 text-base leading-relaxed">
          Sistema de Gestión de Clientes, Inventario, Facturación y Reservas.
        </p>

        {/* Mini-características */}
        <div className="mt-7 grid grid-cols-4 gap-2">
          {CARACTERISTICAS.map((c) => (
            <div key={c.label} className="rounded-xl bg-white/5 border border-white/10 py-3">
              <div className="text-2xl">{c.icon}</div>
              <div className="text-[11px] text-slate-400 mt-1">{c.label}</div>
            </div>
          ))}
        </div>

        {/* Botones principales */}
        <div className="mt-9 space-y-3">
          <button
            onClick={() => navigate('/login')}
            className="w-full rounded-xl bg-gradient-to-r from-blue-700 to-blue-500 hover:opacity-95 text-white font-semibold py-3 shadow-lg transition"
          >
            Iniciar Sesión
          </button>
          <button
            onClick={() => navigate('/login?modo=registro')}
            className="w-full rounded-xl bg-white/10 hover:bg-white/15 border border-white/20 text-white font-semibold py-3 transition"
          >
            Registrarse
          </button>
        </div>

        <p className="mt-8 text-slate-500 text-xs">Logix · Plataforma de gestión</p>
      </div>
    </div>
  )
}
