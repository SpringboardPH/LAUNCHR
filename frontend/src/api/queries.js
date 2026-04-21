import api from './axios'

// ─── Employees ──────────────────────────────────────────────
export const employeeKeys = {
  all: ['employees'],
  list: (params) => ['employees', 'list', params],
  detail: (id) => ['employees', id],
}

export const getEmployees = (params) =>
  api.get('/employees', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination.total,
  }))

export const getEmployee = (id) =>
  api.get(`/employees/${id}`).then(r => r.data.data)

export const createEmployee = (data) =>
  api.post('/employees', data).then(r => r.data)

export const updateEmployee = (id, data) =>
  api.put(`/employees/${id}`, data).then(r => r.data)

export const deactivateEmployee = (id) =>
  api.patch(`/employees/${id}/deactivate`).then(r => r.data)

export const deleteEmployee = (id) =>
  api.delete(`/employees/${id}`).then(r => r.data)

// ─── Attendance ──────────────────────────────────────────────
export const attendanceKeys = {
  all: ['attendance'],
  today: () => ['attendance', 'today', 'my'],  // Employee's own attendance
  todayAll: () => ['attendance', 'today', 'all'],  // HR/Admin all employees
  list: (params) => ['attendance', 'list', params],
  monthly: (employeeId, month) => ['attendance', 'monthly', employeeId, month],
}

export const getAttendanceToday = () =>
  api.get('/attendance/today').then(r => r.data.data)

export const getAttendance = (params) =>
  api.get('/attendance', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination?.total,
  }))

export const getMonthlyAttendance = (employeeId, month) =>
  api.get(`/attendance/${employeeId}/monthly`, { params: { month } }).then(r => r.data.data)

export const clockIn = (notes, employeeId = null) =>
  api.post('/attendance/clock-in', { notes, employee_id: employeeId }).then(r => r.data.data)

export const clockOut = (notes, employeeId = null) =>
  api.post('/attendance/clock-out', { notes, employee_id: employeeId }).then(r => r.data.data)

// ─── Leaves ──────────────────────────────────────────────────
export const leaveKeys = {
  all: ['leaves'],
  list: (params) => ['leaves', 'list', params],
  detail: (id) => ['leaves', id],
  balance: (employeeId) => ['leaves', 'balance', employeeId],
}

export const getLeaves = (params) =>
  api.get('/leaves', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination.total,
  }))

export const getLeaveBalance = () =>
  api.get('/leaves/balance').then(r => r.data.data)

export const createLeave = (data) =>
  api.post('/leaves', data).then(r => r.data)

export const approveLeave = (id) =>
  api.patch(`/leaves/${id}/approve`).then(r => r.data)

export const rejectLeave = (id, reason) =>
  api.patch(`/leaves/${id}/reject`, { reason }).then(r => r.data)

// ─── Payroll ─────────────────────────────────────────────────
export const payrollKeys = {
  all: ['payroll'],
  list: () => ['payroll', 'list'],
  detail: (id) => ['payroll', id],
}

export const getPayrollRuns = () =>
  api.get('/payroll').then(r => r.data.data)

export const getPayrollRun = (id) =>
  api.get(`/payroll/${id}`).then(r => r.data.data)

export const runPayroll = (data) =>
  api.post('/payroll/run', data).then(r => r.data)

export const exportPayroll = (id, label) => {
  api.get(`/payroll/${id}/export`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a')
    a.href = url
    a.download = `payroll-${label}.csv`
    a.click()
    URL.revokeObjectURL(url)
  })
}

// ─── Dashboard ───────────────────────────────────────────────
export const dashboardKeys = { all: ['dashboard'] }

export const getDashboard = () =>
  api.get('/dashboard/summary').then(r => r.data.data)

// ─── Admin Settings ──────────────────────────────────────────────
export const adminSettingsKeys = {
  all: ['admin', 'settings'],
  detail: (key) => ['admin', 'settings', key],
  defaults: ['admin', 'settings', 'defaults'],
}

