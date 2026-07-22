import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useFeatures } from '../context/FeaturesContext'
import { api } from '../api/client'

function Campana() {
  const [abierto, setAbierto] = useState(false)
  const [items, setItems] = useState([])
  const [noLeidas, setNoLeidas] = useState(0)

  async function cargar() {
    try {
      const [lista, count] = await Promise.all([api('/notificaciones'), api('/notificaciones/no-leidas')])
      setItems(lista); setNoLeidas(count.no_leidas)
    } catch { /* ignore */ }
  }
  useEffect(() => {
    cargar()
    const t = setInterval(cargar, 30000) // refresca cada 30s
    return () => clearInterval(t)
  }, [])

  async function abrir() {
    setAbierto(!abierto)
    if (!abierto && noLeidas > 0) {
      await api('/notificaciones/marcar-leidas', { method: 'POST' })
      setNoLeidas(0)
    }
  }

  return (
    <div className="relative">
      <button onClick={abrir} className="relative p-2 rounded-lg hover:bg-slate-800 text-lg">🔔
        {noLeidas > 0 && <span className="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs rounded-full px-1.5">{noLeidas}</span>}
      </button>
      {abierto && (
        <div className="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-slate-800 border border-slate-700 rounded-xl shadow-xl z-50">
          {items.length === 0 && <p className="p-4 text-slate-500 text-sm">Sin notificaciones.</p>}
          {items.map((n) => (
            <div key={n.id} className="p-3 border-b border-slate-700 text-sm">
              <p className="font-medium">{n.titulo}</p>
              {n.mensaje && <p className="text-slate-400 text-xs">{n.mensaje}</p>}
              <p className="text-slate-600 text-xs mt-1">{new Date(n.created_at).toLocaleString('es')}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// Menú organizado por secciones (sectorizado) para una navegación más clara.
const MENU = [
  {
    grupo: 'Principal',
    items: [
      { to: '/', label: 'Dashboard', icon: '📊', end: true },
    ],
  },
  {
    grupo: 'Agenda',
    items: [
      { to: '/agenda', label: 'Agenda y Citas', icon: '📅', feat: 'agenda' },
      { to: '/qr', label: 'QR Reservas', icon: '🔲', feat: 'qr' },
    ],
  },
  {
    grupo: 'Taller y POS',
    items: [
      { to: '/taller', label: 'Taller / Órdenes', icon: '🔧', feat: 'servicios', featPorTipo: { lavadero: 'lavadero', barberia: 'barberia' } },
      { to: '/planes-lavado', label: 'Planes de Lavado', icon: '🧼', feat: 'lavadero', soloTipo: 'lavadero' },
      { to: '/servicios', label: 'Servicios', icon: '💈', feat: 'agenda' },
      { to: '/restaurante', label: 'Mesas y Comandas', icon: '🍽️', feat: 'mesas' },
      { to: '/caja', label: 'Caja y Gastos', icon: '💵', feat: 'caja' },
    ],
  },
  {
    grupo: 'Clientes y Ventas',
    items: [
      { to: '/clientes', label: 'Clientes', icon: '👥', feat: 'clientes' },
      { to: '/facturacion', label: 'Facturación', icon: '🧾', feat: 'facturacion' },
    ],
  },
  {
    grupo: 'Inventario',
    items: [
      { to: '/productos', label: 'Productos', icon: '📦', feat: 'productos' },
      { to: '/inventario', label: 'Inventario', icon: '🏷️', feat: 'inventario' },
      { to: '/bodegas', label: 'Bodegas', icon: '🏭', feat: 'inventario' },
      { to: '/proveedores', label: 'Proveedores', icon: '🚚', feat: 'proveedores' },
      { to: '/compras', label: 'Compras', icon: '🛒', feat: 'proveedores' },
    ],
  },
  {
    grupo: 'Documentos',
    items: [
      { to: '/documentos', label: 'Documentos', icon: '📁', feat: 'documental' },
      { to: '/reportes', label: 'Reportes', icon: '📈', feat: 'reportes' },
    ],
  },
  {
    grupo: 'Herramientas',
    items: [
      { to: '/notas', label: 'Bloc de Notas', icon: '📝', feat: 'notas' },
      { to: '/calculadora', label: 'Calculadora', icon: '🧮', feat: 'calculadora' },
      { to: '/notificaciones', label: 'Notificaciones', icon: '🔔', feat: 'notificaciones' },
    ],
  },
  {
    grupo: 'Cuenta',
    items: [
      { to: '/perfil', label: 'Mi Perfil', icon: '🙍' },
      { to: '/planes', label: 'Planes', icon: '💳' },
      { to: '/configuracion', label: 'Configuración', icon: '⚙️' },
    ],
  },
]

// Rutas permitidas para el rol Mecanico: solo su trabajo, sin dinero ni facturación.
const RUTAS_MECANICO = ['/', '/taller', '/productos', '/notificaciones', '/perfil']

// Sección visible únicamente para el Super Administrador.
const MENU_SUPER_ADMIN = {
  grupo: 'Plataforma (Super Admin)',
  items: [
    { to: '/empresas', label: 'Empresas', icon: '🏢' },
    { to: '/usuarios', label: 'Usuarios Registrados', icon: '👤' },
    { to: '/licencias', label: 'Administración de Licencias', icon: '🔑' },
    { to: '/funcionalidades', label: 'Control de Funcionalidades', icon: '🧩' },
    { to: '/auditoria', label: 'Auditoría', icon: '📜' },
  ],
}

export default function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [abierto, setAbierto] = useState(false)
  // Sidebar colapsable en escritorio (más espacio para tablas de Inventario/Agenda). Se recuerda entre sesiones.
  const [isCollapsed, setIsCollapsed] = useState(() => localStorage.getItem('logix_sidebar_collapsed') === '1')
  useEffect(() => {
    localStorage.setItem('logix_sidebar_collapsed', isCollapsed ? '1' : '0')
  }, [isCollapsed])

  useEffect(() => {
    const onResize = () => {
      if (window.innerWidth >= 768) {
        setAbierto(false)
      }
    }
    onResize()
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])

  useEffect(() => {
    document.body.style.overflow = abierto ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [abierto])

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  const linkClass = ({ isActive }) =>
    `flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition ${
      isActive ? 'bg-emerald-600 text-white' : 'text-slate-300 hover:bg-slate-800'
    }`

  const { visible } = useFeatures()

  // El Super Administrador ve además la sección de plataforma.
  const base = user?.es_super_admin ? [...MENU, MENU_SUPER_ADMIN] : MENU
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave
  const esMecanico = user?.rol?.nombre === 'Mecanico'
  const esLavadorRol = user?.rol?.nombre === 'Lavador'
  const esOperarioRol = esMecanico || esLavadorRol
  // "Taller / Órdenes" cambia de nombre según el tipo de negocio (misma ruta /taller).
  const LABEL_POR_TIPO = {
    lavadero: { label: 'Servicios de Lavado', icon: '🧼' },
    barberia: { label: 'Barbería / Agenda', icon: '💈' },
  }
  // Oculta del menú las funcionalidades DESACTIVADAS para el usuario.
  // El Mecánico/Lavador solo ve sus órdenes y catálogo: nada de facturación ni dinero.
  const secciones = base
    .map((s) => ({
      ...s,
      items: s.items
        .filter((m) => {
          // Negocios lavadero/barbería: el módulo relevante es el propio, independiente de 'servicios' (talleres).
          const feat = (tipoNegocio && m.featPorTipo?.[tipoNegocio]) ? m.featPorTipo[tipoNegocio] : m.feat
          const permitidoPorTipo = !m.soloTipo || (Array.isArray(m.soloTipo) ? m.soloTipo.includes(tipoNegocio) : m.soloTipo === tipoNegocio)
          return (!feat || visible(feat)) && (!esOperarioRol || RUTAS_MECANICO.includes(m.to)) && permitidoPorTipo
        })
        .map((m) => (m.to === '/taller' && LABEL_POR_TIPO[tipoNegocio] ? { ...m, ...LABEL_POR_TIPO[tipoNegocio] } : m)),
    }))
    .filter((s) => s.items.length > 0)

  // Estado de cobro SaaS (viene en /me): saldo prepago o vencimiento de membresía.
  const saas = user?.facturacion_saas

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100">
      {/* Barra superior */}
      <header className="fixed top-0 inset-x-0 z-50 bg-slate-950 border-b border-slate-800 px-4 py-3">
        <div className="flex items-center gap-3">
          {/* Colapsar/expandir el sidebar en escritorio: más ancho para tablas de Inventario/Agenda. */}
          <button onClick={() => setIsCollapsed(!isCollapsed)} aria-label={isCollapsed ? 'Expandir menú' : 'Colapsar menú'}
            className="hidden md:flex p-2 rounded-lg text-slate-300 hover:bg-slate-800 hover:text-white text-lg leading-none">
            ☰
          </button>
          <img src="/logo.svg" alt="" className="h-7 w-7 object-contain"
            onError={(e) => { e.currentTarget.style.display = 'none' }} />
          <span className="font-bold text-lg">Logix</span>
          <div className="ml-auto flex items-center gap-2">
            {/* Saldo de la billetera (modo prepago) o aviso de membresía vencida */}
            {saas?.modo_cobro === 'prepago' && !user?.es_super_admin && (
              <NavLink to="/planes" className="hidden sm:flex items-center gap-1 text-xs rounded-full bg-sky-500/15 text-sky-300 px-3 py-1.5 hover:bg-sky-500/25">
                💰 {saas.creditos_facturacion ?? 0} facturas
              </NavLink>
            )}
            {saas?.membresia_vencida && !user?.es_super_admin && (
              <NavLink to="/planes?vencida=1" className="flex items-center gap-1 text-xs rounded-full bg-red-500/20 text-red-300 px-3 py-1.5 hover:bg-red-500/30">
                ⚠️ Membresía vencida
              </NavLink>
            )}
            <Campana />
            <span className="text-sm text-slate-400 hidden sm:block">{user?.name} · {user?.rol?.nombre ?? 'Sin rol'}</span>
            <button onClick={handleLogout} className="text-sm rounded-lg bg-slate-800 hover:bg-slate-700 px-3 py-1.5">Salir</button>
          </div>
        </div>

        <div className="mt-3 flex items-center gap-2 md:hidden">
          <button onClick={() => setAbierto(!abierto)} aria-label="Menú" aria-expanded={abierto} aria-controls="mobile-sidebar"
            className="flex items-center gap-2 p-2 rounded-lg bg-slate-800/80 text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-base leading-none">
            <span className="text-xl">☰</span>
            Menú
          </button>
          <button onClick={() => navigate('/')} aria-label="Volver al Dashboard" className="flex items-center gap-2 p-2 rounded-lg bg-slate-800/80 text-slate-200 hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 text-base leading-none">
            <span className="text-lg">←</span>
            Atrás
          </button>
        </div>
      </header>

      {/* Fondo oscuro al abrir el menú en móvil */}
      {abierto && <div onClick={() => setAbierto(false)} className="fixed inset-0 z-30 bg-black/50 md:hidden" />}

      {/* Menú lateral deslizante (oculto por defecto, se abre con el botón ☰) */}
      <aside id="mobile-sidebar" className={`fixed top-0 left-0 z-40 h-full w-72 max-w-[85vw] bg-slate-950 border-r border-slate-800 pt-28 md:pt-16 px-3 pb-4 overflow-y-auto transition-all duration-200 shadow-2xl
        ${abierto ? 'translate-x-0' : '-translate-x-full'}
        ${isCollapsed ? 'md:-translate-x-full md:w-0 md:px-0 md:border-0 md:overflow-hidden' : 'md:translate-x-0 md:w-64'} md:max-w-none`}>
        <div className="mb-3 flex items-center justify-between px-1 md:hidden">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Navegación</p>
          <button onClick={() => setAbierto(false)} className="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="Cerrar menú">✕</button>
        </div>
        <nav className="flex flex-col gap-1">
          {secciones.map((seccion) => (
            <div key={seccion.grupo} className="mb-2">
              <p className="px-4 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{seccion.grupo}</p>
              {seccion.items.map((m) => (
                <NavLink key={m.to} to={m.to} end={m.end} onClick={() => setAbierto(false)} className={linkClass}>
                  <span className="text-base">{m.icon}</span>{m.label}
                </NavLink>
              ))}
            </div>
          ))}

          {/* Cerrar sesión */}
          <button onClick={handleLogout}
            className="flex items-center gap-3 px-4 py-2.5 mt-2 rounded-lg text-sm font-medium text-red-300 hover:bg-red-500/10 transition">
            <span className="text-base">🚪</span>Cerrar Sesión
          </button>
        </nav>
      </aside>

      {/* Contenido: en escritorio deja espacio al sidebar fijo (o lo reclama si está colapsado); en móvil baja bajo el header de dos filas */}
      <main className={`pt-28 md:pt-14 transition-[padding] duration-200 ${isCollapsed ? 'md:pl-0' : 'md:pl-64'}`}>
        <div className={`mx-auto px-4 py-8 transition-[max-width] duration-200 ${isCollapsed ? 'max-w-full' : 'max-w-6xl'}`}>
          <Outlet />
        </div>
      </main>
    </div>
  )
}
