import { createContext, useContext, useEffect, useState } from 'react'
import { api, getToken, setToken } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [cargando, setCargando] = useState(true)

  // Al montar, si hay token guardado, recupera el usuario.
  useEffect(() => {
    async function init() {
      if (!getToken()) {
        setCargando(false)
        return
      }
      try {
        const me = await api('/me')
        setUser(me)
      } catch {
        setToken(null)
      } finally {
        setCargando(false)
      }
    }
    init()
  }, [])

  async function login(email, password) {
    const data = await api('/login', { method: 'POST', body: { email, password } })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }

  async function register(payload) {
    const data = await api('/register', { method: 'POST', body: payload })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }

  async function forgotPassword(email) {
    return api('/forgot-password', { method: 'POST', body: { email } })
  }

  async function resetPassword(payload) {
    return api('/reset-password', { method: 'POST', body: payload })
  }

  async function logout() {
    try {
      await api('/logout', { method: 'POST' })
    } catch {
      // ignorar errores de red al cerrar sesión
    }
    setToken(null)
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ user, setUser, cargando, login, register, forgotPassword, resetPassword, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de <AuthProvider>')
  return ctx
}