export const getAdminSettings = () =>
  api.get('/admin/settings').then(r => r.data.data)

export const getAdminSetting = (key) =>
  api.get(`/admin/settings/${key}`).then(r => r.data.data)

export const updateAdminSetting = (key, value, description, type = 'string') =>
  api.put(`/admin/settings/${key}`, { value, description, type }).then(r => r.data)

export const initializeAdminSettings = () =>
  api.post('/admin/settings/initialize').then(r => r.data)

export const getSettingDefaults = () =>
  api.get('/admin/settings/defaults').then(r => r.data.data)

// ─── Admin Departments ──────────────────────────────────────────────
export const adminDepartmentKeys = {
  all: ['admin', 'departments'],
  detail: (id) => ['admin', 'departments', id],
}

export const getAdminDepartments = () =>
  api.get('/admin/departments').then(r => r.data.data)

export const getAdminDepartment = (id) =>
  api.get(`/admin/departments/${id}`).then(r => r.data.data)

export const createAdminDepartment = (data) =>
  api.post('/admin/departments', data).then(r => r.data)

export const updateAdminDepartment = (id, data) =>
  api.put(`/admin/departments/${id}`, data).then(r => r.data)

export const deleteAdminDepartment = (id) =>
  api.delete(`/admin/departments/${id}`).then(r => r.data)

export const hardDeleteAdminDepartment = (id) =>
  api.delete(`/admin/departments/${id}/hard-delete`).then(r => r.data)

export const restoreAdminDepartment = (id) =>
  api.patch(`/admin/departments/${id}/restore`).then(r => r.data)

// ─── Admin Employee Management ──────────────────────────────────────────────
export const hardDeleteEmployee = (id) =>
  api.delete(`/admin/employees/${id}/hard-delete`).then(r => r.data)

export const restoreEmployee = (id) =>
  api.patch(`/admin/employees/${id}/restore`).then(r => r.data)

// ─── Schedule Templates (Admin) ──────────────────────────────────────────────
export const scheduleTemplateKeys = {
  all: ['admin', 'schedule-templates'],
  detail: (id) => ['admin', 'schedule-templates', id],
}

export const getScheduleTemplates = () =>
  api.get('/admin/schedule-templates').then(r => r.data.data)

export const getScheduleTemplate = (id) =>
  api.get(`/admin/schedule-templates/${id}`).then(r => r.data.data)

export const createScheduleTemplate = (data) =>
  api.post('/admin/schedule-templates', data).then(r => r.data)

export const updateScheduleTemplate = (id, data) =>
  api.put(`/admin/schedule-templates/${id}`, data).then(r => r.data)

export const deleteScheduleTemplate = (id) =>
  api.delete(`/admin/schedule-templates/${id}`).then(r => r.data)

// ─── Employee Schedules (HR) ────────────────────────────────────────────────
export const employeeScheduleKeys = {
  all: ['schedules'],
  list: (params) => ['schedules', 'list', params],
  detail: (id) => ['schedules', id],
  currentForEmployee: (employeeId) => ['schedules', 'current', employeeId],
}

export const getEmployeeSchedules = (params) =>
  api.get('/schedules', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination?.total,
  }))

export const getEmployeeSchedule = (id) =>
  api.get(`/schedules/${id}`).then(r => r.data.data)

export const getCurrentScheduleForEmployee = (employeeId) =>
  api.get(`/schedules/employee/${employeeId}/current`).then(r => r.data.data)

export const createEmployeeSchedule = (data) =>
  api.post('/schedules', data).then(r => r.data)

export const updateEmployeeSchedule = (id, data) =>
  api.put(`/schedules/${id}`, data).then(r => r.data)

export const deleteEmployeeSchedule = (id) =>
  api.delete(`/schedules/${id}`).then(r => r.data)
