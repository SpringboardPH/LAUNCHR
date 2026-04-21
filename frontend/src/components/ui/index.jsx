import { X } from 'lucide-react'
import clsx from 'clsx'

// ─── PageHeader ───────────────────────────────────────────────
export function PageHeader({ title, description, action }) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between mb-5">
      <div className="min-w-0">
        <h1 className="text-lg font-semibold text-gray-900 leading-tight">{title}</h1>
        {description && <p className="text-sm text-gray-500 mt-1 max-w-2xl">{description}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

// ─── StatCard ─────────────────────────────────────────────────
export function StatCard({ label, value, icon: Icon, color = 'brand', sub }) {
  const colors = {
    brand:  'bg-brand-50 text-brand-600',
    blue:   'bg-blue-50 text-blue-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    red:    'bg-red-50 text-red-600',
    gray:   'bg-gray-100 text-gray-600',
  }
  return (
    <div className="card p-4 flex items-start gap-4">
      <div className={clsx('w-10 h-10 rounded-lg flex items-center justify-center shrink-0', colors[color])}>
        <Icon size={18} />
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
        <p className="text-xl font-semibold text-gray-900 mt-0.5">{value}</p>
        {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
      </div>
    </div>
  )
}

// ─── Spinner ──────────────────────────────────────────────────
export function Spinner({ size = 'md' }) {
  const sizes = { sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-10 h-10' }
  return (
    <div className={clsx('border-2 border-brand-600 border-t-transparent rounded-full animate-spin', sizes[size])} />
  )
}

export function PageSpinner() {
  return (
    <div className="flex items-center justify-center h-64">
      <Spinner size="lg" />
    </div>
  )
}

// ─── EmptyState ───────────────────────────────────────────────
export function EmptyState({ icon: Icon, title, description, action }) {
  return (
    <div className="flex flex-col items-center justify-center py-14 text-center">
      {Icon && <Icon size={36} className="text-gray-300 mb-4" />}
      <p className="text-sm font-medium text-gray-600">{title}</p>
      {description && <p className="text-xs text-gray-400 mt-1">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

// ─── Modal ────────────────────────────────────────────────────
export function Modal({ open, onClose, title, children, size = 'md' }) {
  if (!open) return null
  const sizes = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className={clsx('relative bg-white rounded-xl shadow-xl w-full', sizes[size])}>
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
          <button onClick={onClose} className="btn-ghost p-1.5">
            <X size={16} />
          </button>
        </div>
        <div className="px-5 py-4">{children}</div>
      </div>
    </div>
  )
}

// ─── FormField ────────────────────────────────────────────────
export function FormField({ label, error, children, required }) {
  return (
    <div>
      <label className="label text-xs font-medium text-gray-600 mb-1">
        {label} {required && <span className="text-red-500 ml-0.5">*</span>}
      </label>
      {children}
      {error && <p className="text-xs text-red-500 mt-1">{error}</p>}
    </div>
  )
}

// ─── Badge helpers ────────────────────────────────────────────
export function StatusBadge({ status }) {
  const map = {
    active:      'badge-green',
    inactive:    'badge-gray',
    terminated:  'badge-red',
    present:     'badge-green',
    late:        'badge-yellow',
    absent:      'badge-red',
    pending:     'badge-yellow',
    approved:    'badge-green',
    rejected:    'badge-red',
    draft:       'badge-gray',
    finalized:   'badge-blue',
    full_time:   'badge-blue',
    part_time:   'badge-yellow',
    contractual: 'badge-gray',
  }
  const label = status?.replace(/_/g, ' ')
  return <span className={map[status] ?? 'badge-gray'}>{label}</span>
}
