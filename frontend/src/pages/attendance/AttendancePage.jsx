import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format, parseISO, startOfWeek, endOfWeek } from 'date-fns'
import {
  getAttendanceToday, getAttendance, clockIn, clockOut,
  getEmployees, attendanceKeys, employeeKeys,
  getEmployeeSchedules, employeeScheduleKeys,
  getSystemClock, systemClockKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, ConfirmModal } from '../../components/ui/index.jsx'
import { Clock, LogIn, LogOut, CalendarDays } from 'lucide-react'
import { getClockWindow } from '../../utils/attendance'

// Calculate hours worked between two time strings (HH:MM:SS format)
const calculateHours = (clockInTime, clockOutTime) => {
  if (!clockInTime || !clockOutTime) return '—'
  
  try {
    const [inH, inM, inS] = clockInTime.split(':').map(Number)
    const [outH, outM, outS] = clockOutTime.split(':').map(Number)
    
    const inMinutes = inH * 60 + inM + inS / 60
    const outMinutes = outH * 60 + outM + outS / 60
    const diffMinutes = outMinutes - inMinutes
    
    if (diffMinutes < 0) return '—'
    
    const hours = Math.floor(diffMinutes / 60)
    const minutes = Math.round(diffMinutes % 60)
    
    return `${hours}h ${minutes}m`
  } catch {
    return '—'
  }
}

