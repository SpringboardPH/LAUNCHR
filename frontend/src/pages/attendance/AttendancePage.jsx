import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import {
  getAttendanceToday, getAttendance, clockIn, clockOut,
  getEmployees, attendanceKeys, employeeKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { Clock, LogIn, LogOut } from 'lucide-react'

export default function AttendancePage() {
  const [month, setMonth] = useState(format(new Date(), 'yyyy-MM'))
  const qc = useQueryClient()

  const { data: todayLogs = [], isLoading: todayLoading } = useQuery({
    queryKey: attendanceKeys.today(),
    queryFn: getAttendanceToday,
    refetchInterval: 30_000,
  })

  const { data: employees } = useQuery({
    queryKey: employeeKeys.list({}),
    queryFn: () => getEmployees({ status: 'active' }),
  })

  const { data: monthlyData, isLoading: monthlyLoading } = useQuery({
    queryKey: attendanceKeys.list({ month }),
    queryFn: () => getAttendance({ month }),
  })

  const clockInMutation = useMutation({
    mutationFn: clockIn,
    onSuccess: () => qc.invalidateQueries({ queryKey: attendanceKeys.today() }),
  })
  const clockOutMutation = useMutation({
    mutationFn: clockOut,
    onSuccess: () => qc.invalidateQueries({ queryKey: attendanceKeys.today() }),
  })

  const activeEmployees = employees?.data ?? []
  const logs = monthlyData?.data ?? []

  const getClockedIn = (empId) => todayLogs.find(l => l.employee_id === empId)

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
                      <td className="py-2.5 pr-4 text-gray-600">{log?.clock_in ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600">{log?.clock_out ?? '—'}</td>
                      <td className="py-2.5 pr-4 text-gray-600">{log?.hours_worked ?? '—'}</td>
                      <td className="py-2.5 pr-4">
                        {log ? <StatusBadge status={log.status} /> : <span className="badge-gray">Not yet</span>}
                      </td>
                      <td className="py-2.5">
                        {!log ? (
                          <button
                            onClick={() => clockInMutation.mutate(emp.id)}
                            disabled={clockInMutation.isPending}
                            className="btn-primary text-xs py-1 px-3"
                          >
                            <LogIn size={12} /> Clock In
                          </button>
                        ) : !log.clock_out ? (
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
                    <td className="py-2.5 pr-4 text-gray-600">{log.clock_in ?? '—'}</td>
                    <td className="py-2.5 pr-4 text-gray-600">{log.clock_out ?? '—'}</td>
                    <td className="py-2.5 pr-4 text-gray-600">{log.hours_worked ?? '—'}</td>
                    <td className="py-2.5"><StatusBadge status={log.status} /></td>
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
