import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../store/AuthContext'
import { useQuery } from '@tanstack/react-query'
import { getDashboard, dashboardKeys, getSystemConfig, systemConfigKeys } from '../../api/queries'
import {
  LayoutDashboard, Users, Clock,
  Banknote, LogOut, Menu, X, Settings, Building2, CalendarRange, UserCog, User, Sliders, History, FileText, ClipboardList, ChevronDown,
} from 'lucide-react'
import { useState, useEffect } from 'react'
import clsx from 'clsx'

const COMMON_NAV = [
  { to: '/hr', icon: LayoutDashboard, label: 'Dashboard', end: true },
  { to: '/hr/employees', icon: Users, label: 'Employees' },
  {
    icon: Clock,
    label: 'Attendance',
    children: [
      { to: '/hr/attendance', label: 'Attendance Logs' },
      { to: '/hr/dtr', label: 'DTR Management', dtrGated: true },
    ],
  },
  {
    icon: CalendarRange,
    label: 'Schedules',
    children: [
      { to: '/hr/employee-schedules', label: 'Employee Schedules' },
      { to: '/hr/schedule-templates', label: 'Templates' },
    ],
  },
  { to: '/hr/requests', icon: ClipboardList, label: 'Requests', badge: true },
  { to: '/hr/calendar', icon: CalendarRange, label: 'Calendar' },
  {
    icon: Banknote,
    label: 'Payroll',
    children: [
      { to: '/hr/payroll', label: 'Payroll Runs' },
      { to: '/hr/thirteenth-month', label: '13th Month' },
    ],
  },
]

const ADMIN_ONLY_NAV = [
  { to: '/admin/system-settings', icon: Settings, label: 'System Settings' },
  { to: '/admin/attendance-logs', icon: FileText, label: 'Attendance Logs' },
  { to: '/admin/audit-logs', icon: History, label: 'Audit Logs' },
  { to: '/admin/configure-leave', icon: Sliders, label: 'Configure Leave' },
  { to: '/admin/calendar-event-types', icon: CalendarRange, label: 'Configure Calendar' },
  { to: '/admin/users', icon: UserCog, label: 'User Management' },
  { to: '/admin/departments', icon: Building2, label: 'Departments' },
]

const EMPLOYEE_NAV = [
  { to: '/employee', icon: LayoutDashboard, label: 'My Dashboard', end: true },
  { to: '/employee/attendance', icon: Clock, label: 'My Attendance' },
  { to: '/employee/requests/new', icon: ClipboardList, label: 'My Requests' },
  { to: '/employee/calendar', icon: CalendarRange, label: 'Company Calendar' },
  { to: '/employee/profile', icon: User, label: 'My Profile' },
]

