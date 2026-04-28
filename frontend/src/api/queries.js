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

// ─── Departments ─────────────────────────────────────────────
export const departmentKeys = {
  all: ['departments'],
}

export const getDepartments = () =>
  api.get('/departments').then(r => r.data.data)

// ─── Attendance ──────────────────────────────────────────────
export const attendanceKeys = {
  all: ['attendance'],
  today: (id) => ['attendance', 'today', 'my', id],  // Employee's own attendance
  todayAll: () => ['attendance', 'today', 'all'],  // HR/Admin all employees
  list: (params) => ['attendance', 'list', params],
  monthly: (employeeId, month) => ['attendance', 'monthly', employeeId, month],
}

export const getAttendanceToday = (params = { personal: true }) =>
  api.get('/attendance/today', { params }).then(r => r.data.data)

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

export const clockOut = (notes, employeeId = null, confirmEarlyClockOut = false) =>
  api.post('/attendance/clock-out', {
    notes,
    employee_id: employeeId,
    confirm_early_clock_out: confirmEarlyClockOut,
  }).then(r => r.data.data)

export const updateAttendanceLog = (id, data) =>
  api.put(`/attendance/${id}`, data).then(r => r.data)

// ─── Leaves ──────────────────────────────────────────────────
export const leaveKeys = {
  all: ['leaves'],
  list: (params) => ['leaves', 'list', params],
  detail: (id) => ['leaves', id],
  balance: (id) => ['leaves', 'balance', id],
}

export const getLeaves = (params = {}) =>
  api.get('/leaves', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination.total,
  }))

export const getLeaveBalance = (employeeId = null) =>
  api.get('/leaves/balance', { params: employeeId ? { employee_id: employeeId } : {} }).then(r => r.data.data)

export const createLeave = (data) =>
  api.post('/leaves', data).then(r => r.data)

export const approveLeave = (id) =>
  api.patch(`/leaves/${id}/approve`).then(r => r.data)

export const rejectLeave = (id, reason) =>
  api.patch(`/leaves/${id}/reject`, { reason }).then(r => r.data)

export const leaveTypeKeys = {
  all: ['leave-types'],
  list: (params) => ['leave-types', 'list', params],
  detail: (id) => ['leave-types', id],
}

export const getLeaveTypes = (params = {}) =>
  api.get('/leave-types', { params }).then(r => r.data.data)

export const getAdminLeaveTypes = (params = {}) =>
  api.get('/admin/leave-types', { params }).then(r => r.data.data)

export const createLeaveType = (data) =>
  api.post('/admin/leave-types', data).then(r => r.data)

export const updateLeaveType = (id, data) =>
  api.put(`/admin/leave-types/${id}`, data).then(r => r.data)

export const deleteLeaveType = (id) =>
  api.delete(`/admin/leave-types/${id}`).then(r => r.data)

export const employeeLeaveBalanceKeys = {
  all: ['admin', 'employee-leave-balances'],
  detail: (employeeId) => ['admin', 'employee-leave-balances', employeeId],
}

export const getEmployeeLeaveBalances = (employeeId) =>
  api.get(`/admin/employee-leave-balances/${employeeId}`).then(r => r.data.data)

export const upsertEmployeeLeaveBalance = (employeeId, leaveTypeId, data) =>
  api.put(`/admin/employee-leave-balances/${employeeId}/${leaveTypeId}`, data).then(r => r.data)

export const cancelEmployeeLeaveBalance = (employeeId, leaveTypeId) =>
  api.delete(`/admin/employee-leave-balances/${employeeId}/${leaveTypeId}`).then(r => r.data)

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

export const systemClockKeys = {
  all: ['system-clock'],
}

export const getSystemClock = () =>
  api.get('/system-clock').then(r => r.data.data)

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
  api.get('/admin/employee-schedules', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination?.total,
  }))

export const getEmployeeSchedule = (id) =>
  api.get(`/admin/employee-schedules/${id}`).then(r => r.data.data)

