import { useQuery } from '@tanstack/react-query'
import { getDashboard, dashboardKeys } from '../../api/queries'
import { PageHeader, StatCard, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { Users, Clock, CalendarOff, Banknote, TrendingUp } from 'lucide-react'
import { format } from 'date-fns'

export default function DashboardPage() {
  const { data, isLoading } = useQuery({ queryKey: dashboardKeys.all, queryFn: getDashboard })

  if (isLoading) return <PageSpinner />

  const s = data?.summary ?? {}

  return (
    <div>
      <PageHeader
        title={`Good morning 👋`}
        description={format(new Date(), 'EEEE, MMMM d, yyyy')}
      />

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard label="Total Employees" value={s.total_employees ?? 0} icon={Users} color="brand" />
        <StatCard label="Present Today" value={s.present_today ?? 0} icon={Clock} color="blue"
          sub={`${s.absent_today ?? 0} absent`} />
        <StatCard label="Pending Leaves" value={s.pending_leaves ?? 0} icon={CalendarOff} color="yellow" />
        <StatCard label="Attendance Rate" value={`${s.attendance_rate ?? 0}%`} icon={TrendingUp} color="brand"
          sub="This month" />
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        {/* Department breakdown */}
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Employees by Department</h2>
          {data?.by_department?.length ? (
            <div className="space-y-3">
              {data.by_department.map(dept => (
                <div key={dept.department} className="flex items-center gap-3">
                  <span className="text-sm text-gray-600 w-32 truncate">{dept.department}</span>
                  <div className="flex-1 bg-gray-100 rounded-full h-2">
                    <div
                      className="bg-brand-500 h-2 rounded-full transition-all"
                      style={{ width: `${(dept.count / (s.total_employees || 1)) * 100}%` }}
                    />
                  </div>
                  <span className="text-sm font-medium text-gray-700 w-6 text-right">{dept.count}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-400 text-center py-6">No department data yet</p>
          )}
        </div>

        {/* Pending leave requests */}
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Pending Leave Requests</h2>
          {data?.recent_leaves?.length ? (
            <div className="space-y-3">
              {data.recent_leaves.map(leave => (
                <div key={leave.id} className="flex items-center justify-between gap-3 py-2 border-b border-gray-50 last:border-0">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">
                      {leave.employee?.first_name} {leave.employee?.last_name}
                    </p>
                    <p className="text-xs text-gray-500">
                      {leave.leave_type?.replace(/_/g, ' ')} · {leave.days_requested}d
                    </p>
                  </div>
                  <StatusBadge status={leave.status} />
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-400 text-center py-6">No pending leaves</p>
          )}
        </div>
      </div>

      {s.last_payroll && (
        <div className="mt-4 card p-4 flex items-center gap-3">
          <Banknote size={16} className="text-brand-600 shrink-0" />
          <p className="text-sm text-gray-600">
            Last payroll run: <span className="font-medium text-gray-900">{s.last_payroll}</span>
          </p>
        </div>
      )}
    </div>
  )
}
