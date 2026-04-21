import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { format, parseISO } from 'date-fns'
import { clockIn, clockOut, getAttendanceToday, getMonthlyAttendance, attendanceKeys } from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import { Clock, LogOut, AlertCircle, CalendarDays } from 'lucide-react'
import { useAuth } from '../../store/AuthContext'

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
  const [month, setMonth] = useState(format(new Date(), 'yyyy-MM'))
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user, loading: authLoading } = useAuth()

  const employeeId = user?.employee?.id

  const { data: todayAttendance, isLoading, refetch } = useQuery({
    queryKey: attendanceKeys.today(),
    queryFn: getAttendanceToday,
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  const { data: monthlyData, isLoading: monthlyLoading } = useQuery({
    queryKey: attendanceKeys.monthly(employeeId, month),
    queryFn: () => getMonthlyAttendance(employeeId, month),
    enabled: Boolean(employeeId),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const inMutation = useMutation({
    mutationFn: () => clockIn(notes),
    onSuccess: () => {
      setNotes('')
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
    onError: () => {
      setNotes('')
      refetch()
    },
  })
  const outMutation = useMutation({
    mutationFn: () => clockOut(notes),
    onSuccess: () => {
      setNotes('')
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    },
    onError: () => {
      setNotes('')
      refetch()
    },
  })

  if (isLoading) return <PageSpinner />
  if (authLoading) return <PageSpinner />

  const isClockedIn = todayAttendance?.clock_in_time
  const isClockedOut = todayAttendance?.clock_out_time
  const monthlyLogs = monthlyData?.data ?? []

  return (
    <div>
      <PageHeader
        title="Clock In / Out"
        description={format(new Date(), 'EEEE, MMMM d, yyyy')}
        action={
          <button onClick={() => navigate('/employee')} className="btn-secondary">
            ← Back
          </button>
        }
      />

      <div className="grid lg:grid-cols-3 gap-6 items-start">
        <div className="lg:col-span-1">
          <div className="card p-8">
            {/* Current Time Display */}
            <div className="text-center mb-8">
              <div className="inline-block bg-gradient-to-br from-brand-100 to-brand-50 p-8 rounded-full mb-4">
                <Clock size={40} className="text-brand-600" />
              </div>
              <div className="text-5xl font-bold text-gray-900 mb-2" id="currentTime">
                {format(new Date(), 'HH:mm:ss')}
              </div>
              <p className="text-sm text-gray-500">{format(new Date(), 'EEEE, MMMM d')}</p>
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
                onClick={() => inMutation.mutate()}
                disabled={inMutation.isPending}
                className="btn btn-primary w-full"
              >
                <Clock size={16} />
                {inMutation.isPending ? 'Clocking in...' : 'Clock In'}
              </button>
            ) : !isClockedOut ? (
              <button
                onClick={() => outMutation.mutate()}
                disabled={outMutation.isPending}
                className="btn btn-secondary w-full"
              >
                <LogOut size={16} />
                {outMutation.isPending ? 'Clocking out...' : 'Clock Out'}
              </button>
            ) : (
              <div className="p-3 bg-gray-50 rounded-lg text-center">
                <p className="text-sm text-gray-600">You've already clocked out today</p>
              </div>
            )}
          </div>
        </div>

        <div className="lg:col-span-2">
          <div className="card p-5">
            <div className="flex items-center justify-between gap-3 mb-4">
              <div>
                <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                  <CalendarDays size={14} className="text-brand-600" /> My Monthly Attendance Log
                </h2>
                <p className="text-xs text-gray-500 mt-1">Only your own attendance records are shown here.</p>
              </div>
              <input
                type="month"
                className="input w-44 text-sm"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
              />
            </div>

            {monthlyLoading ? (
              <PageSpinner />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="border-b border-gray-100">
                    <tr>
                      {['Date', 'Clock In', 'Clock Out', 'Hours', 'Status'].map(h => (
                        <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {monthlyLogs.map(log => (
                      <tr key={log.id} className="hover:bg-gray-50">
                        <td className="py-2.5 pr-4 text-gray-600">{format(parseISO(log.date), 'MMM dd, yyyy')}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{log.clock_in_time ?? '—'}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{log.clock_out_time ?? '—'}</td>
                        <td className="py-2.5 pr-4 text-gray-600">{calculateHours(log.clock_in_time, log.clock_out_time)}</td>
                        <td className="py-2.5">
                          {log.status === 'completed' ? (
                            <span className="badge-green text-xs px-2 py-1 rounded">Completed</span>
                          ) : log.status === 'late' ? (
                            <span className="badge-yellow text-xs px-2 py-1 rounded">Late</span>
                          ) : log.status === 'incomplete' ? (
                            <span className="badge-yellow text-xs px-2 py-1 rounded">Incomplete</span>
                          ) : (
                            <span className="badge-gray text-xs px-2 py-1 rounded">Absent</span>
                          )}
                        </td>
                      </tr>
                    ))}
                    {monthlyLogs.length === 0 && (
                      <tr>
                        <td colSpan={5} className="py-6 text-center text-gray-400 text-sm">No records for this month</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Update current time every second */}
      {typeof window !== 'undefined' && (
        <script>
          {`
            setInterval(() => {
              document.getElementById('currentTime').textContent = new Date().toLocaleTimeString('en-US', { hour12: false })
            }, 1000)
          `}
        </script>
      )}
    </div>
  )
}
