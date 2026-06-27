import { useEffect } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AuthProvider, useAuth } from './store/AuthContext'
import { systemConfigKeys, getSystemConfig } from './api/queries'
import { applyTheme } from './utils/theme'
import AppLayout from './components/layout/AppLayout'
import EmployeeLayout from './components/layout/EmployeeLayout'
import LoginPage from './pages/auth/LoginPage'
import DashboardPage from './pages/dashboard/DashboardPage'
import ConfigureLeavePage from './pages/admin/ConfigureLeavePage'
import AdminDepartmentsPage from './pages/admin/AdminDepartmentsPage'
import AdminScheduleTemplatesPage from './pages/admin/AdminScheduleTemplatesPage'
import EmployeeScheduleAssignmentPage from './pages/admin/EmployeeScheduleAssignmentPage'
import AdminAttendanceLogsPage from './pages/admin/AdminAttendanceLogsPage'
import EmployeeListPage from './pages/employees/EmployeeListPage'
import EmployeeFormPage from './pages/employees/EmployeeFormPage'
import EmployeeDetailPage from './pages/employees/EmployeeDetailPage'
import AttendancePage from './pages/attendance/AttendancePage'
import PayrollPage from './pages/payroll/PayrollPage'
import PayrollDetailPage from './pages/payroll/PayrollDetailPage'
import EmployeeDashboardPage from './pages/employee/EmployeeDashboardPage'
import AttendanceClockPage from './pages/employee/AttendanceClockPage'
import RequestFormPage from './pages/employee/RequestFormPage'
import RequestsPage from './pages/requests/RequestsPage'
import EmployeeProfilePage from './pages/employee/EmployeeProfilePage'
import CalendarPage from './pages/calendar/CalendarPage'

import UserListPage from './pages/admin/UserListPage'
import UserFormPage from './pages/admin/UserFormPage'
import SystemSettingsPage from './pages/admin/SystemSettingsPage'
import AdminCalendarEventTypesPage from './pages/admin/AdminCalendarEventTypesPage'
import AuditLogPage from './pages/admin/AuditLogPage'

const isEmployee = (user) => {
  if (!user) return true;
  return !['admin', 'hr', 'accounting'].includes(user.role);
};

function ProtectedRoute({ children, adminOnly = false }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  
  if (!user) return <Navigate to="/login" replace />
  
  if (adminOnly && isEmployee(user)) {
    return <Navigate to="/employee" replace />
  }
  
  return children
}

function RootRoute() {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  // If logged in, redirect to appropriate dashboard
  if (user) {
    if (['admin', 'hr', 'accounting'].includes(user.role)) return <Navigate to="/hr" replace />
    return <Navigate to="/employee" replace />
  }
  // If not logged in, redirect to login
  return <Navigate to="/login" replace />
}

function HrRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  if (!user) return <Navigate to="/login" replace />
  if (isEmployee(user)) return <Navigate to="/employee" replace />
  return children
}

function SystemAdminRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  if (!user) return <Navigate to="/login" replace />
  if (user.role !== 'admin') return <Navigate to="/hr" replace />
  return children
}

function LayoutSelector() {
  const { user } = useAuth()
  // Admin is not allowed to access standard employee pages
  if (user?.role === 'admin') return <Navigate to="/hr" replace />
  // HR uses the rich AppLayout to keep management links visible, standard employees use simple EmployeeLayout
  return ['hr', 'accounting'].includes(user?.role) ? <AppLayout /> : <EmployeeLayout />
}

export default function App() {
  const { data } = useQuery({
    queryKey: systemConfigKeys.all,
    queryFn: getSystemConfig,
    staleTime: Infinity,
  })

  const themeColor = data?.theme_color || 'sienna'
  const systemName = data?.system_name || 'LAUNCHR'
  const systemLogo = data?.system_logo
  useEffect(() => {
    applyTheme(themeColor)
  }, [themeColor])

  useEffect(() => {
    document.title = `LAUNCHR - ${systemName}`
  }, [systemName])

  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<RootRoute />} />
          <Route path="/hr" element={<HrRoute><AppLayout /></HrRoute>}>
            <Route index element={<DashboardPage />} />
            <Route path="employees" element={<EmployeeListPage />} />
            <Route path="employees/new" element={<EmployeeFormPage />} />
            <Route path="employees/:id" element={<EmployeeDetailPage />} />
            <Route path="employees/:id/edit" element={<EmployeeFormPage />} />
            <Route path="attendance" element={<AttendancePage />} />
            <Route path="leaves" element={<Navigate to="/hr/requests" replace />} />
            <Route path="requests" element={<RequestsPage />} />
            <Route path="payroll" element={<PayrollPage />} />
            <Route path="payroll/:id" element={<PayrollDetailPage />} />
            <Route path="employee-schedules" element={<EmployeeScheduleAssignmentPage />} />
            <Route path="schedule-templates" element={<AdminScheduleTemplatesPage />} />
            <Route path="calendar" element={<CalendarPage readOnly={false} />} />
          </Route>
          <Route path="/admin" element={<SystemAdminRoute><AppLayout /></SystemAdminRoute>}>
            <Route index element={<Navigate to="/hr" replace />} />
            <Route path="audit-logs" element={<AuditLogPage />} />
            <Route path="attendance-logs" element={<AdminAttendanceLogsPage />} />
            <Route path="configure-leave" element={<ConfigureLeavePage />} />
            <Route path="departments" element={<AdminDepartmentsPage />} />
            <Route path="schedule-templates" element={<Navigate to="/hr/schedule-templates" replace />} />
            <Route path="users" element={<UserListPage />} />
            <Route path="users/new" element={<UserFormPage />} />
            <Route path="users/:id/edit" element={<UserFormPage />} />
            <Route path="system-settings" element={<SystemSettingsPage />} />
            <Route path="calendar-event-types" element={<AdminCalendarEventTypesPage />} />
          </Route>
          <Route path="/employee" element={<ProtectedRoute><LayoutSelector /></ProtectedRoute>}>
            <Route index element={<EmployeeDashboardPage />} />
            <Route path="attendance" element={<AttendanceClockPage />} />
            <Route path="leaves/new" element={<Navigate to="/employee/requests/new" replace />} />
            <Route path="requests/new" element={<RequestFormPage />} />
            <Route path="profile" element={<EmployeeProfilePage />} />
            <Route path="calendar" element={<CalendarPage readOnly={true} />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
