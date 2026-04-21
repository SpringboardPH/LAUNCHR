import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './store/AuthContext'
import AppLayout from './components/layout/AppLayout'
import EmployeeLayout from './components/layout/EmployeeLayout'
import LoginPage from './pages/auth/LoginPage'
import DashboardPage from './pages/dashboard/DashboardPage'
import AdminSettingsPage from './pages/admin/AdminSettingsPage'
import AdminDepartmentsPage from './pages/admin/AdminDepartmentsPage'
import AdminScheduleTemplatesPage from './pages/admin/AdminScheduleTemplatesPage'
import EmployeeScheduleAssignmentPage from './pages/admin/EmployeeScheduleAssignmentPage'
import EmployeeListPage from './pages/employees/EmployeeListPage'
import EmployeeFormPage from './pages/employees/EmployeeFormPage'
import EmployeeDetailPage from './pages/employees/EmployeeDetailPage'
import AttendancePage from './pages/attendance/AttendancePage'
import LeavePage from './pages/leaves/LeavePage'
import PayrollPage from './pages/payroll/PayrollPage'
import PayrollDetailPage from './pages/payroll/PayrollDetailPage'
import EmployeeDashboardPage from './pages/employee/EmployeeDashboardPage'
import AttendanceClockPage from './pages/employee/AttendanceClockPage'
import LeaveRequestFormPage from './pages/employee/LeaveRequestFormPage'

function ProtectedRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  return user ? children : <Navigate to="/login" replace />
}

function PublicRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return null
  if (user) {
    // Redirect employees to employee dashboard, others to admin dashboard
    return <Navigate to={user.role === 'employee' ? '/employee' : '/'} replace />
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
    return user.role === 'employee' ? <Navigate to="/employee" replace /> : <Navigate to="/admin" replace />
  }
  // If not logged in, redirect to login
  return <Navigate to="/login" replace />
}

function AdminRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-6 h-6 border-2 border-brand-600 border-t-transparent rounded-full animate-spin" />
    </div>
  )
  if (!user) return <Navigate to="/login" replace />
  if (user.role === 'employee') return <Navigate to="/employee" replace />
  return children
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<RootRoute />} />
          <Route path="/admin" element={<AdminRoute><AppLayout /></AdminRoute>}>
            <Route index element={<DashboardPage />} />
            <Route path="employees" element={<EmployeeListPage />} />
            <Route path="employees/new" element={<EmployeeFormPage />} />
            <Route path="employees/:id" element={<EmployeeDetailPage />} />
            <Route path="employees/:id/edit" element={<EmployeeFormPage />} />
            <Route path="attendance" element={<AttendancePage />} />
            <Route path="leaves" element={<LeavePage />} />
            <Route path="payroll" element={<PayrollPage />} />
            <Route path="payroll/:id" element={<PayrollDetailPage />} />
            <Route path="settings" element={<AdminSettingsPage />} />
            <Route path="departments" element={<AdminDepartmentsPage />} />
            <Route path="schedule-templates" element={<AdminScheduleTemplatesPage />} />
            <Route path="employee-schedules" element={<EmployeeScheduleAssignmentPage />} />
          </Route>
          <Route path="/employee" element={<ProtectedRoute><EmployeeLayout /></ProtectedRoute>}>
            <Route index element={<EmployeeDashboardPage />} />
            <Route path="attendance" element={<AttendanceClockPage />} />
            <Route path="leaves/new" element={<LeaveRequestFormPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
