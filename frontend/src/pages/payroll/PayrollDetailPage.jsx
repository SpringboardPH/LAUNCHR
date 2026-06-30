import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { getPayroll, payrollKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { ArrowLeft } from 'lucide-react'

export default function PayrollDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const { data: payroll, isLoading } = useQuery({
    queryKey: payrollKeys.detail(id),
    queryFn: () => getPayroll(id),
  })

  if (isLoading) return <PageSpinner />
  if (!payroll) return <p className="text-sm text-gray-500 p-6">Payroll record not found.</p>

  const deductions = Array.isArray(payroll.deductions)
    ? payroll.deductions
    : Object.entries(payroll.deductions || {}).map(([label, amount]) => ({ label, amount }))

  const allowances = Array.isArray(payroll.allowances)
    ? payroll.allowances
    : Object.entries(payroll.allowances || {}).map(([label, amount]) => ({ label, amount }))

  const totalDeductions = Number(payroll.gross_pay) - Number(payroll.net_pay)
  const periodLabel = `${format(parseISO(payroll.cutoff_start), 'MMM d')} – ${format(parseISO(payroll.cutoff_end), 'MMM d, yyyy')}`
  const baseIncome = Number(payroll.gross_pay) - allowances.reduce((s, a) => s + Number(a.amount || 0), 0)

  return (
    <div className="space-y-5">
      <PageHeader
        title={`${payroll.employee?.first_name ?? ''} ${payroll.employee?.last_name ?? ''}`}
        description={periodLabel}
        action={
          <button onClick={() => navigate(-1)} className="btn-secondary">
            <ArrowLeft size={14} /> Back
          </button>
        }
      />

      {/* Summary cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Days Worked', value: payroll.days_worked ?? 0 },
          { label: 'Gross Pay', value: `₱${Number(payroll.gross_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
          { label: 'Total Deductions', value: `₱${totalDeductions.toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
          { label: 'Net Pay', value: `₱${Number(payroll.net_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
        ].map(({ label, value }) => (
          <div key={label} className="card p-4">
            <p className="text-xs text-gray-400 mb-1">{label}</p>
            <p className="text-lg font-semibold text-gray-900">{value}</p>
          </div>
        ))}
      </div>

      {/* Status + meta */}
      <div className="flex items-center gap-3">
        <StatusBadge status={payroll.status} />
        <span className="text-xs text-gray-400">
          Generated {format(parseISO(payroll.created_at), 'MMM d, yyyy h:mm a')}
        </span>
        {payroll.employee?.position && (
          <span className="text-xs text-gray-400">· {payroll.employee.position}</span>
        )}
      </div>

      {/* Earnings / Deductions */}
      <div className="grid lg:grid-cols-2 gap-4">
        <div className="card p-5">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Earnings</p>
          <div className="space-y-1.5">
            <div className="flex justify-between text-sm py-2 border-b border-gray-100">
              <span className="text-gray-600">
                {payroll.employee?.rate_type === 'daily'
                  ? `Base Pay (${payroll.days_worked} days)`
                  : 'Base Salary'}
              </span>
              <span className="font-semibold text-gray-900">
                ₱{baseIncome.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </span>
            </div>
            {allowances.map((item, i) => (
              <div key={i} className="flex justify-between text-sm py-1">
                <span className="text-blue-700">{item.label}</span>
                <span className="font-medium text-blue-800">+₱{Number(item.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
              </div>
            ))}
            <div className="flex justify-between text-sm font-bold pt-2 border-t border-gray-200">
              <span className="text-gray-700">Gross Pay</span>
              <span className="text-gray-900">₱{Number(payroll.gross_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
            </div>
          </div>
        </div>

        <div className="card p-5">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Deductions</p>
          <div className="space-y-1.5">
            {deductions.length === 0 && (
              <p className="text-sm text-gray-400">No deductions</p>
            )}
            {deductions.map((item, i) => (
              <div key={i} className="flex justify-between text-sm py-1">
                <span className="text-red-700">{item.label}</span>
                <span className="font-medium text-red-800">-₱{Number(item.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
              </div>
            ))}
            <div className="flex justify-between text-sm font-bold pt-2 border-t border-gray-200">
              <span className="text-gray-700">Total Deductions</span>
              <span className="text-red-700">₱{totalDeductions.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
            </div>
          </div>
        </div>
      </div>

      {/* Attendance metrics */}
      <div className="card p-5">
        <p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Attendance Metrics</p>
        <div className="grid grid-cols-3 md:grid-cols-6 gap-4">
          {[
            { label: 'Work Hours', value: `${Number(payroll.total_hours ?? 0).toFixed(1)}h` },
            { label: 'Days Worked', value: `${payroll.days_worked ?? 0}d` },
            { label: 'Overtime', value: `${Number(payroll.overtime_hours ?? 0).toFixed(1)}h` },
            { label: 'Late', value: `${payroll.late_minutes ?? 0}m` },
            { label: 'Undertime', value: `${payroll.undertime_minutes ?? 0}m` },
            { label: 'Daily Rate', value: `₱${Number(payroll.daily_rate ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
          ].map(({ label, value }) => (
            <div key={label}>
              <p className="text-xs text-gray-400">{label}</p>
              <p className="text-sm font-bold text-gray-700 mt-0.5">{value}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Net pay */}
      <div className="card p-5 flex justify-between items-center bg-green-50 border border-green-100">
        <p className="text-sm font-semibold text-green-700">Net Pay</p>
        <p className="text-2xl font-black text-green-700">
          ₱{Number(payroll.net_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
        </p>
      </div>
    </div>
  )
}
