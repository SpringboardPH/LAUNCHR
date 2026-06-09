import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useAuth } from '../../store/AuthContext'
import { getAttendanceToday, getLeaveBalance, getMonthlyAttendance, attendanceKeys, leaveKeys, getCurrentScheduleForEmployee, employeeScheduleKeys, getSystemClock, systemClockKeys } from '../../api/queries'
import { PageHeader, StatCard, PageSpinner, ScheduleDisplay } from '../../components/ui/index.jsx'
import { Clock, CalendarOff, TrendingUp, LogOut, Plus, CheckCircle2, AlertCircle, Zap } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell } from 'recharts'

const STATUS_COLORS = {
  completed: '#10b981',
  late: '#f59e0b',
  undertime: '#f97316',
  half_day: '#fbbf24',
  absent: '#ef4444',
  on_leave: '#3b82f6',
}

export default function EmployeeDashboardPage() {
  const { user } = useAuth()

  const { data: sysClock, isLoading: loadingClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  const { data: todayAttendance, isLoading: loadingAttendance } = useQuery({
    queryKey: attendanceKeys.today(user?.id),
    queryFn: () => getAttendanceToday({ personal: true }),
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  const { data: leaveBalance, isLoading: loadingBalance } = useQuery({
    queryKey: [...leaveKeys.balance(user?.id), sysClock?.date],
    queryFn: () => getLeaveBalance(),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const { data: currentSchedule, isLoading: loadingSchedule } = useQuery({
    queryKey: [...employeeScheduleKeys.currentForEmployee(user?.employee?.id), sysClock?.date],
    queryFn: () => getCurrentScheduleForEmployee(user?.employee?.id),
    enabled: !!user?.employee?.id,
  })

  const activeMonth = sysClock?.date ? sysClock.date.substring(0, 7) : format(new Date(), 'yyyy-MM')

  const { data: monthlyAttendance, isLoading: loadingMonthlyAttendance } = useQuery({
    queryKey: [...attendanceKeys.monthly(user?.employee?.id, activeMonth), sysClock?.date],
    queryFn: () => getMonthlyAttendance(user?.employee?.id, activeMonth),
    enabled: Boolean(user?.employee?.id) && Boolean(activeMonth),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  if (loadingAttendance || loadingBalance || loadingSchedule || loadingClock || loadingMonthlyAttendance) return <PageSpinner />

  const isClockedIn = todayAttendance?.clock_in_time
  const isClockedOut = todayAttendance?.clock_out_time
  const leaveBalances = Object.values(leaveBalance?.balances ?? {})
  const leaveCardColors = ['yellow', 'brand', 'blue', 'gray']
  const displayDateLabel = sysClock?.date
    ? format(parseISO(sysClock.date), 'EEEE, MMMM d, yyyy')
    : format(new Date(), 'EEEE, MMMM d, yyyy')
  const monthlyLogs = monthlyAttendance?.data ?? []

  const monthlyStatusCounts = monthlyLogs.reduce((acc, log) => {
    const status = log.status || 'unknown'
    acc[status] = (acc[status] || 0) + 1
    return acc
  }, {})

  const visualStatuses = [
    { key: 'completed', label: 'Completed', color: STATUS_COLORS.completed, icon: CheckCircle2 },
    { key: 'overtime', label: 'Overtime', color: '#a855f7', icon: TrendingUp },
    { key: 'late', label: 'Late', color: STATUS_COLORS.late, icon: AlertCircle },
    { key: 'undertime', label: 'Undertime', color: STATUS_COLORS.undertime, icon: Zap },
    { key: 'half_day', label: 'Half Day', color: STATUS_COLORS.half_day, icon: Zap },
    { key: 'absent', label: 'Absent', color: STATUS_COLORS.absent, icon: AlertCircle },
    { key: 'on_leave', label: 'On Leave', color: STATUS_COLORS.on_leave, icon: CalendarOff },
  ]

  const totalVisualDays = visualStatuses.reduce((sum, item) => sum + (monthlyStatusCounts[item.key] || 0), 0)
  const monthLabel = format(parseISO(`${activeMonth}-01`), 'MMMM yyyy')

  // Prepare chart data
  const chartData = visualStatuses.map(item => ({
    name: item.label,
    count: monthlyStatusCounts[item.key] || 0,
    color: item.color,
  })).filter(item => item.count > 0)

  return (
    <div className="space-y-6">
      <PageHeader
        title="My Dashboard"
        description={displayDateLabel}
      />

      {/* Clock In/Out - Prominent Card */}
      <div className="card p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold text-gray-700">Today's Status</h2>
          <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${isClockedOut ? 'bg-gray-200 text-gray-900' : isClockedIn ? 'bg-green-200 text-green-900' : 'bg-yellow-200 text-yellow-900'}`}>
            {isClockedOut ? 'Off Duty' : isClockedIn ? 'Working' : 'Not Started'}
          </span>
        </div>
        
        <div className="mb-6 pb-6 border-b border-gray-200">
          <ScheduleDisplay schedule={currentSchedule} sysClock={sysClock} />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {isClockedIn && (
            <div className="flex flex-col p-4 bg-green-100 rounded-lg border border-green-300">
              <p className="text-xs text-green-700 font-semibold uppercase tracking-wide">Clocked In</p>
              <p className="text-2xl font-bold text-green-900 mt-1">{todayAttendance.clock_in_time}</p>
            </div>
          )}
          {isClockedOut && (
            <div className="flex flex-col p-4 bg-gray-100 rounded-lg border border-gray-300">
              <p className="text-xs text-gray-700 font-semibold uppercase tracking-wide">Clocked Out</p>
              <p className="text-2xl font-bold text-gray-900 mt-1">{todayAttendance.clock_out_time}</p>
            </div>
          )}
          
          <Link 
            to="/employee/attendance" 
            className={`btn ${isClockedIn && !isClockedOut ? 'btn-primary' : 'btn-secondary'} flex items-center justify-center gap-2`}
          >
            {isClockedIn && !isClockedOut ? (
              <>
                <LogOut size={16} /> Clock Out
              </>
            ) : (
              <>
                <Clock size={16} /> {isClockedOut ? 'View Details' : 'Clock In'}
              </>
            )}
          </Link>
        </div>
      </div>

      {/* Leave Balance Overview */}
      <div className="grid lg:grid-cols-3 gap-6">
        {/* Leave Balances - Main */}
        <div className="lg:col-span-2 card p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-sm font-semibold text-gray-700">Leave Balance</h2>
            <Link to="/employee/leaves/new" className="text-xs text-brand-600 hover:text-brand-700 font-medium">
              Request Leave →
            </Link>
          </div>
          {leaveBalance?.cycle && (
            <p className="text-xs text-gray-500 mb-4">
              Anniversary Cycle: {format(new Date(leaveBalance.cycle.start), 'MMM d, yyyy')} – {format(new Date(leaveBalance.cycle.end), 'MMM d, yyyy')}
            </p>
          )}
          {leaveBalance?.balances ? (
            <div className="space-y-5">
              {Object.entries(leaveBalance.balances).map(([type, balance]) => {
                const usagePercent = balance.total > 0 ? Math.min(100, (balance.used / balance.total) * 100) : 0;
                return (
                  <div key={type}>
                    <div className="flex justify-between items-end mb-2">
                      <div>
                        <h3 className="text-sm font-semibold text-gray-900">{balance.name}</h3>
                        {balance.requires_balance && (
                          <p className="text-xs text-gray-500 mt-0.5">
                            {balance.remaining} days remaining
                          </p>
                        )}
                      </div>
                      <div className="text-right">
                        {balance.requires_balance ? (
                          <p className="text-sm font-bold text-gray-900">{balance.remaining}/{balance.total}</p>
                        ) : (
                          <p className="text-sm font-bold text-gray-900">Unlimited</p>
                        )}
                      </div>
                    </div>
                    {balance.requires_balance && (
                      <>
                        <div className="bg-gray-200 rounded-full h-3 overflow-hidden">
                          <div
                            className={`h-3 rounded-full transition-all ${usagePercent >= 80 ? 'bg-red-500' : usagePercent >= 50 ? 'bg-yellow-500' : 'bg-green-500'}`}
                            style={{ width: `${usagePercent}%` }}
                          />
                        </div>
                        <p className="text-xs text-gray-500 mt-1">{balance.used} of {balance.total} used</p>
                      </>
                    )}
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="text-sm text-gray-400 text-center py-8">No leave balance data</p>
          )}
        </div>

        {/* Quick Stats Card */}
        <div className="card p-6 bg-gradient-to-br from-brand-50 to-blue-50 border border-brand-100">
          <h3 className="text-sm font-semibold text-gray-700 mb-4">This Month's Summary</h3>
          <div className="space-y-4">
            {visualStatuses.slice(0, 3).map(status => {
              const count = monthlyStatusCounts[status.key] || 0;
              return (
                <div key={status.key} className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div 
                      className="w-3 h-3 rounded-full" 
                      style={{ backgroundColor: status.color }}
                    />
                    <span className="text-sm text-gray-600">{status.label}</span>
                  </div>
                  <span className="text-lg font-bold text-gray-900">{count}</span>
                </div>
              );
            })}
            <div className="border-t border-brand-200 pt-4 mt-4">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-gray-700">Total Work Days</span>
                <span className="text-lg font-bold text-gray-900">{totalVisualDays}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Monthly Attendance Overview */}
      {chartData.length > 0 && (
        <div className="card p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-sm font-semibold text-gray-700">Attendance Breakdown - {monthLabel}</h2>
            <span className="text-xs text-gray-500">{totalVisualDays} work days tracked</span>
          </div>
          
          {/* Bar Chart */}
          <div className="mb-6">
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="name" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#fff', border: '1px solid #e5e7eb', borderRadius: '6px' }}
                  cursor={{ fill: 'rgba(0, 0, 0, 0.05)' }}
                />
                <Bar dataKey="count" radius={[8, 8, 0, 0]}>
                  {chartData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.color} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>

          {/* Detailed Status Breakdown */}
          <div className="flex flex-wrap gap-3">
            {visualStatuses.map((item) => {
              const count = monthlyStatusCounts[item.key] || 0;
              const percent = totalVisualDays > 0 ? Math.round((count / totalVisualDays) * 100) : 0;

              return (
                <div key={item.key} className="flex-1 min-w-[100px] text-center p-3 rounded-lg border border-gray-200 hover:border-gray-300 transition">
                  <div 
                    className="w-12 h-12 mx-auto rounded-full flex items-center justify-center mb-2" 
                    style={{ backgroundColor: item.color + '20' }}
                  >
                    <item.icon size={20} style={{ color: item.color }} />
                  </div>
                  <p className="text-xs font-semibold text-gray-900">{count}</p>
                  <p className="text-xs text-gray-500 mt-0.5">{item.label}</p>
                  {count > 0 && (
                    <p className="text-xs text-gray-400 mt-1">{percent}%</p>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {!chartData.length && (
        <div className="card p-8 text-center">
          <p className="text-sm text-gray-400">No attendance logs available for {monthLabel} yet.</p>
        </div>
      )}
    </div>
  )
}