export const getCurrentScheduleForEmployee = (employeeId) =>
  api.get(`/admin/employee-schedules/employee/${employeeId}/current`).then(r => r.data.data)

export const createEmployeeSchedule = (data) =>
  api.post('/admin/employee-schedules', data).then(r => r.data)

export const updateEmployeeSchedule = (id, data) =>
  api.put(`/admin/employee-schedules/${id}`, data).then(r => r.data)

export const deleteEmployeeSchedule = (id) =>
  api.delete(`/admin/employee-schedules/${id}`).then(r => r.data)

// ─── Users (Admin) ────────────────────────────────────────────────
export const userKeys = {
  all: ['admin', 'users'],
  list: (params) => ['admin', 'users', 'list', params],
  detail: (id) => ['admin', 'users', id],
  trashed: (params) => ['admin', 'users', 'trashed', params],
}

export const getUsers = (params) =>
  api.get('/admin/users', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination?.total,
  }))

export const getUser = (id) =>
  api.get(`/admin/users/${id}`).then(r => r.data.data)

export const createUser = (data) =>
  api.post('/admin/users', data).then(r => r.data)

export const updateUser = (id, data) =>
  api.put(`/admin/users/${id}`, data).then(r => r.data)

export const deleteUser = (id) =>
  api.delete(`/admin/users/${id}`).then(r => r.data)

export const hardDeleteUser = (id) =>
  api.delete(`/admin/users/${id}/hard-delete`).then(r => r.data)

export const getTrashedUsers = (params) =>
  api.get('/admin/users/trashed', { params }).then(r => ({
    data: r.data.data,
    pagination: r.data.pagination,
    total: r.data.pagination?.total,
  }))

export const restoreUser = (id) =>
  api.patch(`/admin/users/${id}/restore`).then(r => r.data)

// ─── Calendar (HR/Admin/Employee) ──────────────────────────────────────────
export const calendarEventKeys = {
  all: ['calendar-events'],
  list: (params) => ['calendar-events', 'list', params],
  detail: (id) => ['calendar-events', id],
}

export const getCalendarEvents = (params = {}) =>
  api.get('/calendar-events', { params }).then(r => r.data.data)

export const getCalendarEvent = (id) =>
  api.get(`/calendar-events/${id}`).then(r => r.data.data)

export const createCalendarEvent = (data) =>
  api.post('/admin/calendar-events', data).then(r => r.data)

export const updateCalendarEvent = (id, data, updateScope = 'single') =>
  api.put(`/admin/calendar-events/${id}`, { ...data, update_scope: updateScope }).then(r => r.data)

export const deleteCalendarEvent = (id, deleteScope = 'single') =>
  api.delete(`/admin/calendar-events/${id}`, { params: { delete_scope: deleteScope } }).then(r => r.data)

export const importCalendarEvents = (file) => {
  const formData = new FormData()
  formData.append('file', file)
  return api.post('/admin/calendar-events/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then(r => r.data)
}

export const exportCalendarEvents = (startDate = null, endDate = null) => {
  const params = {}
  if (startDate) params.start_date = startDate
  if (endDate) params.end_date = endDate
  return api.get('/admin/calendar-events/export', { params, responseType: 'blob' })
}

export const calendarEventTypeKeys = {
  all: ['calendar-event-types'],
  list: (params) => ['calendar-event-types', 'list', params],
  detail: (id) => ['calendar-event-types', id],
}

export const getCalendarEventTypes = (params = {}) =>
  api.get('/calendar-event-types', { params }).then(r => r.data.data)

export const getAdminCalendarEventTypes = (params = {}) =>
  api.get('/admin/calendar-event-types', { params }).then(r => r.data.data)

export const createCalendarEventType = (data) =>
  api.post('/admin/calendar-event-types', data).then(r => r.data)

export const updateCalendarEventType = (id, data) =>
  api.put(`/admin/calendar-event-types/${id}`, data).then(r => r.data)

export const deleteCalendarEventType = (id) =>
  api.delete(`/admin/calendar-event-types/${id}`).then(r => r.data)
