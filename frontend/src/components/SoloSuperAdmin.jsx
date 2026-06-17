import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

/** Restringe una ruta al Super Administrador. Los demás se redirigen al inicio. */
export default function SoloSuperAdmin({ children }) {
  const { user } = useAuth()
  if (!user?.es_super_admin) return <Navigate to="/" replace />
  return children
}
