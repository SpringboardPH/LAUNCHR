import { useState, useEffect } from 'react'
import { useAuth } from '../../store/AuthContext'
import { useNavigate } from 'react-router-dom'
import { Spinner } from '../../components/ui/index.jsx'
import { Eye, EyeOff } from 'lucide-react'
import axios from 'axios'

export default function LoginPage() {
  const { login, user, loading } = useAuth()
  const navigate = useNavigate()
  
  // Step 1: Credentials
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [rememberMe, setRememberMe] = useState(false)
  
  // Step 2: OTP
  const [otp, setOtp] = useState('')
  const [userId, setUserId] = useState(null)
  
  // State
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [step, setStep] = useState('credentials') // 'credentials' or 'otp'

  // Initialize state from sessionStorage on mount
  useEffect(() => {
    const savedStep = sessionStorage.getItem('login_step')
    const savedUserId = sessionStorage.getItem('login_user_id')
    const savedEmail = sessionStorage.getItem('login_email')
    
    if (savedStep === 'otp' && savedUserId && savedEmail) {
      setStep('otp')
      setUserId(savedUserId)
      setEmail(savedEmail)
    }
  }, [])

  // Persist step and userId to sessionStorage when they change
  useEffect(() => {
    if (step === 'otp' && userId && email) {
      sessionStorage.setItem('login_step', 'otp')
      sessionStorage.setItem('login_user_id', userId)
      sessionStorage.setItem('login_email', email)
    } else {
      sessionStorage.removeItem('login_step')
      sessionStorage.removeItem('login_user_id')
      sessionStorage.removeItem('login_email')
    }
  }, [step, userId, email])

  // Redirect if already logged in (only after loading completes)
  useEffect(() => {
    if (!loading && user) {
      // Clear login session storage on successful redirect
      sessionStorage.removeItem('login_step')
      sessionStorage.removeItem('login_user_id')
      sessionStorage.removeItem('login_email')
      navigate('/', { replace: true })
    }
  }, [user, loading, navigate])

  const handleSubmitCredentials = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const response = await axios.post('/api/auth/request-otp', {
        email,
        password,
      })
      
      if (response.data.success) {
        setUserId(response.data.data.user_id)
        setStep('otp')
        setError('')
        setSubmitting(false)
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Login failed. Please try again.')
      setSubmitting(false)
    }
  }

  const handleSubmitOtp = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const response = await axios.post('/api/auth/verify-otp', {
        user_id: userId,
        code: otp,
        remember_me: rememberMe,
      })
      
      if (response.data.success) {
        // Clear login session storage on successful OTP verification
        sessionStorage.removeItem('login_step')
        sessionStorage.removeItem('login_user_id')
        sessionStorage.removeItem('login_email')
        
        const token = response.data.data.token
        // Store token in localStorage with the same key as AuthContext expects
        localStorage.setItem('hr_token', token)
        // Set the default Authorization header for future requests
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`
        // Reload page to let auth context pick up the token
        window.location.href = '/'
      }
    } catch (err) {
      setError(err.response?.data?.message || 'OTP verification failed. Please try again.')
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
          <img src="/synctalents.png" alt="Synctalents International" className="h-20 mb-3" />
        </div>

        <div className="card p-6">
          {step === 'credentials' ? (
            <>
              <h2 className="text-base font-semibold text-gray-900 mb-5">Sign in to your account</h2>
              <form onSubmit={handleSubmitCredentials} className="space-y-4">
                <div>
                  <label className="label">Email address</label>
                  <input
                    type="email" className="input" placeholder="user@synctalents.com"
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

                <div className="flex items-center">
                  <input
                    type="checkbox"
                    id="rememberMe"
                    checked={rememberMe}
                    onChange={e => setRememberMe(e.target.checked)}
                    className="w-4 h-4 text-brand-600 bg-gray-100 border-gray-300 rounded cursor-pointer"
                  />
                  <label htmlFor="rememberMe" className="ml-2 text-sm text-gray-700 cursor-pointer">
                    Remember me for 30 days
                  </label>
                </div>

                {error && (
                  <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                    {error}
                  </div>
                )}

                <button type="submit" disabled={submitting} className="btn-primary w-full justify-center">
                  {submitting ? <Spinner size="sm" /> : 'Continue'}
                </button>
              </form>
            </>
          ) : (
            <>
              <h2 className="text-base font-semibold text-gray-900 mb-5">Verify your identity</h2>
              <div className="mb-4 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                <p className="text-sm text-blue-900">
                  We've sent a 6-digit code to <strong>{email}</strong>. Check your inbox and enter it below.
                </p>
              </div>
              
              <form onSubmit={handleSubmitOtp} className="space-y-4">
                <div>
                  <label className="label">Enter OTP Code</label>
                  <input
                    type="text"
                    className="input text-center text-2xl tracking-widest font-mono"
                    placeholder="000000"
                    value={otp}
                    onChange={e => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    maxLength="6"
                    required
                  />
                  <p className="text-xs text-gray-500 mt-2">Code expires in 10 minutes</p>
                </div>

                {error && (
                  <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                    {error}
                  </div>
                )}

                <div className="flex gap-3">
                  <button
                    type="button"
                    onClick={() => {
                      setStep('credentials')
                      setError('')
                      setOtp('')
                      sessionStorage.removeItem('login_step')
                      sessionStorage.removeItem('login_user_id')
                      sessionStorage.removeItem('login_email')
                    }}
                    disabled={submitting}
                    className="btn-secondary w-full justify-center"
                  >
                    Back
                  </button>
                  <button type="submit" disabled={submitting || otp.length !== 6} className="btn-primary w-full justify-center">
                    {submitting ? <Spinner size="sm" /> : 'Verify'}
                  </button>
                </div>
              </form>
            </>
          )}
        </div>
        <p className="text-center text-xs text-gray-400 mt-6">
          LAUNCHR &copy; {new Date().getFullYear()} made by aaron luyun
        </p>
      </div>
    </div>
  )
}