export default function AttendancePage() {
  const [month, setMonth] = useState(null)
  const [monthlyEmployeeSearch, setMonthlyEmployeeSearch] = useState('')
  const [monthlyStatus, setMonthlyStatus] = useState('')
  const [monthlyDate, setMonthlyDate] = useState('')
  const [earlyClockOutConfirm, setEarlyClockOutConfirm] = useState({
    open: false,
    employeeId: null,
  })
  const qc = useQueryClient()

  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  const defaultMonth = sysClock?.date
    ? sysClock.date.substring(0, 7)
    : format(new Date(), 'yyyy-MM')
  const activeMonth = month ?? defaultMonth

  const monthlyParams = {
    month: activeMonth,
    include_absentees: true,
    personal: false,
    ...(monthlyEmployeeSearch.trim() ? { employee_search: monthlyEmployeeSearch.trim() } : {}),
    ...(monthlyStatus ? { status: monthlyStatus } : {}),
    ...(monthlyDate ? { date: monthlyDate } : {}),
  }

  const clearMonthlyFilters = () => {
    setMonthlyEmployeeSearch('')
    setMonthlyStatus('')
    setMonthlyDate('')
  }

  const { data: todayLogs = [], isLoading: todayLoading } = useQuery({
    queryKey: attendanceKeys.todayAll(),
    queryFn: () => getAttendanceToday({ personal: false }),
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  // Helper to calculate grace windows (mirroring backend logic)
  const getDetailedClockWindow = (schedule) => {
    const template = schedule?.template
    if (!template) return '—'

    const today = sysClock?.day_of_week ?? new Date().getDay()
    const dayRule = (template.day_rules || []).find(r => r.day === today)

    if (dayRule && !dayRule.enabled) {
      return (
        <div className="flex flex-col gap-0.5 text-[10px] leading-tight text-amber-700">
          <span className="font-medium">Not scheduled today</span>
          <span>{template.name}</span>
        </div>
      )
    }

    // Helper functions for time math
    const parse = (t) => {
      if (!t) return 0
      const [h, m] = t.split(':').map(Number)
      return h * 60 + (m || 0)
    }
    const formatTime = (m) => {
      const h = Math.floor(m / 60)
      const min = m % 60
      return `${h.toString().padStart(2, '0')}:${min.toString().padStart(2, '0')}`
    }

    let inStart, inEnd, outStart, outEnd

    if (dayRule && dayRule.enabled) {
      const targetIn = parse(dayRule.clock_in)
      const targetOut = parse(dayRule.clock_out)
      const grace = parseInt(dayRule.grace_minutes || 0)
      const type = dayRule.grace_type || '-/+'

      if (dayRule.grace_enabled) {
        inStart = targetIn - ((type === '-' || type === '-/+') ? grace : 0)
        inEnd = targetIn + ((type === '+' || type === '-/+') ? grace : 0)
        outStart = targetOut - ((type === '-' || type === '-/+') ? grace : 0)
        outEnd = targetOut + ((type === '+' || type === '-/+') ? grace : 0)
      } else {
        inStart = inEnd = targetIn
        outStart = outEnd = targetOut
      }
      
      return (
        <div className="flex flex-col gap-0.5 text-[10px] leading-tight">
          <div className="flex justify-between gap-2">
            <span className="text-gray-400">IN:</span>
            <span className="font-medium text-gray-700">{formatTime(inStart)} - {formatTime(inEnd)}</span>
          </div>
          <div className="flex justify-between gap-2 border-t border-gray-50 pt-0.5">
            <span className="text-gray-400">OUT:</span>
            <span className="font-medium text-gray-700">{formatTime(outStart)} - {formatTime(outEnd)}</span>
          </div>
        </div>
      )
    }

    // Fallback to template fields
    return (
      <div className="flex flex-col gap-0.5 text-[10px] leading-tight text-gray-500">
        <div className="flex justify-between gap-2">
          <span>IN:</span>
          <span>{template.clock_in_start?.substring(0, 5) || '—'} - {template.clock_in_end?.substring(0, 5) || '—'}</span>
        </div>
        <div className="flex justify-between gap-2 border-t border-gray-50 pt-0.5">
          <span>OUT:</span>
          <span>{template.clock_out_start?.substring(0, 5) || '—'} - {template.clock_out_end?.substring(0, 5) || '—'}</span>
        </div>
      </div>
    )
  }

  const { data: employees } = useQuery({
    queryKey: employeeKeys.list({}),
    queryFn: () => getEmployees({ status: 'active' }),
  })

  const { data: scheduleResponse } = useQuery({
    queryKey: employeeScheduleKeys.list({ status: 'active' }),
    queryFn: () => getEmployeeSchedules({ status: 'active' }),
  })

  const { data: monthlyData, isLoading: monthlyLoading } = useQuery({
    queryKey: attendanceKeys.list(monthlyParams),
    queryFn: () => getAttendance(monthlyParams),
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
  })

  const clockInMutation = useMutation({
    mutationFn: (employeeId) => clockIn('', employeeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
  })
  const clockOutMutation = useMutation({
    mutationFn: ({ employeeId, confirmEarlyClockOut = false }) =>
      clockOut('', employeeId, confirmEarlyClockOut),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
    onError: (error, variables) => {
      const shouldConfirmEarlyClockOut =
        error?.response?.status === 422 && error?.response?.data?.confirm_required

      if (!shouldConfirmEarlyClockOut || variables?.confirmEarlyClockOut) {
        return
      }

      setEarlyClockOutConfirm({
        open: true,
        employeeId: variables.employeeId,
      })
    },
  })

  const activeEmployees = employees?.data ?? []
  const activeSchedules = scheduleResponse?.data ?? []
  const logs = monthlyData?.data ?? []
  const todayLogsArray = Array.isArray(todayLogs) ? todayLogs : []

  const currentDate = sysClock?.date ? parseISO(sysClock.date) : new Date()
  const currentSchedules = activeSchedules.filter(schedule => {
    const start = parseISO(schedule.start_date)
    const end = parseISO(schedule.end_date)
    return currentDate >= start && currentDate <= end
  })

  const currentWeekStart = startOfWeek(currentDate, { weekStartsOn: 1 })
  const currentWeekEnd = endOfWeek(currentDate, { weekStartsOn: 1 })
  const currentWeekStartStr = format(currentWeekStart, 'yyyy-MM-dd')
  const currentWeekEndStr = format(currentWeekEnd, 'yyyy-MM-dd')

  const hasCurrentScheduleByEmployee = new Set(currentSchedules.map((schedule) => schedule.employee_id))

  const previousSchedules = activeSchedules
    .filter((schedule) => parseISO(schedule.end_date) < currentDate)
    .sort((a, b) => parseISO(b.end_date) - parseISO(a.end_date))

  const latestPreviousByEmployee = new Map()
  previousSchedules.forEach((schedule) => {
    if (!latestPreviousByEmployee.has(schedule.employee_id)) {
      latestPreviousByEmployee.set(schedule.employee_id, schedule)
    }
  })

  const fallbackCurrentSchedules = Array.from(latestPreviousByEmployee.values())
    .filter((schedule) => !hasCurrentScheduleByEmployee.has(schedule.employee_id))
    .map((schedule) => ({
      ...schedule,
      id: `carry-forward-${schedule.employee_id}-${currentWeekStartStr}`,
      start_date: currentWeekStartStr,
      end_date: currentWeekEndStr,
      carried_forward: true,
    }))

  const resolvedCurrentSchedules = [...currentSchedules, ...fallbackCurrentSchedules]

  const displayDateLabel = sysClock?.date
    ? format(parseISO(sysClock.date), 'EEEE, MMMM d, yyyy')
    : format(new Date(), 'EEEE, MMMM d, yyyy')

  const getClockedIn = (empId) => todayLogsArray.find(l => l.employee_id === empId)
  const getScheduleForEmployee = (empId) => resolvedCurrentSchedules.find(s => s.employee_id === empId)

  return (
    <div className="space-y-5">
      <ConfirmModal
        open={earlyClockOutConfirm.open}
        onClose={() => setEarlyClockOutConfirm({ open: false, employeeId: null })}
        onConfirm={() => {
          if (!earlyClockOutConfirm.employeeId) return
          clockOutMutation.mutate({
            employeeId: earlyClockOutConfirm.employeeId,
            confirmEarlyClockOut: true,
          })
          setEarlyClockOutConfirm({ open: false, employeeId: null })
        }}
        title="Clock Out Early?"
        message="Clock out now even though hours will be counted as incomplete?"
        type="danger"
        confirmLabel="Confirm Clock Out"
      />

      <PageHeader title="Attendance" description={`Today: ${displayDateLabel}`} />

      {/* Weekly schedule summary */}
      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <CalendarDays size={14} className="text-brand-600" /> Current Weekly Schedules
        </h2>
        {resolvedCurrentSchedules.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-100">
                <tr>
                  {['Employee', 'Template', 'Week', 'Work Hours', 'Clock Window'].map(h => (
                    <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {resolvedCurrentSchedules.map(schedule => (
                  <tr key={schedule.id} className="hover:bg-gray-50">
                    <td className="py-2.5 pr-4 font-medium text-gray-900">
                      {schedule.employee?.first_name} {schedule.employee?.last_name}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-600">{schedule.template?.name}</td>
                    <td className="py-2.5 pr-4 text-gray-600">
                      {format(parseISO(schedule.start_date), 'MMM dd')} - {format(parseISO(schedule.end_date), 'MMM dd, yyyy')}
                      {schedule.carried_forward && (
                        <span className="ml-2 text-[10px] text-gray-500">(carried forward)</span>
                      )}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-600">
                      {schedule.template?.work_start_time?.substring(0, 5)} - {schedule.template?.work_end_time?.substring(0, 5)}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-600">
                      {getDetailedClockWindow(schedule)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-gray-400">No active weekly schedules for this week</p>
        )}
      </div>

      {/* Today's quick clock-in panel */}
      <div className="card p-5 mb-6">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <Clock size={14} className="text-brand-600" /> Today's Attendance
        </h2>
        {todayLoading ? <PageSpinner /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-100">
                <tr>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Employee</th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-8">Schedule</th>
                  <th className="pb-2 pl-3 text-left text-xs text-gray-400 font-medium pr-4">Clock In</th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Clock Out</th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Hours</th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Status</th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {activeEmployees.map(emp => {
                  const log = getClockedIn(emp.id)
                  const schedule = getScheduleForEmployee(emp.id)
                  return (
                    <tr key={emp.id} className="hover:bg-gray-50">
                      <td className="py-2.5 pr-4 font-medium text-gray-900 text-sm">
                        {emp.first_name} {emp.last_name}
                      </td>
                      <td className="py-2.5 pr-8 text-gray-600 text-sm">
                        {schedule ? (
                          <div className="space-y-0.5">
                            <div className="font-medium text-gray-800 text-sm">{schedule.template?.name}</div>
                            <div className="text-xs text-gray-400">
                              {format(parseISO(schedule.start_date), 'MMM dd')} - {format(parseISO(schedule.end_date), 'MMM dd')}
                            </div>
                            <div className="mt-1.5 border-t border-gray-100 pt-1.5">
                              {getDetailedClockWindow(schedule)}
                            </div>
                          </div>
                        ) : (
                          <span className="text-gray-400">No schedule</span>
                        )}
                      </td>
                      <td className="py-2.5 pl-3 pr-4 text-gray-600">{log?.clock_in_time ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600">{log?.clock_out_time ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600 text-sm">{calculateHours(log?.clock_in_time, log?.clock_out_time)}</td>
                      <td className="py-2.5 pr-4">
                        {log ? (
                          log.clock_out_time ? (
                            <span className="badge-gray text-xs px-2 py-1 rounded">Done</span>
                          ) : (
                            <span className="badge-green text-xs px-2 py-1 rounded">Working</span>
                          )
                        ) : (
                          (() => {
                            const win = getClockWindow(schedule, sysClock)
                            if (win?.isInactiveDay) {
                              return <span className="badge-gray text-xs px-2 py-1 rounded">Not scheduled</span>
                            }
                            const isPastShift = win && win.currentMinutes > win.outEnd
                            return isPastShift ? (
                              <span className="badge-red text-xs px-2 py-1 rounded">Absent</span>
                            ) : (
                              <span className="badge-gray text-xs px-2 py-1 rounded">Not yet</span>
                            )
                          })()
                        )}
                      </td>
                      <td className="py-2.5">
                        {!log || !log.clock_in_time ? (
                          (() => {
                            const win = getClockWindow(schedule, sysClock)
                            const canClockIn = Boolean(win) && !win.isInactiveDay && win.currentMinutes >= win.inStart && win.currentMinutes <= win.outEnd
                            return (
                          <button
                            onClick={() => clockInMutation.mutate(emp.id)}
                            disabled={clockInMutation.isPending || !canClockIn}
                            className="btn-primary text-xs py-1.5 px-3 disabled:opacity-50"
                          >
                            <LogIn size={12} />
                            {!canClockIn
                              ? (win?.isInactiveDay ? 'Not scheduled today' : 'Clock in unavailable')
                              : 'Clock In'}
                          </button>
                            )
                          })()
                        ) : !log.clock_out_time ? (
                          <button
                            onClick={() => clockOutMutation.mutate({ employeeId: emp.id })}
                            disabled={clockOutMutation.isPending}
                            className="btn-secondary text-xs py-1.5 px-3"
                          >
                            <LogOut size={12} /> Clock Out
                          </button>
                        ) : (
                          <span className="text-xs text-gray-400">Done</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {activeEmployees.length === 0 && (
                  <tr><td colSpan={7} className="py-6 text-center text-gray-400 text-sm">No active employees</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Monthly log */}
      <div className="card p-5">
        <div className="flex flex-col gap-3 mb-4">
          <h2 className="text-sm font-semibold text-gray-700">Monthly Log</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
            <input
              type="month"
              className="input text-sm"
                value={activeMonth}
              onChange={e => setMonth(e.target.value)}
            />
            <input
              type="text"
              className="input text-sm"
              value={monthlyEmployeeSearch}
              onChange={e => setMonthlyEmployeeSearch(e.target.value)}
              placeholder="Search employee name"
            />
            <select
              className="input text-sm"
              value={monthlyStatus}
              onChange={e => setMonthlyStatus(e.target.value)}
            >
              <option value="">All Statuses</option>
              <option value="completed">Completed</option>
              <option value="working">Working</option>
              <option value="late">Late</option>
              <option value="incomplete">Incomplete</option>
              <option value="on_leave">On Leave</option>
              <option value="absent">Absent</option>
            </select>
            <input
              type="date"
              className="input text-sm"
              value={monthlyDate}
              onChange={e => setMonthlyDate(e.target.value)}
            />
            <button
              type="button"
              className="btn-secondary text-sm"
              onClick={clearMonthlyFilters}
            >
              Clear Filters
            </button>
          </div>
        </div>
        {monthlyLoading ? <PageSpinner /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-100">
                <tr>
                  {['Date', 'Employee', 'Schedule', 'Clock In', 'Clock Out', 'Hours', 'Status'].map(h => (
                    <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {logs.map(log => (
                  <tr key={log.id} className="hover:bg-gray-50">
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">{format(parseISO(log.date), 'MMM dd, yyyy')}</td>
                    <td className="py-2.5 pr-4 font-medium text-gray-900 text-sm">
                      {log.employee?.first_name} {log.employee?.last_name}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">
                      {log.template_name || '—'}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">{log.clock_in_time ?? '—'}</td>
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">{log.clock_out_time ?? '—'}</td>
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">{calculateHours(log.clock_in_time, log.clock_out_time)}</td>
                    <td className="py-2.5">
                      {log.status === 'completed' ? (
                        <span className="badge-green text-[10px] px-1.5 py-0.5 rounded">Completed</span>
                      ) : log.status === 'working' ? (
                        <span className="badge-green text-[10px] px-1.5 py-0.5 rounded animate-pulse">Working</span>
                      ) : log.status === 'on_leave' ? (
                        <span className="badge-blue text-[10px] px-1.5 py-0.5 rounded">On Leave</span>
                      ) : log.status === 'late' ? (
                        <span className="badge-yellow text-[10px] px-1.5 py-0.5 rounded">Late</span>
                      ) : log.status === 'incomplete' ? (
                        <span className="badge-yellow text-[10px] px-1.5 py-0.5 rounded">Incomplete</span>
                      ) : (
                        <span className="badge-red text-[10px] px-1.5 py-0.5 rounded">Absent</span>
                      )}
                    </td>
                  </tr>
                ))}
                {logs.length === 0 && (
                  <tr><td colSpan={7} className="py-6 text-center text-gray-400 text-sm">No records for this month</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
