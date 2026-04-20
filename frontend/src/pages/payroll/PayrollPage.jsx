import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { format } from 'date-fns'
import { getPayrollRuns, runPayroll, payrollKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, FormField, Spinner } from '../../components/ui/index.jsx'
import { Plus, Eye, Banknote } from 'lucide-react'

export default function PayrollPage() {
  const [showModal, setShowModal] = useState(false)
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: payrollKeys.list(),
    queryFn: getPayrollRuns,
  })

  const { register, handleSubmit, reset, formState: { errors } } = useForm()

  const runMutation = useMutation({
    mutationFn: runPayroll,
    onSuccess: () => { qc.invalidateQueries({ queryKey: payrollKeys.all }); setShowModal(false); reset() },
  })

  const runs = data?.data ?? []

  return (
    <div>
      <PageHeader
        title="Payroll"
        description="Compute and manage payroll runs"
        action={
          <button onClick={() => setShowModal(true)} className="btn-primary">
            <Plus size={14} /> Run Payroll
          </button>
        }
      />

      {isLoading ? <PageSpinner /> : runs.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16 text-center">
          <Banknote size={40} className="text-gray-200 mb-3" />
          <p className="text-sm font-medium text-gray-600">No payroll runs yet</p>
          <p className="text-xs text-gray-400 mb-4">Run your first payroll to compute employee salaries</p>
          <button onClick={() => setShowModal(true)} className="btn-primary"><Plus size={14} /> Run Payroll</button>
        </div>
      ) : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                {['Period', 'Date Range', 'Employees', 'Status', 'Created', ''].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {runs.map(run => (
                <tr key={run.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{run.period_label}</td>
                  <td className="px-4 py-3 text-gray-500 text-xs">
                    {format(new Date(run.period_start), 'MMM d')} – {format(new Date(run.period_end), 'MMM d, yyyy')}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{run.items_count ?? '—'} employees</td>
                  <td className="px-4 py-3"><StatusBadge status={run.status} /></td>
                  <td className="px-4 py-3 text-gray-400 text-xs">{format(new Date(run.created_at), 'MMM d, yyyy')}</td>
                  <td className="px-4 py-3">
                    <Link to={`/payroll/${run.id}`} className="btn-ghost p-1.5" title="View"><Eye size={14} /></Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Run payroll modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title="Run Payroll" size="sm">
        <form onSubmit={handleSubmit(data => runMutation.mutate(data))} className="space-y-4">
          <FormField label="Period label" error={errors.period_label?.message} required>
            <input {...register('period_label', { required: 'Required' })} className="input"
              placeholder="e.g. April 2026 – 1st Half" />
          </FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Start date" error={errors.period_start?.message} required>
              <input type="date" {...register('period_start', { required: 'Required' })} className="input" />
            </FormField>
            <FormField label="End date" error={errors.period_end?.message} required>
              <input type="date" {...register('period_end', { required: 'Required' })} className="input" />
            </FormField>
          </div>
          <p className="text-xs text-gray-400">
            This will compute payroll for all active employees based on their attendance records.
          </p>
          {runMutation.isError && (
            <p className="text-xs text-red-500">{runMutation.error?.response?.data?.message}</p>
          )}
          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={() => setShowModal(false)} className="btn-secondary">Cancel</button>
            <button type="submit" disabled={runMutation.isPending} className="btn-primary">
              {runMutation.isPending ? <Spinner size="sm" /> : 'Compute payroll'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
