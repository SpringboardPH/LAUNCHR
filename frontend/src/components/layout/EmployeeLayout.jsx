import { Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../../store/AuthContext'
import { useQuery } from '@tanstack/react-query'
import { getSystemConfig, systemConfigKeys } from '../../api/queries'
import { LogOut, Menu, X, LayoutDashboard, Clock, CalendarOff, User, CalendarRange } from 'lucide-react'
import { useState } from 'react'
import clsx from 'clsx'

const NAV = [
  { to: '/employee', icon: LayoutDashboard, label: 'Dashboard', end: true },
  { to: '/employee/attendance', icon: Clock, label: 'Attendance' },
  { to: '/employee/schedule', icon: CalendarRange, label: 'My Schedule' },
  { to: '/employee/leaves/new', icon: CalendarOff, label: 'Request Leave' },
  { to: '/employee/calendar', icon: CalendarRange, label: 'Company Calendar' },
  { to: '/employee/profile', icon: User, label: 'My Profile' },
]

export default function EmployeeLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const { data: systemConfig } = useQuery({
    queryKey: systemConfigKeys.all,
    queryFn: getSystemConfig,
    staleTime: Infinity,
  })

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div className="flex h-screen bg-gray-50 overflow-hidden">
      {/* Mobile overlay */}
      {open && (
        <div className="fixed inset-0 z-20 bg-black/40 lg:hidden" onClick={() => setOpen(false)} />
      )}

      {/* Sidebar */}
      <aside className={clsx(
        'fixed inset-y-0 left-0 z-30 w-60 bg-white border-r border-gray-200 flex flex-col transition-transform lg:translate-x-0 lg:static lg:z-auto',
        open ? 'translate-x-0' : '-translate-x-full'
      )}>
        {/* Logo */}
        <div className="flex items-center gap-2 px-5 py-4 border-b border-gray-200">
          <span className="font-semibold text-gray-900 text-sm">{systemConfig?.system_name || 'LAUNCHR'}</span>
          <button className="ml-auto lg:hidden" onClick={() => setOpen(false)}>
            <X size={16} className="text-gray-400" />
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
          <div className="px-3 py-2">
            <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Self Service</p>
          </div>
          {NAV.map(({ to, icon: Icon, label }) => (
            <a
              key={to} href={to} onClick={(e) => {
                e.preventDefault()
                navigate(to)
                setOpen(false)
              }}
              className={clsx(
                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                window.location.pathname === to
                  ? 'bg-brand-50 text-brand-700 font-medium'
                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
              )}
            >
              <Icon size={16} />
              {label}
            </a>
          ))}
        </nav>

        {/* User + logout */}
        <div className="px-4 py-4 border-t border-gray-200">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xs font-semibold">
              {user?.name?.charAt(0).toUpperCase()}
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-900 truncate">{user?.name}</p>
              <p className="text-xs text-gray-500 truncate">Employee</p>
            </div>
          </div>
          <button onClick={handleLogout} className="btn-ghost w-full justify-start text-xs">
            <LogOut size={14} /> Sign out
          </button>
        </div>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Mobile topbar */}
        <header className="lg:hidden flex items-center gap-3 px-4 py-3 bg-white border-b border-gray-200">
          <button onClick={() => setOpen(true)}>
            <Menu size={20} className="text-gray-600" />
          </button>
          <span className="font-semibold text-sm text-gray-900">{systemConfig?.system_name || 'LAUNCHR'}</span>
        </header>

        <main className="flex-1 overflow-y-auto p-5 lg:p-7">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
