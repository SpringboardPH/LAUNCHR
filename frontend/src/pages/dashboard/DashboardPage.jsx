import { useQuery } from '@tanstack/react-query'
import { getDashboard, dashboardKeys, getSystemClock, systemClockKeys } from '../../api/queries'
import { PageHeader, StatCard, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { Users, Clock, CalendarOff, Banknote, TrendingUp, AlertCircle, Zap } from 'lucide-react'
import { format, parseISO } from 'date-fns'

export default function DashboardPage() {
  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
    refetchInterval: 30_000,
  })

  const { data, isLoading } = useQuery({
    queryKey: [...dashboardKeys.all, sysClock?.date],
    queryFn: () => getDashboard(),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  if (isLoading) return <PageSpinner />

  const s = data?.summary ?? {}
  const displayDateLabel = sysClock?.date
    ? format(parseISO(sysClock.date), 'EEEE, MMMM d, yyyy')
    : format(new Date(), 'EEEE, MMMM d, yyyy')

  return (
    <div>
      <PageHeader
        title={`Good morning 👋`}
        description={displayDateLabel}
      />

      {/* Today's Status Cards */}
      <div className="mb-6">
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Today's Status</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <StatCard label="Present" value={s.present_today ?? 0} icon={Clock} color="green" />
          <StatCard label="Absent" value={s.absent_today ?? 0} icon={Users} color="gray" />
          <StatCard label="Late" value={s.late_today ?? 0} icon={AlertCircle} color="yellow" />
          <StatCard label="On Leave" value={s.on_leave_today ?? 0} icon={CalendarOff} color="blue" />
          <StatCard label="Incomplete" value={s.incomplete_hours_today ?? 0} icon={Zap} color="orange" />
        </div>
      </div>

      {/* Key Metrics Cards */}
      <div className="mb-8">
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Key Metrics</h3>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard label="Total Employees" value={s.total_employees ?? 0} icon={Users} color="brand" />
          <StatCard label="Pending Leaves" value={s.pending_leaves ?? 0} icon={CalendarOff} color="yellow" />
          <StatCard label="Attendance Rate" value={`${s.attendance_rate ?? 0}%`} icon={TrendingUp} color="brand"
            sub="This month" />
          <StatCard label="On-Time %" value={`${s.on_time_percent ?? 0}%`} icon={Clock} color="green"
            sub="This month" />
        </div>
      </div>

      {/* New Hires */}
      <div className="mb-8">
        <div className="card p-4 bg-gradient-to-r from-brand-50 to-blue-50 border border-brand-100">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs text-gray-600 font-medium">New Hires (Last 30 Days)</p>
              <p className="text-2xl font-bold text-gray-900 mt-1">{s.new_hires_30_days ?? 0}</p>
            </div>
            <Users size={32} className="text-brand-500 opacity-20" />
          </div>
        </div>
      </div>

      <div className="grid lg:grid-cols-3 gap-6 mb-6">
        {/* Department breakdown */}
        <div className="lg:col-span-2 card p-5">
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
            <div className="space-y-4 max-h-[160px] overflow-y-auto pr-2">
              {data.recent_leaves.map(leave => (
                <div key={leave.id} className="flex flex-col gap-1 border-b border-gray-50 pb-3 last:border-0 last:pb-0">
                  <div className="flex justify-between items-start">
                    <span className="text-sm font-medium text-gray-900">
                      {leave.employee?.first_name} {leave.employee?.last_name}
                    </span>
                    <StatusBadge status={leave.status} />
                  </div>
                  <div className="flex justify-between text-xs text-gray-500">
                    <span className="capitalize">{leave.leave_type?.replace(/_/g, ' ')}</span>
                    <span>{leave.days_requested}d</span>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-400 text-center py-6">No pending leaves</p>
          )}
        </div>
      </div>

      {s.last_payroll && (
        <div className="card p-4 flex items-center gap-3 bg-blue-50 border border-blue-100">
          <Banknote size={16} className="text-blue-600 shrink-0" />
          <p className="text-sm text-gray-600">
            Last payroll run: <span className="font-medium text-gray-900">{s.last_payroll}</span>
          </p>
        </div>
      )}
    </div>
  )
}
