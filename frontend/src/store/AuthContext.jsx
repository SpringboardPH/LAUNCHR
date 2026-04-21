import { createContext, useContext, useState, useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import api from '../api/axios'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const queryClient = useQueryClient()

  useEffect(() => {
    const token = localStorage.getItem('hr_token')
    if (token) {
      api.defaults.headers.common['Authorization'] = `Bearer ${token}`
      api.get('/user')
        .then(res => setUser(res.data.data))
        .catch(() => {
          localStorage.removeItem('hr_token')
          delete api.defaults.headers.common['Authorization']
        })
        .finally(() => setLoading(false))
    } else {
      setLoading(false)
    }
  }, [])

  const login = async (email, password) => {
    const res = await api.post('/login', { email, password })
    const { token, user } = res.data.data
    localStorage.setItem('hr_token', token)
    api.defaults.headers.common['Authorization'] = `Bearer ${token}`
    
    // Clear any cached data from previous sessions before setting the new user
    queryClient.clear()
    
    setUser(user)
    return user
  }

  const logout = async () => {
    try { await api.post('/logout') } catch {}
    localStorage.removeItem('hr_token')
    delete api.defaults.headers.common['Authorization']
    
    // Clear cache on logout
    queryClient.clear()
    
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
