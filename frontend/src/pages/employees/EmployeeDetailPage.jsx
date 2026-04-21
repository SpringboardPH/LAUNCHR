import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { getEmployee, getLeaveBalance, employeeKeys, leaveKeys, employeeScheduleKeys, getCurrentScheduleForEmployee } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { ArrowLeft, Pencil, Mail, Phone, Calendar, Briefcase, Clock3, CalendarDays, ClipboardList } from 'lucide-react'
import { format } from 'date-fns'

export default function EmployeeDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const { data: emp, isLoading } = useQuery({
    queryKey: employeeKeys.detail(id),
    queryFn: () => getEmployee(id),
  })

  const { data: balance } = useQuery({
    queryKey: leaveKeys.balance(id),
    queryFn: () => getLeaveBalance(id),
    enabled: Boolean(id),
  })

  const { data: schedule } = useQuery({
    queryKey: employeeScheduleKeys.currentForEmployee(id),
    queryFn: () => getCurrentScheduleForEmployee(id),
    enabled: Boolean(id),
  })

  if (isLoading) return <PageSpinner />
  if (!emp) return <p className="text-sm text-gray-500">Employee not found.</p>

  return (
    <div className="max-w-3xl">
      <PageHeader
        title={`${emp.first_name} ${emp.last_name}`}
        description={`${emp.position} · ${emp.department}`}
        action={
          <div className="flex gap-2">
            <Link to={`/admin/employee-schedules?employee_id=${id}`} className="btn-secondary">
              <CalendarDays size={14} /> Assign Schedule
            </Link>
            <button onClick={() => navigate('/admin/employees')} className="btn-secondary">
              <ArrowLeft size={14} /> Back
            </button>
            <Link to={`/admin/employees/${id}/edit`} className="btn-primary">
              <Pencil size={14} /> Edit
            </Link>
          </div>
        }
      />

      <div className="grid lg:grid-cols-3 gap-5">
        {/* Profile card */}
        <div className="card p-5 flex flex-col items-center text-center lg:col-span-1">
          <div className="w-16 h-16 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xl font-semibold mb-3">
            {emp.first_name.charAt(0)}{emp.last_name.charAt(0)}
          </div>
          <p className="font-semibold text-gray-900">{emp.first_name} {emp.last_name}</p>
          <p className="text-sm text-gray-500 mb-2">{emp.position}</p>
          <StatusBadge status={emp.status} />
          <p className="text-xs text-gray-400 font-mono mt-2">{emp.employee_id}</p>
        </div>

        {/* Details */}
        <div className="card p-5 lg:col-span-2 space-y-4">
          <h2 className="text-sm font-semibold text-gray-700">Details</h2>
          <div className="grid grid-cols-2 gap-4 text-sm">
            {[
              { icon: Mail,     label: 'Email',           value: emp.email },
              { icon: Phone,    label: 'Phone',           value: emp.phone || '—' },
              { icon: Briefcase,label: 'Department',      value: emp.department },
              { icon: Calendar, label: 'Hire date',       value: format(new Date(emp.hire_date), 'MMM dd, yyyy') },
              { icon: Briefcase,label: 'Salary',         value: `₱${Number(emp.salary).toLocaleString()}` },
            ].map(({ icon: Icon, label, value }) => (
              <div key={label} className="flex items-start gap-2">
                <Icon size={14} className="text-gray-400 mt-0.5 shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">{label}</p>
                  <p className="font-medium text-gray-800">{value}</p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Leave balance */}
        {balance && (
          <div className="card p-5 lg:col-span-3">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Leave Balance ({new Date().getFullYear()})</h2>
            <div className="grid grid-cols-2 gap-4">
              {[
                { label: 'Vacation leave', data: balance.vacation, color: 'bg-blue-500' },
                { label: 'Sick leave',     data: balance.sick,     color: 'bg-yellow-500' },
              ].map(({ label, data, color }) => (
                <div key={label}>
                  <div className="flex justify-between text-xs text-gray-500 mb-1">
                    <span>{label}</span>
                    <span>{data.used} / {data.total} used</span>
                  </div>
                  <div className="bg-gray-100 rounded-full h-2">
                    <div
                      className={`${color} h-2 rounded-full`}
                      style={{ width: `${(data.used / data.total) * 100}%` }}
                    />
                  </div>
                  <p className="text-xs text-gray-400 mt-1">{data.remaining} days remaining</p>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Weekly schedule */}
        {schedule && schedule.template && (
          <div className="card p-5 lg:col-span-3">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-semibold text-gray-700">Assigned Weekly Schedule</h2>
              <StatusBadge status={schedule.status} />
            </div>

            <div className="grid md:grid-cols-2 gap-4 text-sm">
              <div className="flex items-start gap-2">
                <CalendarDays size={14} className="text-gray-400 mt-0.5 shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">Template</p>
                  <p className="font-medium text-gray-800">{schedule.template.name}</p>
                </div>
              </div>
              <div className="flex items-start gap-2">
                <Calendar size={14} className="text-gray-400 mt-0.5 shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">Week range</p>
                  <p className="font-medium text-gray-800">
                    {format(new Date(schedule.start_date), 'MMM dd, yyyy')} - {format(new Date(schedule.end_date), 'MMM dd, yyyy')}
                  </p>
                </div>
              </div>
              <div className="flex items-start gap-2">
                <Clock3 size={14} className="text-gray-400 mt-0.5 shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">Work window</p>
                  <p className="font-medium text-gray-800">
                    {schedule.template.work_start_time?.substring(0, 5)} - {schedule.template.work_end_time?.substring(0, 5)}
                  </p>
                </div>
              </div>
              <div className="flex items-start gap-2">
                <ClipboardList size={14} className="text-gray-400 mt-0.5 shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">Rules</p>
                  <p className="font-medium text-gray-800">
                    {schedule.template.required_hours_per_day}h/day, late after {schedule.template.late_threshold_minutes}m
                  </p>
                </div>
              </div>
            </div>

            <div className="mt-4">
              <p className="text-xs text-gray-400 mb-2">Work days</p>
              <div className="flex flex-wrap gap-2">
                {(schedule.template.work_days || []).map(day => (
                  <span key={day} className="px-2 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-medium">
                    {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][day]}
                  </span>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
