import { useAuth } from '../../store/AuthContext'
import { PageHeader, StatusBadge } from '../../components/ui/index.jsx'
import { Mail, Phone, Calendar, Briefcase } from 'lucide-react'
import { format } from 'date-fns'

export default function EmployeeProfilePage() {
  const { user } = useAuth()
  const emp = user?.employee

  if (!emp) {
    return (
      <div>
        <PageHeader title="My Profile" />
        <div className="card p-6 text-center text-gray-500">
          No employee profile information found.
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-3xl">
      <PageHeader
        title="My Profile"
        description="View your personal and employment details"
      />

      <div className="grid lg:grid-cols-3 gap-5">
        {/* Profile card */}
        <div className="card p-5 flex flex-col items-center text-center lg:col-span-1">
          <div className="w-16 h-16 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xl font-semibold mb-3">
            {emp.first_name?.charAt(0)}{emp.last_name?.charAt(0)}
          </div>
          <p className="font-semibold text-gray-900">{emp.first_name} {emp.last_name}</p>
          <p className="text-sm text-gray-500 mb-2">{emp.position}</p>
          <StatusBadge status={emp.status} />
          <p className="text-xs text-gray-400 font-mono mt-2">ID: {emp.employee_id}</p>
        </div>

        {/* Details */}
        <div className="card p-5 lg:col-span-2 space-y-4">
          <h2 className="text-sm font-semibold text-gray-700">Employment Details</h2>
          <div className="grid grid-cols-2 gap-4 text-sm">
            {[
              { icon: Mail, label: 'Email Address', value: emp.email },
              { icon: Phone, label: 'Phone Number', value: emp.phone || '—' },
              { icon: Briefcase, label: 'Department', value: emp.department },
              { icon: Calendar, label: 'Hire Date', value: emp.hire_date ? format(new Date(emp.hire_date), 'MMM dd, yyyy') : '—' },
              { icon: Briefcase, label: 'Salary', value: `₱${Number(emp.salary).toLocaleString()}` },
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
      </div>
    </div>
  )
}
