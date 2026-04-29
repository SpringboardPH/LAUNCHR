import { useState, useEffect } from 'react'
import { useAuth } from '../../store/AuthContext'
import { useNavigate } from 'react-router-dom'
import { Spinner } from '../../components/ui/index.jsx'
import { Eye, EyeOff } from 'lucide-react'

export default function LoginPage() {
  const { login, user, loading } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('admin@hr.com')
  const [password, setPassword] = useState('password')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  // Redirect if already logged in (only after loading completes)
  useEffect(() => {
    if (!loading && user) {
      // Navigate to root to let App.jsx handle the specific role-based routing
      navigate('/', { replace: true })
    }
  }, [user, loading, navigate])

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await login(email, password)
      // Navigate to root to let App.jsx handle the specific role-based routing
      navigate('/')
    } catch (err) {
      setError(err.response?.data?.message || 'Login failed. Please try again.')
      setSubmitting(false)
    }
  }

  // Only show loading during initial auth check, not after login submit
  if (loading && !submitting) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        {/* Logo */}
        <div className="flex flex-col items-center mb-8">
          <div className="w-12 h-12 bg-brand-600 rounded-xl flex items-center justify-center mb-3">
            <span className="text-white font-bold text-lg">HR</span>
          </div>
          <h1 className="text-xl font-semibold text-gray-900">Springboard Philippines</h1>
        </div>

        <div className="card p-6">
          <h2 className="text-base font-semibold text-gray-900 mb-5">Sign in to your account</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">Email address</label>
              <input
                type="email" className="input" placeholder="admin@hr.com"
                value={email} onChange={e => setEmail(e.target.value)} required
              />
            </div>
            <div>
              <label className="label">Password</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  className="input pr-10"
                  placeholder="••••••••"
                  value={password}
                  onChange={e => setPassword(e.target.value)}
                  required
                />
                <button
                  type="button"
                  className="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 transition"
                  onClick={() => setShowPassword(!showPassword)}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            {error && (
              <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                {error}
              </div>
            )}

            <button type="submit" disabled={submitting} className="btn-primary w-full justify-center">
              {submitting ? <Spinner size="sm" /> : 'Sign in'}
            </button>
          </form>
        </div>
        <p className="text-center text-xs text-gray-400 mt-6">
          LAUNCHR &copy; {new Date().getFullYear()} made by aaron luyun
        </p>
      </div>
    </div>
  )
}
