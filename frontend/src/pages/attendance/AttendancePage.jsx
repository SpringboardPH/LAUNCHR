import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import {
  getAttendanceToday, getAttendance, clockIn, clockOut,
  getEmployees, attendanceKeys, employeeKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { Clock, LogIn, LogOut } from 'lucide-react'

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
  const [month, setMonth] = useState(format(new Date(), 'yyyy-MM'))
  const qc = useQueryClient()

  const { data: todayLogs = [], isLoading: todayLoading } = useQuery({
    queryKey: attendanceKeys.todayAll(),
    queryFn: getAttendanceToday,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  const { data: employees } = useQuery({
    queryKey: employeeKeys.list({}),
    queryFn: () => getEmployees({ status: 'active' }),
  })

  const { data: monthlyData, isLoading: monthlyLoading } = useQuery({
    queryKey: attendanceKeys.list({ month }),
    queryFn: () => getAttendance({ month }),
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
  })

  const clockInMutation = useMutation({
    mutationFn: (employeeId) => clockIn('', employeeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.list({ month }) })
    },
  })
  const clockOutMutation = useMutation({
    mutationFn: (employeeId) => clockOut('', employeeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: attendanceKeys.todayAll() })
      qc.invalidateQueries({ queryKey: attendanceKeys.list({ month }) })
    },
  })

  const activeEmployees = employees?.data ?? []
  const logs = monthlyData?.data ?? []
  const todayLogsArray = Array.isArray(todayLogs) ? todayLogs : []

  const getClockedIn = (empId) => todayLogsArray.find(l => l.employee_id === empId)

  return (
    <div>
      <PageHeader title="Attendance" description={`Today: ${format(new Date(), 'EEEE, MMMM d, yyyy')}`} />

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
                  {['Employee', 'Clock In', 'Clock Out', 'Hours', 'Status', 'Action'].map(h => (
                    <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {activeEmployees.map(emp => {
                  const log = getClockedIn(emp.id)
                  return (
                    <tr key={emp.id} className="hover:bg-gray-50">
                      <td className="py-2.5 pr-4 font-medium text-gray-900">
                        {emp.first_name} {emp.last_name}
                      </td>
                      <td className="py-2.5 pr-4 text-gray-600">{log?.clock_in_time ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600">{log?.clock_out_time ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600">{calculateHours(log?.clock_in_time, log?.clock_out_time)}</td>
                      <td className="py-2.5 pr-4">
                        {log ? (
                          log.clock_out_time ? (
                            <span className="badge-gray text-xs px-2 py-1 rounded">Done</span>
                          ) : (
                            <span className="badge-green text-xs px-2 py-1 rounded">Working</span>
                          )
                        ) : (
                          <span className="badge-gray text-xs px-2 py-1 rounded">Not yet</span>
                        )}
                      </td>
                      <td className="py-2.5">
                        {!log || !log.clock_in_time ? (
                          <button
                            onClick={() => clockInMutation.mutate(emp.id)}
                            disabled={clockInMutation.isPending}
                            className="btn-primary text-xs py-1 px-3"
                          >
                            <LogIn size={12} /> Clock In
                          </button>
                        ) : !log.clock_out_time ? (
                          <button
                            onClick={() => clockOutMutation.mutate(emp.id)}
                            disabled={clockOutMutation.isPending}
                            className="btn-secondary text-xs py-1 px-3"
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
                  <tr><td colSpan={6} className="py-6 text-center text-gray-400 text-sm">No active employees</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Monthly log */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold text-gray-700">Monthly Log</h2>
          <input
            type="month" className="input w-44 text-sm"
            value={month} onChange={e => setMonth(e.target.value)}
          />
        </div>
        {monthlyLoading ? <PageSpinner /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-100">
                <tr>
                  {['Date', 'Employee', 'Clock In', 'Clock Out', 'Hours', 'Status'].map(h => (
                    <th key={h} className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {logs.map(log => (
                  <tr key={log.id} className="hover:bg-gray-50">
                    <td className="py-2.5 pr-4 text-gray-600">{format(new Date(log.date), 'MMM dd, yyyy')}</td>
                    <td className="py-2.5 pr-4 font-medium text-gray-900">
                      {log.employee?.first_name} {log.employee?.last_name}
                    </td>
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
                {logs.length === 0 && (
                  <tr><td colSpan={6} className="py-6 text-center text-gray-400 text-sm">No records for this month</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
