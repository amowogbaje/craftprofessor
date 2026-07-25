import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { fetchCurrentUser, logout as apiLogout } from '@/lib/api-auth'
import { AUTH_LOGOUT_EVENT, clearToken, getToken, setToken as persistToken } from '@/lib/token'
import type { User } from '@/lib/types'

interface AuthContextValue {
  user: User | null
  isLoading: boolean
  isAuthenticated: boolean
  login: (token: string, user: User) => void
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const refreshUser = useCallback(async () => {
    if (!getToken()) {
      setUser(null)
      return
    }
    try {
      const freshUser = await fetchCurrentUser()
      setUser(freshUser)
    } catch {
      clearToken()
      setUser(null)
    }
  }, [])

  useEffect(() => {
    refreshUser().finally(() => setIsLoading(false))
  }, [refreshUser])

  useEffect(() => {
    const onUnauthorized = () => setUser(null)
    window.addEventListener(AUTH_LOGOUT_EVENT, onUnauthorized)
    return () => window.removeEventListener(AUTH_LOGOUT_EVENT, onUnauthorized)
  }, [])

  const login = useCallback((token: string, nextUser: User) => {
    persistToken(token)
    setUser(nextUser)
  }, [])

  const logout = useCallback(async () => {
    try {
      await apiLogout()
    } catch {
      // Token may already be invalid — clear local state regardless.
    }
    clearToken()
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, isLoading, isAuthenticated: !!user, login, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
