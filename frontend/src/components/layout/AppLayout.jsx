import { Outlet, NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../../store/AuthContext'
import { useQuery } from '@tanstack/react-query'
import { getDashboard, dashboardKeys } from '../../api/queries'
import {
  LayoutDashboard, Users, Clock, CalendarOff,
  Banknote, LogOut, Menu, X, Settings, Building2, CalendarRange, UserCog, User, Sliders
} from 'lucide-react'
import { useState } from 'react'
import clsx from 'clsx'

const COMMON_NAV = [
  { to: '/hr', icon: LayoutDashboard, label: 'Dashboard', end: true },
  { to: '/hr/employees', icon: Users, label: 'Employees' },
  { to: '/hr/attendance', icon: Clock, label: 'Attendance' },
  { to: '/hr/employee-schedules', icon: CalendarRange, label: 'Schedules' },
  { to: '/hr/leaves', icon: CalendarOff, label: 'Leaves', badge: true },
  { to: '/hr/calendar', icon: CalendarRange, label: 'Calendar' },
  { to: '/hr/payroll', icon: Banknote, label: 'Payroll' },
]

const ADMIN_ONLY_NAV = [
  { to: '/admin/system-settings', icon: Settings, label: 'System Settings' },
  { to: '/admin/configure-leave', icon: Sliders, label: 'Configure Leave' },
  { to: '/admin/calendar-event-types', icon: CalendarRange, label: 'Configure Calendar' },
  { to: '/admin/users', icon: UserCog, label: 'User Management' },
  { to: '/admin/departments', icon: Building2, label: 'Departments' },
  { to: '/admin/schedule-templates', icon: CalendarRange, label: 'Schedule Templates' },
]

const EMPLOYEE_NAV = [
  { to: '/employee', icon: LayoutDashboard, label: 'My Dashboard', end: true },
  { to: '/employee/attendance', icon: Clock, label: 'My Attendance' },
  { to: '/employee/leaves/new', icon: CalendarOff, label: 'Request Leave' },
  { to: '/employee/calendar', icon: CalendarRange, label: 'Company Calendar' },
  { to: '/employee/profile', icon: User, label: 'My Profile' },
]

export default function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const { data: dashboardData } = useQuery({
    queryKey: dashboardKeys.all,
    queryFn: () => getDashboard(),
    enabled: !!user && ['admin', 'hr'].includes(user.role),
    staleTime: 60_000,
  })

  const pendingLeavesCount = dashboardData?.summary?.pending_leaves ?? 0

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
          <div className="w-7 h-7 bg-brand-600 rounded-lg flex items-center justify-center">
            <span className="text-white text-xs font-bold">HR</span>
          </div>
          <span className="font-semibold text-gray-900 text-sm">Springboard Philippines</span>
          <button className="ml-auto lg:hidden" onClick={() => setOpen(false)}>
            <X size={16} className="text-gray-400" />
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
          {user?.role === 'hr' ? (
            <>
              <div className="px-3 py-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Self Service</p>
              </div>
              {EMPLOYEE_NAV.map(({ to, icon: Icon, label, end }) => (
                <NavLink
                  key={to} to={to} end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700 font-medium'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  {label}
                </NavLink>
              ))}
              
              <div className="pt-5 mt-4 border-t border-gray-100 px-3 pb-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Management</p>
              </div>
              {COMMON_NAV.map(({ to, icon: Icon, label, end, badge }) => (
                <NavLink
                  key={to} to={to} end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700 font-medium'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  <span className="flex-1">{label}</span>
                  {badge && pendingLeavesCount > 0 && (
                    <span className="flex items-center justify-center min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full">
                      {pendingLeavesCount}
                    </span>
                  )}
                </NavLink>
              ))}
            </>
          ) : (
            <>
              <div className="px-3 py-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Management</p>
              </div>
              {COMMON_NAV.map(({ to, icon: Icon, label, end, badge }) => (
                <NavLink
                  key={to} to={to} end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700 font-medium'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  <span className="flex-1">{label}</span>
                  {badge && pendingLeavesCount > 0 && (
                    <span className="flex items-center justify-center min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full">
                      {pendingLeavesCount}
                    </span>
                  )}
                </NavLink>
              ))}

              <div className="pt-5 mt-4 border-t border-gray-100 px-3 pb-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">System</p>
              </div>
              {ADMIN_ONLY_NAV.map(({ to, icon: Icon, label, end }) => (
                <NavLink
                  key={to} to={to} end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700 font-medium'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  {label}
                </NavLink>
              ))}
            </>
          )}
        </nav>

        {/* User + logout */}
        <div className="px-4 py-4 border-t border-gray-200">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xs font-semibold">
              {user?.name?.charAt(0).toUpperCase()}
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-900 truncate">{user?.name}</p>
              <p className="text-xs text-gray-500 truncate">
                {user?.role === 'admin' ? 'Administrator' : 'Human Resources'}
              </p>
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
          <span className="font-semibold text-sm text-gray-900">Springboard Philippines</span>
        </header>

        <main className="flex-1 overflow-y-auto p-5 lg:p-7">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
