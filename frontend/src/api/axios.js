import axios from 'axios'

const normalizeApiBaseURL = (value) => {
  const trimmedValue = (value || '').trim().replace(/\/+$/, '')

  if (!trimmedValue) {
    return '/api'
  }

  return trimmedValue.endsWith('/api') ? trimmedValue : `${trimmedValue}/api`
}

const baseURL = normalizeApiBaseURL(import.meta.env.VITE_API_BASE_URL)
const baseBackendURL = baseURL.replace(/\/api$/, '')

const api = axios.create({
  baseURL,
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
  withCredentials: false,
})

// Helper to get CSRF token (calls the root endpoint, not /api)
export const getCsrfToken = async () => {
  try {
    await axios.get(`${baseBackendURL}/sanctum/csrf-cookie`, { withCredentials: true })
  } catch (err) {
    console.error('CSRF token fetch failed:', err)
  }
}

// Attach stored token on every request
api.interceptors.request.use(config => {
  const token = localStorage.getItem('hr_token')
  if (token) config.headers['Authorization'] = `Bearer ${token}`
  return config
})

// Redirect to login on 401
api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('hr_token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default api