export default function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [openGroups, setOpenGroups] = useState(
    () => new Set(COMMON_NAV.filter(i => i.children?.some(c => location.pathname.startsWith(c.to))).map(i => i.label))
  )

  const { data: systemConfig } = useQuery({
    queryKey: systemConfigKeys.all,
    queryFn: getSystemConfig,
    staleTime: Infinity,
  })

  const { data: dashboardData } = useQuery({
    queryKey: dashboardKeys.all,
    queryFn: () => getDashboard(),
    enabled: !!user && ['admin', 'hr', 'accounting'].includes(user.role),
    staleTime: 60_000,
  })

  const pendingBadgeCount = (dashboardData?.summary?.pending_leaves ?? 0) + (dashboardData?.summary?.pending_requests ?? 0)

  useEffect(() => {
    setOpenGroups(prev => {
      const next = new Set(prev)
      COMMON_NAV.forEach(item => {
        if (item.children?.some(c => location.pathname.startsWith(c.to))) next.add(item.label)
      })
      return next
    })
  }, [location.pathname])

  const toggleGroup = (label) =>
    setOpenGroups(prev => { const n = new Set(prev); n.has(label) ? n.delete(label) : n.add(label); return n })

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const renderNavItem = (item) => {
    if (item.dtrGated && !systemConfig?.dtr_page_enabled) return null
    if (item.children) {
      const visibleChildren = item.children.filter(c => !c.dtrGated || systemConfig?.dtr_page_enabled)
      if (!visibleChildren.length) return null
      const isOpen = openGroups.has(item.label)
      const isGroupActive = visibleChildren.some(c => location.pathname.startsWith(c.to))
      const Icon = item.icon
      return (
        <div key={item.label}>
          <button
            onClick={() => toggleGroup(item.label)}
            className={clsx(
              'w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
              isGroupActive ? 'text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
            )}
          >
            <Icon size={16} />
            <span className="flex-1 text-left">{item.label}</span>
            <ChevronDown size={13} className={clsx('transition-transform duration-150', isOpen ? '' : '-rotate-90')} />
          </button>
          {isOpen && (
            <div className="ml-6 mt-0.5 space-y-0.5 border-l border-gray-100 pl-2.5">
              {visibleChildren.map(child => (
                <NavLink
                  key={child.to}
                  to={child.to}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center px-2 py-1.5 rounded-md text-sm transition-colors',
                    isActive ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'
                  )}
                >
                  {child.label}
                </NavLink>
              ))}
            </div>
          )}
        </div>
      )
    }
    const { to, icon: Icon, label, end, badge } = item
    return (
      <NavLink
        key={to} to={to} end={end}
        onClick={() => setOpen(false)}
        className={({ isActive }) => clsx(
          'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
          isActive ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
        )}
      >
        <Icon size={16} />
        <span className="flex-1">{label}</span>
        {badge && pendingBadgeCount > 0 && (
          <span className="flex items-center justify-center min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full">
            {pendingBadgeCount}
          </span>
        )}
      </NavLink>
    )
  }

  return (
    <div className="flex h-screen bg-gray-50 overflow-hidden">
      {open && (
        <div className="fixed inset-0 z-20 bg-black/40 lg:hidden" onClick={() => setOpen(false)} />
      )}

      <aside className={clsx(
        'fixed inset-y-0 left-0 z-30 w-60 bg-white border-r border-gray-200 flex flex-col transition-transform lg:translate-x-0 lg:static lg:z-auto',
        open ? 'translate-x-0' : '-translate-x-full'
      )}>
        <div className="flex items-center gap-2 px-5 py-4 border-b border-gray-200">
          <span className="font-semibold text-gray-900 text-sm">{systemConfig?.system_name || 'LAUNCHR'}</span>
          <button className="ml-auto lg:hidden" onClick={() => setOpen(false)}>
            <X size={16} className="text-gray-400" />
          </button>
        </div>

        <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
          {['hr', 'accounting'].includes(user?.role) ? (
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
                    isActive ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  {label}
                </NavLink>
              ))}
              <div className="pt-5 mt-4 border-t border-gray-100 px-3 pb-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Management</p>
              </div>
              {COMMON_NAV.map(renderNavItem)}
            </>
          ) : (
            <>
              <div className="px-3 py-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Management</p>
              </div>
              {COMMON_NAV.map(renderNavItem)}
              <div className="pt-5 mt-4 border-t border-gray-100 px-3 pb-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">System</p>
              </div>
              {ADMIN_ONLY_NAV.map(({ to, icon: Icon, label, end }) => (
                <NavLink
                  key={to} to={to} end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                    isActive ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                  )}
                >
                  <Icon size={16} />
                  {label}
                </NavLink>
              ))}
            </>
          )}
        </nav>

        <div className="px-4 py-4 border-t border-gray-200">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xs font-semibold">
              {user?.name?.charAt(0).toUpperCase()}
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-900 truncate">{user?.name}</p>
              <p className="text-xs text-gray-500 truncate">
                {user?.role === 'admin' ? 'Administrator' : user?.role === 'accounting' ? 'Accounting' : 'Human Resources'}
              </p>
            </div>
          </div>
          <button onClick={handleLogout} className="btn-ghost w-full justify-start text-xs">
            <LogOut size={14} /> Sign out
          </button>
        </div>
      </aside>

      <div className="flex-1 flex flex-col overflow-hidden">
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
