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
