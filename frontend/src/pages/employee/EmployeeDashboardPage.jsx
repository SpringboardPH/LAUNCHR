import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getAttendanceToday, getLeaveBalance, attendanceKeys, leaveKeys } from '../../api/queries'
import { PageHeader, StatCard, PageSpinner } from '../../components/ui/index.jsx'
import { Clock, CalendarOff, TrendingUp, LogOut, Plus } from 'lucide-react'
import { format } from 'date-fns'

export default function EmployeeDashboardPage() {
  const { data: todayAttendance, isLoading: loadingAttendance } = useQuery({
    queryKey: attendanceKeys.today(),
    queryFn: getAttendanceToday,
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  const { data: leaveBalance, isLoading: loadingBalance } = useQuery({
    queryKey: leaveKeys.balance(),
    queryFn: getLeaveBalance,
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  if (loadingAttendance || loadingBalance) return <PageSpinner />

  const isClockedIn = todayAttendance?.clock_in_time
  const isClockedOut = todayAttendance?.clock_out_time

  return (
    <div>
      <PageHeader
        title="My Dashboard"
        description={format(new Date(), 'EEEE, MMMM d, yyyy')}
      />

      {/* Quick Stats */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
        <StatCard
          label="Status Today"
          value={isClockedOut ? 'Off Duty' : isClockedIn ? 'Working' : 'Not Started'}
          icon={Clock}
          color={isClockedOut ? 'gray' : isClockedIn ? 'green' : 'yellow'}
          sub={isClockedIn ? `Clocked in at ${todayAttendance.clock_in_time}` : 'Clock in to start'}
        />
        <StatCard
          label="Vacation Days Left"
          value={leaveBalance?.balances?.vacation?.remaining ?? 0}
          icon={CalendarOff}
          color="yellow"
          sub={`Out of ${leaveBalance?.balances?.vacation?.total ?? 15} days`}
        />
        <StatCard
          label="Sick Days Left"
          value={leaveBalance?.balances?.sick?.remaining ?? 0}
          icon={TrendingUp}
          color="brand"
          sub={`Out of ${leaveBalance?.balances?.sick?.total ?? 10} days`}
        />
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        {/* Clock In/Out Card */}
        <div className="card p-6">
          <h2 className="text-sm font-semibold text-gray-700 mb-6">Today's Attendance</h2>
          <div className="space-y-3">
            {isClockedIn ? (
              <>
                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg border border-green-200">
                  <div>
                    <p className="text-xs text-green-600 font-medium">Clocked In</p>
                    <p className="text-sm font-semibold text-green-900">{todayAttendance.clock_in_time}</p>
                  </div>
                  <Clock size={20} className="text-green-600" />
                </div>
                {!isClockedOut && (
                  <Link to="/employee/attendance" className="btn btn-primary w-full">
                    <LogOut size={14} /> Clock Out
                  </Link>
                )}
                {isClockedOut && (
                  <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div>
                      <p className="text-xs text-gray-600 font-medium">Clocked Out</p>
                      <p className="text-sm font-semibold text-gray-900">{todayAttendance.clock_out_time}</p>
                    </div>
                    <LogOut size={20} className="text-gray-600" />
                  </div>
                )}
              </>
            ) : (
              <Link to="/employee/attendance" className="btn btn-primary w-full">
                <Clock size={14} /> Clock In
              </Link>
            )}
          </div>
        </div>

        {/* Leave Balance Card */}
        <div className="card p-6">
          <div className="flex flex-col mb-4">
            <h2 className="text-sm font-semibold text-gray-700">Leave Balance</h2>
            {leaveBalance?.cycle && (
              <p className="text-xs text-gray-500 mt-1">
                Anniversary Cycle: {format(new Date(leaveBalance.cycle.start), 'MMM d, yyyy')} – {format(new Date(leaveBalance.cycle.end), 'MMM d, yyyy')}
              </p>
            )}
          </div>
          {leaveBalance?.balances ? (
            <div className="space-y-4">
              {Object.entries(leaveBalance.balances).map(([type, balance]) => (
                <div key={type}>
                  <div className="flex justify-between text-xs text-gray-600 mb-1">
                    <span className="capitalize font-medium">{type.replace(/_/g, ' ')}</span>
                    <span>{balance.used} / {balance.total} used</span>
                  </div>
                  <div className="bg-gray-100 rounded-full h-2">
                    <div
                      className="bg-brand-500 h-2 rounded-full transition-all"
                      style={{ width: balance.total > 0 ? `${(balance.used / balance.total) * 100}%` : '0%' }}
                    />
                  </div>
                  <p className="text-xs text-gray-500 mt-1">{balance.remaining} days remaining</p>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-400">No leave balance data</p>
          )}
          <Link to="/employee/leaves/new" className="btn btn-secondary w-full mt-4">
            <Plus size={14} /> Request Leave
          </Link>
        </div>
      </div>
    </div>
  )
}
