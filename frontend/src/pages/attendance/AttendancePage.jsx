import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { format, parseISO, startOfWeek, endOfWeek, eachDayOfInterval, isWeekend } from 'date-fns'
import {
  getAttendanceToday, getAttendance, clockIn, clockOut, updateAttendanceLog,
  getEmployees, attendanceKeys, employeeKeys,
  getEmployeeSchedules, employeeScheduleKeys,
  getSystemClock, systemClockKeys,
  bulkMarkAbsent,
  getCalendarEvents, calendarEventKeys,
  getCalendarEventTypes, calendarEventTypeKeys
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, ConfirmModal, Modal, FormField, AlertModal } from '../../components/ui/index.jsx'
import { Clock, LogIn, LogOut, Pencil, UserX, AlertCircle, LayoutGrid, List, ChevronDown } from 'lucide-react'
import { getClockWindow, getCutoffPeriod, getNextCutoff, getPrevCutoff, calculateAttendanceStatus } from '../../utils/attendance'
import { calculateHoursWorked } from '../../utils/timeHelpers'

export default function AttendancePage() {
  const [activeCutoff, setActiveCutoff] = useState(null)
  const [viewMode, setViewMode] = useState('list') // 'list' or 'grid'
  const [includeWeekends, setIncludeWeekends] = useState(true)
  const [todayAttendanceExpanded, setTodayAttendanceExpanded] = useState(true)
  const [monthlyEmployeeSearch, setMonthlyEmployeeSearch] = useState('')
  const [monthlyStatus, setMonthlyStatus] = useState('')
  const [monthlyDate, setMonthlyDate] = useState('')
  const [earlyClockOutConfirm, setEarlyClockOutConfirm] = useState({
    open: false,
    employeeId: null,
  })
  const [earlyClockInConfirm, setEarlyClockInConfirm] = useState({
    open: false,
    employeeId: null,
  })
  const [overtimeConfirm, setOvertimeConfirm] = useState({
    open: false,
    employeeId: null,
  })
  const [editLog, setEditLog] = useState(null)
  const [editForm, setEditForm] = useState({ clock_in_time: '', clock_out_time: '', status: '', clock_in_notes: '', clock_out_notes: '' })
  const [markAbsentModal, setMarkAbsentModal] = useState({ open: false, date: format(new Date(), 'yyyy-MM-dd') })
  const [statusConfirmModal, setStatusConfirmModal] = useState({
    open: false,
    detectedStatus: '',
    onConfirm: () => {},
  })
  
  const [alert, setAlert] = useState(null)
  const qc = useQueryClient()

  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  const bulkMarkAbsentMutation = useMutation({
    mutationFn: (date) => bulkMarkAbsent(date),
    onSuccess: (data) => {
      setAlert({ type: 'success', message: data.message || 'Absentees marked successfully' })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
      setMarkAbsentModal({ ...markAbsentModal, open: false })
    },
    onError: (error) => {
      setAlert({ type: 'error', message: error?.response?.data?.message || 'Failed to mark absentees' })
    }
  })

  // Set cutoff once sysClock is available
  useEffect(() => {
    if (sysClock && activeCutoff === null) {
      setActiveCutoff(getCutoffPeriod(sysClock.date))
    }
  }, [sysClock, activeCutoff])

  const currentCutoff = activeCutoff || getCutoffPeriod(sysClock?.date || new Date())

  const monthlyParams = {
    start_date: currentCutoff.startDate,
    end_date: currentCutoff.endDate,
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

  const openEditModal = (log) => {
    setEditLog(log)
    setEditForm({
      clock_in_time: log.clock_in_time || '',
      clock_out_time: log.clock_out_time || '',
      status: log.status || '',
      clock_in_notes: log.clock_in_notes || '',
      clock_out_notes: log.clock_out_notes || '',
    })
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

  const { data: events = [] } = useQuery({
    queryKey: calendarEventKeys.list({ 
      start_date: currentCutoff.startDate, 
      end_date: currentCutoff.endDate 
    }),
    queryFn: () => getCalendarEvents({ 
      start_date: currentCutoff.startDate, 
      end_date: currentCutoff.endDate 
    }),
  })

  const { data: eventTypes = [] } = useQuery({
    queryKey: calendarEventTypeKeys.all,
    queryFn: getCalendarEventTypes,
  })

  const getEventForDate = (dateStr) => {
    if (!events) return null
    return events.find(e => (e.event_date?.substring(0, 10)) === dateStr)
  }

  const getEventTypeForEvent = (event) => {
    if (!event) return null
    return event.type || eventTypes.find(t => t.id === event.calendar_event_type_id)
  }

  const getEventColor = (event) => {
    if (!event) return null
    return event.color || event.type?.color || getEventTypeForEvent(event)?.color
  }

  const getEventCode = (event) => {
    const type = getEventTypeForEvent(event)
    if (!type) return null
    if (type.code) return type.code
    return type.name
      ?.split(' ')
      .map(word => word[0])
      .join('')
      .toUpperCase()
      .substring(0, 2) || 'E'
  }

  const clockInMutation = useMutation({
    mutationFn: (employeeId) => clockIn('', employeeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
  })
  const clockOutMutation = useMutation({
    mutationFn: ({ employeeId, confirmEarlyClockOut = false, isOvertimeParam = null }) =>
      clockOut('', employeeId, confirmEarlyClockOut, isOvertimeParam !== null ? isOvertimeParam : (overtimeConfirm.employeeId === employeeId ? true : false)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
    onError: (error, variables) => {
      const shouldConfirmEarlyClockOut =
        error?.response?.status === 422 && error?.response?.data?.confirm_required

      if (shouldConfirmEarlyClockOut && !variables?.confirmEarlyClockOut) {
        setEarlyClockOutConfirm({
          open: true,
          employeeId: variables.employeeId,
        })
        return
      }

      setAlert({ type: 'error', message: error?.response?.data?.message || 'Failed to clock out' })
    },
  })

  const updateLogMutation = useMutation({
    mutationFn: ({ id, data }) => updateAttendanceLog(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      setEditLog(null)
      setStatusConfirmModal({ ...statusConfirmModal, open: false })
    }
  })

  const handleEditSubmit = (e) => {
    e.preventDefault();
    
    // Calculate detected status
    const logSchedule = editLog?.employee_id ? getScheduleForEmployee(editLog.employee_id) : null
    const template = logSchedule?.template
    const expectedHours = template?.required_hours_per_day || 9
    const workStart = template?.work_start_time || '09:00:00'
    
    const detected = calculateAttendanceStatus(
      editForm.clock_in_time,
      editForm.clock_out_time,
      expectedHours,
      workStart,
      logSchedule
    )

    const specialStatuses = ['undertime', 'half_day', 'overtime']
    
    if (specialStatuses.includes(detected) && detected !== editForm.status) {
      setStatusConfirmModal({
        open: true,
        detectedStatus: detected,
        onConfirm: (useDetected) => {
          const finalData = {
            ...editForm,
            status: useDetected ? detected : editForm.status
          }
          updateLogMutation.mutate({ id: editLog?.id, data: finalData })
        }
      })
    } else {
      updateLogMutation.mutate({ id: editLog?.id, data: editForm })
    }
  }

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

  const moveCutoff = (delta) => {
    if (delta > 0) setActiveCutoff(getNextCutoff(currentCutoff))
    else setActiveCutoff(getPrevCutoff(currentCutoff))
  }

  const getClockedIn = (empId) => todayLogsArray.find(l => l.employee_id === empId)
  const getScheduleForEmployee = (empId) => resolvedCurrentSchedules.find(s => s.employee_id === empId)

  return (
    <div className="space-y-5">
      <ConfirmModal
        open={earlyClockInConfirm.open}
        onClose={() => setEarlyClockInConfirm({ open: false, employeeId: null })}
        onConfirm={() => {
          if (!earlyClockInConfirm.employeeId) return
          clockInMutation.mutate(earlyClockInConfirm.employeeId)
          setEarlyClockInConfirm({ open: false, employeeId: null })
        }}
        title="Clock In Early?"
        message="Note: Clocking in early won't count toward overtime and will only be applied normally. Do you wish to proceed?"
        type="warning"
        confirmLabel="Confirm Clock In"
      />

      <ConfirmModal
        open={earlyClockOutConfirm.open}
        onClose={() => setEarlyClockOutConfirm({ open: false, employeeId: null })}
        onConfirm={() => {
          if (!earlyClockOutConfirm.employeeId) return
          clockOutMutation.mutate({
            employeeId: earlyClockOutConfirm.employeeId,
            confirmEarlyClockOut: true,
            isOvertimeParam: overtimeConfirm.employeeId === earlyClockOutConfirm.employeeId,
          })
          setEarlyClockOutConfirm({ open: false, employeeId: null })
        }}
        title="Clock Out Early?"
        message="Clock out now even though hours will be counted as incomplete?"
        type="danger"
        confirmLabel="Confirm Clock Out"
      />

      <Modal 
        open={overtimeConfirm.open}
        onClose={() => setOvertimeConfirm({ open: false, employeeId: null })}
        title="Clock Out?"
        size="sm"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            The employee is clocking out past their scheduled time. How should this be recorded?
          </p>
          <div className="grid grid-cols-1 gap-2">
            <button 
              onClick={() => {
                clockOutMutation.mutate({ employeeId: overtimeConfirm.employeeId, isOvertimeParam: true })
                setOvertimeConfirm({ open: false, employeeId: null })
              }}
              className="w-full btn bg-purple-600 hover:bg-purple-700 text-white"
            >
              Record as Overtime
            </button>
            <button 
              onClick={() => {
                clockOutMutation.mutate({ employeeId: overtimeConfirm.employeeId, isOvertimeParam: false })
                setOvertimeConfirm({ open: false, employeeId: null })
              }}
              className="w-full btn bg-green-600 hover:bg-green-700 text-white"
            >
              Record as Complete
            </button>
          </div>
        </div>
      </Modal>

      <PageHeader 
        title="Attendance" 
        description={`Today: ${displayDateLabel}`} 
        action={
          <button
            onClick={() => setMarkAbsentModal({ ...markAbsentModal, open: true })}
            className="btn-secondary text-sm"
          >
            <UserX size={16} /> Mark Absentees
          </button>
        }
      />

      {/* Today's quick clock-in panel */}
      <div className="card p-5 mb-6">
        <button
          type="button"
          onClick={() => setTodayAttendanceExpanded(!todayAttendanceExpanded)}
          className="w-full flex items-center justify-between py-2 px-3 -mx-3 -my-2 rounded-lg transition-colors cursor-pointer"
        >
          <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
            <Clock size={14} className="text-brand-600" /> Today's Attendance
          </h2>
          <ChevronDown 
            size={20} 
            className={clsx("text-gray-600 transition-transform duration-200 flex-shrink-0", todayAttendanceExpanded ? "rotate-180" : "")} 
          />
        </button>
        {todayAttendanceExpanded && (todayLoading ? <PageSpinner /> : (
          <div className="overflow-x-auto animate-in fade-in duration-200 mt-4">
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
                      <td className="py-2.5 pr-4 text-gray-600 text-sm">{calculateHoursWorked(log?.clock_in_time, log?.clock_out_time)}</td>
                      <td className="py-2.5 pr-4">
                        {log ? (
                          log.clock_out_time ? (
                            <StatusBadge status={log.status} />
                          ) : (
                            <StatusBadge status="working" />
                          )
                        ) : (
                          (() => {
                            const win = getClockWindow(schedule, sysClock)
                            if (win?.isInactiveDay) {
                              return <StatusBadge status="not_scheduled" />
                            }
                            const isPastShift = win && win.currentMinutes > win.outEnd
                            return isPastShift ? (
                              <StatusBadge status="absent" />
                            ) : (
                              <StatusBadge status="not_yet" />
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
                            onClick={() => {
                              if (win && win.currentMinutes < win.normalInStart) {
                                setEarlyClockInConfirm({ open: true, employeeId: emp.id })
                              } else {
                                clockInMutation.mutate(emp.id)
                              }
                            }}
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
                            onClick={() => {
                              const win = getClockWindow(schedule, sysClock)
                              const isPastShift = win && win.currentMinutes > win.outEnd
                              const isEarly = win && win.currentMinutes < win.outStart

                              if (isPastShift) {
                                setOvertimeConfirm({ open: true, employeeId: emp.id })
                              } else if (isEarly) {
                                setEarlyClockOutConfirm({ open: true, employeeId: emp.id })
                              } else {
                                clockOutMutation.mutate({ 
                                  employeeId: emp.id, 
                                  confirmEarlyClockOut: false,
                                  isOvertimeParam: overtimeConfirm.employeeId === emp.id 
                                })
                              }
                            }}
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
        ))}
      </div>

      {/* Attendance log */}
      <div className="card p-5">
        <div className="flex flex-col gap-3 mb-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <h2 className="text-sm font-semibold text-gray-700">Attendance Log</h2>
              <div className="flex items-center bg-gray-100 p-1 rounded-lg">
                <button 
                  onClick={() => setViewMode('list')}
                  title="List View"
                  className={clsx("p-1.5 rounded-md transition-all", viewMode === 'list' ? "bg-white shadow-sm text-brand-600" : "text-gray-400 hover:text-gray-600")}
                >
                  <List size={16} />
                </button>
                <button 
                  onClick={() => setViewMode('grid')}
                  title="Grid Visualizer"
                  className={clsx("p-1.5 rounded-md transition-all", viewMode === 'grid' ? "bg-white shadow-sm text-brand-600" : "text-gray-400 hover:text-gray-600")}
                >
                  <LayoutGrid size={16} />
                </button>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button 
                type="button" 
                className="btn-secondary text-xs px-2 py-1" 
                onClick={() => moveCutoff(-1)}
              >
                Prev
              </button>
              <span className="text-sm font-medium text-gray-700 min-w-[150px] text-center">
                {currentCutoff.label}
              </span>
              <button 
                type="button" 
                className="btn-secondary text-xs px-2 py-1" 
                onClick={() => moveCutoff(1)}
              >
                Next
              </button>
            </div>
          </div>
          <div className={clsx(
            "grid gap-3",
            viewMode === 'grid' ? "grid-cols-1 md:grid-cols-2 xl:grid-cols-5" : "grid-cols-1 md:grid-cols-2 xl:grid-cols-4"
          )}>
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
              <option value="undertime">Undertime</option>
              <option value="half_day">Half Day</option>
              <option value="overtime">Overtime</option>
              <option value="on_leave">On Leave</option>
              <option value="absent">Absent</option>
            </select>
            <input
              type="date"
              className="input text-sm"
              value={monthlyDate}
              onChange={e => setMonthlyDate(e.target.value)}
            />
            {viewMode === 'grid' && (
              <div className="flex items-center gap-2 px-3 bg-gray-50 border border-gray-100 rounded-lg animate-in fade-in slide-in-from-left-2 duration-300">
                <input 
                  type="checkbox" 
                  id="includeWeekends"
                  checked={includeWeekends}
                  onChange={e => setIncludeWeekends(e.target.checked)}
                  className="rounded border-gray-300 text-brand-600 focus:ring-brand-600 h-4 w-4"
                />
                <label htmlFor="includeWeekends" className="text-xs font-medium text-gray-600 cursor-pointer">
                  Include Weekends
                </label>
              </div>
            )}
            <button
              type="button"
              className="btn-secondary text-sm"
              onClick={clearMonthlyFilters}
            >
              Clear Filters
            </button>
          </div>
        </div>

        {monthlyLoading ? <PageSpinner /> : viewMode === 'list' ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-100">
                <tr>
                  {['Date', 'Employee', 'Schedule', 'Clock In', 'Clock Out', 'Hours', 'Status', 'Action'].map(h => (
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
                    <td className="py-2.5 pr-4 text-gray-600 text-sm">{calculateHoursWorked(log.clock_in_time, log.clock_out_time)}</td>
                    <td className="py-2.5">
                      <div className="flex flex-col items-start gap-1">
                        {(() => {
                          const event = getEventForDate(log.date)
                          const color = getEventColor(event)
                          const type = getEventTypeForEvent(event)
                          
                          if (type) {
                            return (
                              <span 
                                className="px-2 py-1 rounded-md text-[10px] font-bold uppercase border"
                                style={{ backgroundColor: `${color}15`, color: color, borderColor: `${color}30` }}
                              >
                                {type.name || 'Event'}
                              </span>
                            )
                          }

                          return (
                            <>
                              <StatusBadge status={log.status} />
                              {color && (
                                <span 
                                  className="text-[9px] px-1.5 py-0.5 rounded font-bold uppercase w-fit whitespace-nowrap inline-flex border"
                                  style={{ backgroundColor: `${color}15`, color: color, borderColor: `${color}30` }}
                                  title={event.title}
                                >
                                  {type?.code || getEventCode(event)}
                                </span>
                              )}
                            </>
                          )
                        })()}
                      </div>
                    </td>
                    <td className="py-2.5">
                      <button onClick={() => openEditModal(log)} className="text-gray-400 hover:text-brand-600">
                        <Pencil size={16} />
                      </button>
                    </td>
                  </tr>
                ))}
                {logs.length === 0 && (
                  <tr><td colSpan={8} className="py-6 text-center text-gray-400 text-sm">No records for this cutoff</td></tr>
                )}
              </tbody>
            </table>
          </div>
        ) : (
          /* Grid Visualizer */
          <div className="overflow-x-auto">
            {(() => {
              const cutoffDates = eachDayOfInterval({
                start: parseISO(currentCutoff.startDate),
                end: parseISO(currentCutoff.endDate)
              }).filter(date => includeWeekends || !isWeekend(date))

              const groupedByEmployee = logs.reduce((acc, log) => {
                const empId = log.employee_id
                if (!acc[empId]) {
                  acc[empId] = {
                    name: `${log.employee?.first_name} ${log.employee?.last_name}`,
                    data: {}
                  }
                }
                acc[empId].data[format(parseISO(log.date), 'yyyy-MM-dd')] = log
                return acc
              }, {})

              const GRID_STATUS_MAP = {
                completed: { letter: 'C', color: 'bg-green-500 text-white' },
                late:      { letter: 'L', color: 'bg-yellow-400 text-yellow-900' },
                absent:    { letter: 'A', color: 'bg-red-500 text-white' },
                undertime: { letter: 'U', color: 'bg-orange-500 text-white' },
                half_day:  { letter: 'H', color: 'bg-orange-400 text-white' },
                overtime:  { letter: 'O', color: 'bg-blue-600 text-white' },
                on_leave:  { letter: 'V', color: 'bg-blue-400 text-white' },
                holiday:   { letter: 'H', color: 'bg-purple-500 text-white' },
                working:   { letter: 'W', color: 'bg-green-400 text-white' },
              }

              const employeeList = Object.entries(groupedByEmployee).sort((a, b) => a[1].name.localeCompare(b[1].name))

              return (
                <table className="w-full border-collapse">
                  <thead>
                    <tr>
                      <th className="sticky left-0 bg-white z-10 p-2 text-left text-[10px] font-bold text-gray-400 uppercase border-b border-r border-gray-100 min-w-[150px]">Employee</th>
                      {cutoffDates.map(date => (
                        <th key={date.toString()} className="p-1 text-center border-b border-gray-100 min-w-[32px]">
                          <div className="text-[10px] font-bold text-gray-400">{format(date, 'dd')}</div>
                          <div className="text-[9px] text-gray-300 uppercase">{format(date, 'EEE').charAt(0)}</div>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {employeeList.map(([empId, emp]) => (
                      <tr key={empId} className="hover:bg-gray-50 group">
                        <td className="sticky left-0 bg-white group-hover:bg-gray-50 z-10 p-2 text-xs font-medium text-gray-700 border-r border-gray-50 truncate max-w-[150px]">
                          {emp.name}
                        </td>
                        {cutoffDates.map(date => {
                          const dateStr = format(date, 'yyyy-MM-dd')
                          const log = emp.data[dateStr]
                          const status = log?.status
                          const config = GRID_STATUS_MAP[status]
                          const event = getEventForDate(dateStr)
                          const color = getEventColor(event)
                          const eventType = getEventTypeForEvent(event)

                          return (
                            <td key={dateStr} className="p-1 border-b border-gray-50 text-center relative">
                              {(status || color) ? (
                                <button
                                  onClick={() => log ? openEditModal(log) : null}
                                  className={clsx(
                                    "w-6 h-6 rounded-md flex items-center justify-center text-[10px] font-bold transition-transform hover:scale-110 shadow-sm mx-auto",
                                    color ? "" : (config?.color || 'bg-gray-100 text-gray-400')
                                  )}
                                  style={color ? { backgroundColor: color, color: 'white' } : {}}
                                  title={`${emp.name} - ${format(date, 'MMM dd')}: ${status ? status.replace('_', ' ') : (event?.title || 'Event')}`}
                                >
                                  {getEventCode(event) || (config?.letter || '?')}
                                </button>
                              ) : (
                                <div className="w-6 h-6 mx-auto rounded-md border border-dashed border-gray-100" />
                              )}
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                    {employeeList.length === 0 && (
                      <tr><td colSpan={cutoffDates.length + 1} className="py-10 text-center text-gray-400 text-sm italic">No data available for this selection</td></tr>
                    )}
                  </tbody>
                </table>
              )
            })()}
          </div>
        )}
      </div>

      <Modal open={!!editLog} onClose={() => setEditLog(null)} title="Edit Attendance Log" size="md">
        <form onSubmit={handleEditSubmit}>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <FormField label="Clock In"><input type="time" step="1" className="input" value={editForm.clock_in_time} onChange={e => setEditForm({...editForm, clock_in_time: e.target.value})} /></FormField>
              <FormField label="Clock Out"><input type="time" step="1" className="input" value={editForm.clock_out_time} onChange={e => setEditForm({...editForm, clock_out_time: e.target.value})} /></FormField>
            </div>
            
            <FormField label="Status">
              <div className="space-y-2">
                <select className="input" value={editForm.status} onChange={e => setEditForm({...editForm, status: e.target.value})}>
                  <option value="completed">Completed</option>
                  <option value="working">Working</option>
                  <option value="late">Late</option>
                  <option value="undertime">Undertime</option>
                  <option value="half_day">Half Day</option>
                  <option value="overtime">Overtime</option>
                  <option value="absent">Absent</option>
                  <option value="on_leave">On Leave</option>
                </select>
                
                {(() => {
                  const logSchedule = editLog?.employee_id ? getScheduleForEmployee(editLog.employee_id) : null
                  const template = logSchedule?.template
                  const expectedHours = template?.required_hours_per_day || 9
                  const workStart = template?.work_start_time || '09:00:00'
                  const detected = calculateAttendanceStatus(editForm.clock_in_time, editForm.clock_out_time, expectedHours, workStart, logSchedule)
                  
                  if (detected !== editForm.status) {
                    return (
                      <div className="flex items-center gap-2 p-2 bg-brand-50 rounded-lg border border-brand-100">
                        <AlertCircle size={14} className="text-brand-600" />
                        <span className="text-[10px] text-brand-700 font-medium">
                          System detected: <span className="uppercase font-bold">{detected.replace('_', ' ')}</span>
                        </span>
                        <button 
                          type="button" 
                          onClick={() => setEditForm({...editForm, status: detected})}
                          className="ml-auto text-[10px] text-brand-600 hover:underline font-bold"
                        >
                          Apply
                        </button>
                      </div>
                    )
                  }
                  return null
                })()}
              </div>
            </FormField>
            
            <FormField label="Clock In Notes"><textarea className="input text-sm" rows={2} value={editForm.clock_in_notes} onChange={e => setEditForm({...editForm, clock_in_notes: e.target.value})} placeholder="Notes from clock-in time..." /></FormField>
            
            <FormField label="Clock Out Notes"><textarea className="input text-sm" rows={2} value={editForm.clock_out_notes} onChange={e => setEditForm({...editForm, clock_out_notes: e.target.value})} placeholder="Notes from clock-out time..." /></FormField>
            
            <div className="pt-2">
              <button type="submit" disabled={updateLogMutation.isPending} className="btn-primary w-full h-11 text-sm shadow-sm">
                {updateLogMutation.isPending ? 'Saving...' : 'Save Changes'}
              </button>
            </div>
          </div>
        </form>
      </Modal>

      <Modal 
        open={statusConfirmModal.open} 
        onClose={() => setStatusConfirmModal({ ...statusConfirmModal, open: false })}
        title="Confirm Attendance Status"
        size="sm"
      >
        <div className="space-y-4">
          <div className="flex flex-col items-center text-center py-2">
            <div className="w-12 h-12 rounded-full bg-orange-50 flex items-center justify-center text-orange-600 mb-3">
              <AlertCircle size={24} />
            </div>
            <h3 className="text-sm font-semibold text-gray-900">Status Verification Required</h3>
            <p className="text-xs text-gray-500 mt-2 px-2">
              The system detected this shift as <span className="font-bold text-gray-900 uppercase">{statusConfirmModal.detectedStatus.replace('_', ' ')}</span>.
              Is this the correct status for this log?
            </p>
          </div>
          
          <div className="grid grid-cols-1 gap-2 pt-2">
            <button 
              onClick={() => statusConfirmModal.onConfirm(true)}
              className="btn-primary w-full"
            >
              Yes, set as {statusConfirmModal.detectedStatus.replace('_', ' ')}
            </button>
            <button 
              onClick={() => statusConfirmModal.onConfirm(false)}
              className="btn-secondary w-full"
            >
              No, keep my selection
            </button>
            <button 
              onClick={() => setStatusConfirmModal({ ...statusConfirmModal, open: false })}
              className="btn-ghost w-full text-xs text-gray-400"
            >
              Cancel
            </button>
          </div>
        </div>
      </Modal>

      <Modal 
        open={markAbsentModal.open} 
        onClose={() => setMarkAbsentModal({ ...markAbsentModal, open: false })} 
        title="Mark Employees Absent" 
        size="md"
      >
        <div className="space-y-4">
          <div className="bg-blue-50 border-l-4 border-blue-400 p-4 text-sm text-blue-700">
            <p className="font-semibold mb-1">Optional Feature</p>
            <p>
              This manually marks employees as absent if they have no attendance logs for the selected date. 
              The system already does this automatically every day at 11:59 PM.
            </p>
            <p className="mt-2">
              Use this only if you need to mark absences for a day that hasn't been processed yet, 
              or if the automatic process didn't run.
            </p>
          </div>

          <FormField label="Target Date">
            <input 
              type="date" 
              className="input" 
              value={markAbsentModal.date} 
              max={format(new Date(), 'yyyy-MM-dd')}
              onChange={e => setMarkAbsentModal({ ...markAbsentModal, date: e.target.value })} 
            />
          </FormField>

          <div className="flex justify-end gap-3 mt-6">
            <button 
              type="button" 
              className="btn-secondary"
              onClick={() => setMarkAbsentModal({ ...markAbsentModal, open: false })}
            >
              Cancel
            </button>
            <button 
              type="button" 
              className="btn-primary"
              disabled={bulkMarkAbsentMutation.isPending}
              onClick={() => bulkMarkAbsentMutation.mutate(markAbsentModal.date)}
            >
              {bulkMarkAbsentMutation.isPending ? 'Processing...' : 'Run Marking Process'}
            </button>
          </div>
        </div>
      </Modal>

      <AlertModal
        open={!!alert}
        onClose={() => setAlert(null)}
        title={
          alert?.type === 'success' ? 'Success' :
          alert?.type === 'error' ? 'Error' :
          alert?.type === 'warning' ? 'Warning' : 'Information'
        }
        message={alert?.message}
        type={alert?.type}
      />
    </div>
  )
}