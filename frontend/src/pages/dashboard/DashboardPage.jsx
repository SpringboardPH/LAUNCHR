import { useQuery } from '@tanstack/react-query'
import { getDashboard, dashboardKeys, getSystemClock, systemClockKeys } from '../../api/queries'
import { PageHeader, StatCard, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { Users, Clock, CalendarOff, Banknote, TrendingUp, AlertCircle, Zap, CheckCircle2, XCircle, Clock3 } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import {
  LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer
} from 'recharts'

const COLORS = {
  completed: '#10b981',
  late: '#f59e0b',
  undertime: '#f97316',
  half_day: '#fbbf24',
  absent: '#ef4444',
  on_leave: '#3b82f6',
  approved: '#10b981',
  pending: '#f59e0b',
  rejected: '#ef4444',
}

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

  const leaveStatusData = data?.leave_status_breakdown || []
  const departmentAttendanceData = data?.department_attendance_rates || []
  const weeklyTrendData = data?.weekly_attendance_trend || []
  const monthlyStatusData = data?.monthly_status_distribution || []
  const leaveByTypeData = data?.leave_by_type || []

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Good morning 👋`}
        description={displayDateLabel}
      />

      {/* Critical Metrics - Today's Status */}
      <section>
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Today's Critical Metrics</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <StatCard 
            label="Present" 
            value={s.present_today ?? 0} 
            icon={CheckCircle2} 
            color="green"
            trend={`of ${s.total_employees}`}
          />
          <StatCard 
            label="Absent" 
            value={s.absent_today ?? 0} 
            icon={XCircle} 
            color="red"
            sub={s.absent_today > 0 ? 'Needs attention' : 'None'}
          />
          <StatCard 
            label="Late" 
            value={s.late_today ?? 0} 
            icon={Clock} 
            color="yellow"
          />
          <StatCard 
            label="On Leave" 
            value={s.on_leave_today ?? 0} 
            icon={CalendarOff} 
            color="blue" 
          />
          <StatCard 
            label="Short Hours" 
            value={s.short_hours_today ?? 0} 
            icon={Zap} 
            color="orange" 
          />
        </div>
      </section>

      {/* Key Performance Indicators */}
      <section>
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">This Month's Performance</h3>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard 
            label="Attendance Rate" 
            value={`${s.attendance_rate ?? 0}%`} 
            icon={TrendingUp} 
            color="brand"
            sub="Actual vs Expected"
          />
          <StatCard 
            label="On-Time %" 
            value={`${s.on_time_percent ?? 0}%`} 
            icon={Clock3} 
            color="green"
            sub="Punctuality rate"
          />
          <StatCard 
            label="Total Employees" 
            value={s.total_employees ?? 0} 
            icon={Users} 
            color="brand" 
          />
          <StatCard 
            label="Pending Leaves" 
            value={s.pending_leaves ?? 0} 
            icon={AlertCircle} 
            color={s.pending_leaves > 0 ? 'yellow' : 'gray'}
            sub={s.pending_leaves > 0 ? 'Action required' : 'All processed'}
          />
        </div>
      </section>

      {/* Charts Section - Two Column Layout */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* Weekly Attendance Trend */}
        {weeklyTrendData.length > 0 && (
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Weekly Attendance Trend</h2>
            <ResponsiveContainer width="100%" height={250}>
              <LineChart data={weeklyTrendData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="week" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} domain={[0, 100]} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#fff', border: '1px solid #e5e7eb', borderRadius: '6px' }}
                  formatter={(value) => `${value}%`}
                  labelStyle={{ color: '#1f2937' }}
                />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="rate" 
                  stroke="#0ea5e9" 
                  strokeWidth={2}
                  dot={{ fill: '#0ea5e9', r: 4 }}
                  activeDot={{ r: 6 }}
                  name="Attendance %"
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}

        {/* Leave Status Breakdown */}
        {leaveStatusData.length > 0 && (
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Leave Request Status</h2>
            <ResponsiveContainer width="100%" height={250}>
              <PieChart>
                <Pie
                  data={leaveStatusData}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  label={({ status, count }) => `${status}: ${count}`}
                  outerRadius={80}
                  fill="#8884d8"
                  dataKey="count"
                >
                  {leaveStatusData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={COLORS[entry.status.toLowerCase()] || '#9ca3af'} />
                  ))}
                </Pie>
                <Tooltip 
                  formatter={(value) => value}
                  contentStyle={{ backgroundColor: '#fff', border: '1px solid #e5e7eb', borderRadius: '6px' }}
                />
              </PieChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      {/* Second Row Charts */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* Department Attendance Rates */}
        {departmentAttendanceData.length > 0 && (
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Department Attendance Rates</h2>
            <ResponsiveContainer width="100%" height={250}>
              <BarChart data={departmentAttendanceData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="department" stroke="#9ca3af" style={{ fontSize: '12px' }} angle={-45} textAnchor="end" height={80} />
                <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} domain={[0, 100]} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#fff', border: '1px solid #e5e7eb', borderRadius: '6px' }}
                  formatter={(value) => `${value}%`}
                />
                <Bar dataKey="rate" fill="#6366f1" radius={[8, 8, 0, 0]} name="Attendance Rate %" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}

        {/* Leave by Type */}
        {leaveByTypeData.length > 0 && (
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Pending Leaves by Type</h2>
            <ResponsiveContainer width="100%" height={250}>
              <BarChart 
                data={leaveByTypeData}
                layout="vertical"
                margin={{ top: 5, right: 30, left: 150, bottom: 5 }}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis type="number" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <YAxis dataKey="type" type="category" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#fff', border: '1px solid #e5e7eb', borderRadius: '6px' }}
                />
                <Bar dataKey="count" fill="#f59e0b" radius={[0, 8, 8, 0]} name="Pending Requests" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      {/* Quick Action Items */}
      <div className="grid lg:grid-cols-3 gap-6">
        {/* Pending Leaves - High Priority */}
        <div className="lg:col-span-2 card p-5">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-sm font-semibold text-gray-700">Pending Leave Requests</h2>
            {data?.recent_leaves?.length > 0 && (
              <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                {data.recent_leaves.length} awaiting action
              </span>
            )}
          </div>
          {data?.recent_leaves?.length ? (
            <div className="space-y-3 max-h-[300px] overflow-y-auto">
              {data.recent_leaves.map(leave => (
                <div key={leave.id} className="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                  <div className="flex-1">
                    <p className="text-sm font-medium text-gray-900">
                      {leave.employee?.first_name} {leave.employee?.last_name}
                    </p>
                    <div className="flex items-center gap-2 mt-1">
                      <span className="text-xs text-gray-500 capitalize">{leave.leave_type?.replace(/_/g, ' ')}</span>
                      <span className="text-xs font-semibold text-gray-700">{leave.days_requested}d</span>
                    </div>
                  </div>
                  <StatusBadge status={leave.status} />
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-400 text-center py-8">No pending leave requests</p>
          )}
        </div>

        {/* Quick Stats Box */}
        <div className="card p-5 bg-gradient-to-br from-brand-50 to-blue-50 border border-brand-100">
          <h3 className="text-sm font-semibold text-gray-700 mb-4">Quick Stats</h3>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-600">New Hires (30d)</span>
              <span className="text-lg font-bold text-gray-900">{s.new_hires_30_days ?? 0}</span>
            </div>
            <div className="flex items-center justify-between border-t border-brand-200 pt-4">
              <span className="text-sm text-gray-600">Total Absences Today</span>
              <span className={`text-lg font-bold ${s.absent_today > 0 ? 'text-red-600' : 'text-green-600'}`}>
                {s.absent_today ?? 0}
              </span>
            </div>
            {s.last_payroll && (
              <div className="flex items-center justify-between border-t border-brand-200 pt-4">
                <span className="text-sm text-gray-600">Last Payroll</span>
                <span className="text-sm font-medium text-gray-900">{s.last_payroll}</span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Employees by Department Table */}
      {data?.by_department?.length > 0 && (
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Department Breakdown</h2>
          <div className="space-y-3">
            {data.by_department.map(dept => (
              <div key={dept.department} className="flex items-center gap-3">
                <span className="text-sm text-gray-600 w-32 truncate font-medium">{dept.department}</span>
                <div className="flex-1 bg-gray-100 rounded-full h-2.5">
                  <div
                    className="bg-gradient-to-r from-brand-400 to-brand-600 h-2.5 rounded-full transition-all"
                    style={{ width: `${(dept.count / (s.total_employees || 1)) * 100}%` }}
                  />
                </div>
                <span className="text-sm font-semibold text-gray-700 w-12 text-right">{dept.count}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
