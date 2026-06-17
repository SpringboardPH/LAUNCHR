import { useState, useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { addMonths, format, parseISO } from 'date-fns'
import {
  clockIn, clockOut, getAttendanceToday, getMonthlyAttendance,
  attendanceKeys, getCurrentScheduleForEmployee, employeeScheduleKeys,
  getSystemClock, systemClockKeys,
  getCalendarEvents, calendarEventKeys,
  getCalendarEventTypes, calendarEventTypeKeys,
  getPayrollConfig, payrollConfigKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, ScheduleDisplay, ConfirmModal, AlertModal } from '../../components/ui/index.jsx'
import { Clock, LogOut, AlertCircle, CalendarDays } from 'lucide-react'
import { useAuth } from '../../store/AuthContext'
import { getClockWindow, getCutoffPeriod, getNextCutoff, getPrevCutoff } from '../../utils/attendance'

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

export default function AttendanceClockPage() {
  const [notes, setNotes] = useState('')
  const [isOvertime, setIsOvertime] = useState(false)
  const [earlyClockOutConfirmOpen, setEarlyClockOutConfirmOpen] = useState(false)
  const [earlyClockInConfirmOpen, setEarlyClockInConfirmOpen] = useState(false)
  const [overtimeWarningOpen, setOvertimeWarningOpen] = useState(false)
  const [alertConfig, setAlertConfig] = useState({ open: false, title: '', message: '', type: 'error' })
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user, loading: authLoading } = useAuth()

  const employeeId = user?.employee?.id

  // ── System clock (virtual time from backend) ──────────────────
  const { data: sysClock, isLoading: sysClockLoading } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    // Refresh every 30s so the display stays reasonably in sync
    refetchInterval: 30_000,
    staleTime: 0,
  })

  const { data: adminSettings = [] } = useQuery({
    queryKey: payrollConfigKeys.all,
    queryFn: getPayrollConfig,
    staleTime: 0,
    refetchOnWindowFocus: true,
  })

  const [navigatedCutoff, setNavigatedCutoff] = useState(null)

  // Live display clock — ticks every second but starts from system clock
  const [displayTime, setDisplayTime] = useState(null)
  const startTimeRef = useRef(null)

  useEffect(() => {
    if (!sysClock) return
    // Record the wall-clock ms at the moment we received the server time
    startTimeRef.current = {
      serverMs: new Date(sysClock.datetime).getTime(),
      localMs: Date.now(),
    }
    setDisplayTime(new Date(sysClock.datetime))
  }, [sysClock])

  useEffect(() => {
    const interval = setInterval(() => {
      if (!startTimeRef.current) return
      const elapsed = Date.now() - startTimeRef.current.localMs
      setDisplayTime(new Date(startTimeRef.current.serverMs + elapsed))
    }, 1000)
    return () => clearInterval(interval)
  }, [])

  // ── Attendance data ───────────────────────────────────────────
  const { data: todayAttendance, isLoading, refetch } = useQuery({
    queryKey: attendanceKeys.today(user?.id),
    queryFn: () => getAttendanceToday({ personal: true }),
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  const { data: currentSchedule, isLoading: loadingSchedule } = useQuery({
    queryKey: [...employeeScheduleKeys.currentForEmployee(user?.employee?.id), sysClock?.date],
    queryFn: () => getCurrentScheduleForEmployee(user?.employee?.id),
    enabled: !!user?.employee?.id,
  })

  const currentCutoff = navigatedCutoff || getCutoffPeriod(sysClock?.date || new Date(), adminSettings)
  const activeCutoffLabel = currentCutoff.label

  const { data: monthlyData, isLoading: monthlyLoading } = useQuery({
    queryKey: attendanceKeys.monthly(employeeId, currentCutoff.startDate, currentCutoff.endDate),
    queryFn: () => getMonthlyAttendance(employeeId, currentCutoff.startDate, currentCutoff.endDate),
    enabled: Boolean(employeeId) && Boolean(currentCutoff),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  // ── Calendar Data ─────────────────────────────────────────────
  const { data: events = [] } = useQuery({
    queryKey: calendarEventKeys.list({ 
      start_date: currentCutoff.startDate, 
      end_date: currentCutoff.endDate 
    }),
    queryFn: () => getCalendarEvents({ 
      start_date: currentCutoff.startDate, 
      end_date: currentCutoff.endDate 
    }),
    enabled: !!currentCutoff,
  })

  const { data: eventTypes = [] } = useQuery({
    queryKey: calendarEventTypeKeys.all,
    queryFn: () => getCalendarEventTypes(),
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

  const inMutation = useMutation({
    mutationFn: () => clockIn(notes),
    onSuccess: () => {
      setNotes('')
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
      qc.invalidateQueries({ queryKey: systemClockKeys.all })
    },
    onError: () => {
      setNotes('')
      refetch()
    },
  })
  const outMutation = useMutation({
    mutationFn: ({ confirmEarlyClockOut = false, isOvertimeParam = null } = {}) =>
      clockOut(notes, null, confirmEarlyClockOut, isOvertimeParam !== null ? isOvertimeParam : isOvertime),
    onSuccess: () => {
      setNotes('')
      setIsOvertime(false)
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
      qc.invalidateQueries({ queryKey: systemClockKeys.all })
    },
    onError: (error, variables) => {
      const shouldConfirmEarlyClockOut =
        error?.response?.status === 422 && error?.response?.data?.confirm_required

      if (shouldConfirmEarlyClockOut && !variables?.confirmEarlyClockOut) {
        setEarlyClockOutConfirmOpen(true)
        return
      }

      setAlertConfig({ open: true, title: 'Clock Out Failed', message: error?.response?.data?.message || 'Failed to clock out', type: 'error' })
      setNotes('')
      setIsOvertime(false)
      refetch()
    },
  })

  if (isLoading || authLoading || loadingSchedule || sysClockLoading) return <PageSpinner />

  const isClockedIn = todayAttendance?.clock_in_time
  const isClockedOut = todayAttendance?.clock_out_time
  const monthlyLogs = monthlyData?.data ?? []
  const statusCounts = monthlyLogs.reduce((acc, log) => {
    const status = log.status || 'unknown'
    acc[status] = (acc[status] || 0) + 1
    return acc
  }, {})

  const visualStatuses = [
    { key: 'completed', label: 'Completed', color: 'bg-emerald-500' },
    { key: 'late', label: 'Late', color: 'bg-amber-500' },
    { key: 'undertime', label: 'Undertime', color: 'bg-orange-500' },
    { key: 'half_day', label: 'Half Day', color: 'bg-orange-300' },
    { key: 'absent', label: 'Absent', color: 'bg-rose-500' },
    { key: 'on_leave', label: 'On Leave', color: 'bg-sky-500' },
  ]

  const totalVisualDays = visualStatuses.reduce((sum, item) => sum + (statusCounts[item.key] || 0), 0)

  const moveCutoff = (delta) => {
    if (delta > 0) setNavigatedCutoff(getNextCutoff(currentCutoff, adminSettings))
    else setNavigatedCutoff(getPrevCutoff(currentCutoff, adminSettings))
  }

  // Pass sysClock to window check so it uses the virtual time
  const window = getClockWindow(currentSchedule, sysClock)
  const canClockIn = Boolean(window) && !window.isInactiveDay && window.currentMinutes >= window.inStart && window.currentMinutes <= window.outEnd
  const canClockOut = Boolean(window) && Boolean(isClockedIn) && !isClockedOut
  const isTooEarlyToClockIn = Boolean(window) && !window.isInactiveDay && window.currentMinutes < window.inStart
  const isClockInWindowClosed = Boolean(window) && !window.isInactiveDay && window.currentMinutes > window.outEnd

  // Formatted display values — show system clock, not browser clock
  const displayDateLabel = displayTime
    ? format(displayTime, 'EEEE, MMMM d, yyyy')
    : (sysClock?.date ?? format(new Date(), 'EEEE, MMMM d, yyyy'))

  const displayTimeLabel = displayTime
    ? format(displayTime, 'HH:mm:ss')
    : (sysClock?.time ?? format(new Date(), 'HH:mm:ss'))

  const displayDateShort = displayTime
    ? format(displayTime, 'EEEE, MMMM d')
    : (sysClock?.date ?? format(new Date(), 'EEEE, MMMM d'))

  const timerDisplay = (() => {
    if (!isClockedIn) return '00:00:00'
    
    try {
      const [inH, inM, inS] = todayAttendance.clock_in_time.split(':').map(Number)
      let endH, endM, endS

      if (isClockedOut) {
        const parts = todayAttendance.clock_out_time.split(':').map(Number)
        endH = parts[0]
        endM = parts[1]
        endS = parts[2]
      } else {
        const parts = displayTimeLabel.split(':').map(Number)
        if (parts.length !== 3) return '00:00:00'
        endH = parts[0]
        endM = parts[1]
        endS = parts[2]
      }

      let diffSeconds = (endH * 3600 + endM * 60 + endS) - (inH * 3600 + inM * 60 + inS)
      if (diffSeconds < 0) {
        diffSeconds += 24 * 3600
      }

      const h = Math.floor(diffSeconds / 3600).toString().padStart(2, '0')
      const m = Math.floor((diffSeconds % 3600) / 60).toString().padStart(2, '0')
      const s = Math.floor(diffSeconds % 60).toString().padStart(2, '0')

      return `${h}:${m}:${s}`
    } catch {
      return '00:00:00'
    }
  })()

  return (
    <div>
      <AlertModal
        open={alertConfig.open}
        onClose={() => setAlertConfig(a => ({ ...a, open: false }))}
        title={alertConfig.title}
        message={alertConfig.message}
        type={alertConfig.type}
      />

      <ConfirmModal
        open={earlyClockInConfirmOpen}
        onClose={() => setEarlyClockInConfirmOpen(false)}
        onConfirm={() => {
          inMutation.mutate()
          setEarlyClockInConfirmOpen(false)
        }}
        title="Clock In Early?"
        message="Note: Clocking in early won't count toward overtime and will only be applied normally. Do you wish to proceed?"
        type="warning"
        confirmLabel="Confirm Clock In"
      />

      <ConfirmModal
        open={earlyClockOutConfirmOpen}
        onClose={() => setEarlyClockOutConfirmOpen(false)}
        onConfirm={() => {
          outMutation.mutate({ confirmEarlyClockOut: true, isOvertimeParam: isOvertime })
          setEarlyClockOutConfirmOpen(false)
        }}
        title="Clock Out Early?"
        message="Clock out now even though hours will be counted as incomplete?"
        type="danger"
        confirmLabel="Confirm Clock Out"
      />

      <ConfirmModal
        open={overtimeWarningOpen}
        onClose={() => setOvertimeWarningOpen(false)}
        onConfirm={() => {
          setIsOvertime(true)
          setOvertimeWarningOpen(false)
        }}
        title="Overtime Pre-approval Required"
        message="Note: Overtime must be pre-approved by HR. Failure to do so while still confirming it will be noted by HR."
        type="warning"
        confirmLabel="I understand and proceed"
      />

      <PageHeader
        title="Clock In / Out"
        description={displayDateLabel}
        action={
          <button onClick={() => navigate('/employee')} className="btn-secondary">
            ← Back
          </button>
        }
      />

      <div className="grid lg:grid-cols-3 gap-6 items-start">
        <div className="lg:col-span-1">
          <div className="card p-8">
            {/* Timer Display */}
            <div className="text-center mb-8">
              <div className="inline-block bg-gradient-to-br from-brand-100 to-brand-50 p-8 rounded-full mb-4">
                <Clock size={40} className="text-brand-600" />
              </div>
              <div className="text-5xl font-bold text-gray-900 mb-2 font-mono tracking-tight">
                {timerDisplay}
              </div>
              <p className="text-sm font-medium text-brand-600 mb-1">
                {!isClockedIn ? 'Ready to Clock In' : (isClockedOut ? 'Shift Completed' : 'Time Elapsed')}
              </p>
              <p className="text-xs text-gray-500">{displayDateShort}</p>
            </div>

            {/* Status Display */}
            {isClockedIn && (
              <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                <p className="text-xs text-green-600 font-medium mb-1">✓ Clocked In</p>
                <p className="text-lg font-semibold text-green-900">{todayAttendance.clock_in_time}</p>
              </div>
            )}

            {isClockedOut && (
              <div className="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <p className="text-xs text-gray-600 font-medium mb-1">✓ Clocked Out</p>
                <p className="text-lg font-semibold text-gray-900">{todayAttendance.clock_out_time}</p>
                <p className="text-xs text-gray-500 mt-2">Your work day is complete</p>
              </div>
            )}

            {/* Error Messages */}
            {inMutation.error && (
              <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
                <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-medium text-red-900">{inMutation.error.response?.data?.message}</p>
                </div>
              </div>
            )}

            {outMutation.error && (
              <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
                <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-medium text-red-900">{outMutation.error.response?.data?.message}</p>
                </div>
              </div>
            )}

            {/* Notes Field */}
            <textarea
              className="input mb-4 resize-none"
              rows="3"
              placeholder="Add notes (optional)"
              value={notes}
              onChange={e => setNotes(e.target.value)}
              disabled={isClockedOut}
            />

            {/* Action Button */}
            {!isClockedIn ? (
              <button
                onClick={() => {
                  if (window && window.currentMinutes < window.normalInStart) {
                    setEarlyClockInConfirmOpen(true)
                  } else {
                    inMutation.mutate()
                  }
                }}
                disabled={inMutation.isPending || !canClockIn}
                className={`btn w-full ${!canClockIn ? 'bg-gray-100 text-gray-400 cursor-not-allowed border-gray-200' : 'btn-primary'}`}
              >
                <Clock size={16} />
                {!canClockIn
                  ? (window?.isInactiveDay
                    ? 'Not scheduled today'
                    : (isTooEarlyToClockIn ? 'Not your scheduled time yet' : (isClockInWindowClosed ? 'Clock-in window closed' : 'Clock in unavailable')))
                  : (inMutation.isPending ? 'Clocking in...' : 'Clock In')}
              </button>
            ) : !isClockedOut ? (
              <>
                {window && window.currentMinutes > window.outEnd && (
                  <button
                    type="button"
                    onClick={() => {
                      if (isOvertime) {
                        setIsOvertime(false)
                      } else {
                        setOvertimeWarningOpen(true)
                      }
                    }}
                    className={`btn w-full mb-2 ${isOvertime ? 'bg-purple-600 text-white border-purple-600' : 'btn-outline border-purple-300 text-purple-700 hover:bg-purple-50'}`}
                  >
                    <Clock size={16} />
                    {isOvertime ? '✓ Overtime Selected' : 'Clocking out for overtime?'}
                  </button>
                )}
                <button
                  onClick={() => {
                    if (window && window.currentMinutes < window.outStart) {
                      setEarlyClockOutConfirmOpen(true)
                    } else {
                      outMutation.mutate({ confirmEarlyClockOut: false, isOvertimeParam: isOvertime })
                    }
                  }}
                  disabled={outMutation.isPending || !canClockOut}
                  className={`btn w-full ${!canClockOut ? 'bg-gray-100 text-gray-400 cursor-not-allowed border-gray-200' : 'btn-secondary'}`}
                >
                  <LogOut size={16} />
                  {!canClockOut ? 'Not available' : (outMutation.isPending ? 'Clocking out...' : 'Clock Out')}
                </button>
              </>
            ) : (
              <div className="p-3 bg-gray-50 rounded-lg text-center">
                <p className="text-sm text-gray-600">You've already clocked out today</p>
              </div>
            )}
          </div>
        </div>

        <div className="lg:col-span-2 space-y-6">
          <div className="card p-6">
            <h2 className="text-sm font-semibold text-gray-700 mb-6">Assigned Schedule</h2>
            <ScheduleDisplay schedule={currentSchedule} sysClock={sysClock} />
          </div>

          <div className="card p-5">
            <div className="flex items-center justify-between gap-3 mb-4">
              <div>
                <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                  <CalendarDays size={14} className="text-brand-600" /> Attendance Log
                </h2>
                <p className="text-xs text-gray-500 mt-1">Only your own attendance records are shown here.</p>
              </div>
              <div className="flex items-center gap-2">
                <button type="button" className="btn-secondary text-xs px-2 py-1" onClick={() => moveCutoff(-1)}>
                  Prev
                </button>
                <span className="text-sm font-medium text-gray-700 min-w-[110px] text-center">{activeCutoffLabel}</span>
                <button type="button" className="btn-secondary text-xs px-2 py-1" onClick={() => moveCutoff(1)}>
                  Next
                </button>
              </div>
            </div>

            <div className="mb-5 rounded-lg border border-gray-200 p-4 bg-gray-50">
              <p className="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-3">Attendance Visualization</p>
              {totalVisualDays > 0 ? (
                <div className="space-y-3">
                  {visualStatuses.map((item) => {
                    const count = statusCounts[item.key] || 0
                    const percent = totalVisualDays > 0 ? Math.round((count / totalVisualDays) * 100) : 0
                    return (
                      <div key={item.key}>
                        <div className="flex items-center justify-between text-xs text-gray-600 mb-1">
                          <span>{item.label}</span>
                          <span>{count} ({percent}%)</span>
                        </div>
                        <div className="h-2 rounded-full bg-white overflow-hidden">
                          <div className={`${item.color} h-2 rounded-full`} style={{ width: `${percent}%` }} />
                        </div>
                      </div>
                    )
                  })}
                </div>
              ) : (
                <p className="text-sm text-gray-400">No logs yet for {activeCutoffLabel}.</p>
              )}
            </div>

            <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 mb-5">
              <p className="font-semibold mb-1">Important Note:</p>
              <p>Late clock-ins and absences are automatically processed for deductions in the payroll system.</p>
            </div>

            {monthlyLoading ? (
              <PageSpinner />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="border-b border-gray-100">
                    <tr>
                      {['Date', 'Template', 'Clock In', 'Clock Out', 'Hours', 'Status'].map(h => (
                        <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {monthlyLogs.map(log => (
                      <tr key={log.id || log.date} className="hover:bg-gray-50">
                        <td className="py-2.5 pr-4 text-gray-600">{format(parseISO(log.date), 'MMM dd, yyyy')}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{log.template_name || '—'}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{log.clock_in_time ?? '—'}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{log.clock_out_time ?? '—'}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{calculateHours(log.clock_in_time, log.clock_out_time)}</td>
                        <td className="py-2.5">
                          {(() => {
                            const event = getEventForDate(log.date)
                            const color = getEventColor(event)
                            const type = getEventTypeForEvent(event)
                            
                            if (type) {
                              return (
                                <span 
                                  className="px-2 py-1 rounded-md text-[10px] font-bold uppercase border"
                                  style={{ backgroundColor: `${color}15`, color: color, borderColor: `${color}30` }}
                                  title={event.title}
                                >
                                  {type.name || 'Event'}
                                </span>
                              )
                            }

                            if (log.status === 'completed') return <span className="badge-green text-[10px] px-1.5 py-0.5 rounded">Completed</span>
                            if (log.status === 'overtime') return <span className="badge-purple text-[10px] px-1.5 py-0.5 rounded">Overtime</span>
                            if (log.status === 'working') return <span className="badge-green text-[10px] px-1.5 py-0.5 rounded animate-pulse">Working</span>
                            if (log.status === 'on_leave') return <span className="badge-blue text-[10px] px-1.5 py-0.5 rounded">On Leave</span>
                            if (log.status === 'late') return <span className="badge-yellow text-[10px] px-1.5 py-0.5 rounded">Late</span>
                            if (log.status === 'undertime') return <span className="badge-yellow text-[10px] px-1.5 py-0.5 rounded">Undertime</span>
                            if (log.status === 'half_day') return <span className="badge-orange text-[10px] px-1.5 py-0.5 rounded">Half Day</span>
                            return <span className="badge-red text-[10px] px-1.5 py-0.5 rounded">Absent</span>
                          })()}
                        </td>
                      </tr>
                    ))}
                    {monthlyLogs.length === 0 && (
                      <tr>
                        <td colSpan={6} className="py-6 text-center text-gray-400 text-sm">No records for this cutoff</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}