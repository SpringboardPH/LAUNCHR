import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { format } from 'date-fns'
import { getPayrollRun, exportPayroll, payrollKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge } from '../../components/ui/index.jsx'
import { ArrowLeft, Download } from 'lucide-react'

export default function PayrollDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const { data: run, isLoading } = useQuery({
    queryKey: payrollKeys.detail(id),
    queryFn: () => getPayrollRun(id),
  })

  if (isLoading) return <PageSpinner />
  if (!run) return <p className="text-sm text-gray-500">Payroll run not found.</p>

  const totalGross = run.items?.reduce((s, i) => s + Number(i.gross_pay), 0) ?? 0
  const totalNet = run.items?.reduce((s, i) => s + Number(i.net_pay), 0) ?? 0
  const totalDeductions = totalGross - totalNet

  return (
    <div>
      <PageHeader
        title={run.period_label}
        description={`${format(new Date(run.period_start), 'MMM d')} – ${format(new Date(run.period_end), 'MMM d, yyyy')}`}
        action={
          <div className="flex gap-2">
            <button onClick={() => navigate('/payroll')} className="btn-secondary">
              <ArrowLeft size={14} /> Back
            </button>
            <button
              onClick={() => exportPayroll(id, run.period_label)}
              className="btn-primary"
            >
              <Download size={14} /> Export CSV
            </button>
          </div>
        }
      />

      {/* Summary cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        {[
          { label: 'Employees', value: run.items?.length ?? 0 },
          { label: 'Total Gross Pay', value: `₱${totalGross.toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
          { label: 'Total Deductions', value: `₱${totalDeductions.toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
          { label: 'Total Net Pay', value: `₱${totalNet.toLocaleString('en-PH', { minimumFractionDigits: 2 })}` },
        ].map(({ label, value }) => (
          <div key={label} className="card p-4">
            <p className="text-xs text-gray-400 mb-1">{label}</p>
            <p className="text-lg font-semibold text-gray-900">{value}</p>
          </div>
        ))}
      </div>

      {/* Status + meta */}
      <div className="flex items-center gap-3 mb-5">
        <StatusBadge status={run.status} />
        <span className="text-xs text-gray-400">
          Created {format(new Date(run.created_at), 'MMM d, yyyy h:mm a')}
        </span>
      </div>

      {/* Items table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                {[
                  'Employee', 'Basic Salary', 'Days Worked', 'Days Absent',
                  'Gross Pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Net Pay'
                ].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {run.items?.map(item => (
                <tr key={item.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">
                      {item.employee?.first_name} {item.employee?.last_name}
                    </p>
                    <p className="text-xs text-gray-400 font-mono">{item.employee?.employee_id}</p>
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    ₱{Number(item.basic_salary).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{item.days_worked}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {item.days_absent > 0
                      ? <span className="text-red-500">{item.days_absent}</span>
                      : item.days_absent}
                  </td>
                  <td className="px-4 py-3 text-gray-700 font-medium">
                    ₱{Number(item.gross_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-4 py-3 text-gray-500 text-xs">
                    ₱{Number(item.sss_deduction).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-4 py-3 text-gray-500 text-xs">
                    ₱{Number(item.philhealth_deduction).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-4 py-3 text-gray-500 text-xs">
                    ₱{Number(item.pagibig_deduction).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-4 py-3 font-semibold text-brand-700">
                    ₱{Number(item.net_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                </tr>
              ))}
              {!run.items?.length && (
                <tr>
                  <td colSpan={9} className="py-10 text-center text-sm text-gray-400">
                    No payroll items found
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
