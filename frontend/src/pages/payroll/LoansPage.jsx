import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { getLoans, createLoan, loanKeys, getEmployees, employeeKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, FormField, Spinner, PagePagination } from '../../components/ui/index.jsx'
import { useAuth } from '../../store/AuthContext'
import { Plus, Wallet } from 'lucide-react'

const LOAN_TYPES = [
  { value: 'sss_salary',   label: 'SSS Salary Loan' },
  { value: 'sss_calamity', label: 'SSS Calamity Loan' },
  { value: 'pagibig_mpl',  label: 'Pag-IBIG MPL' },
]

function formatLoanType(type) {
  const known = { cash_advance: 'Cash Advance', company_loan: 'Company Loan', ...Object.fromEntries(LOAN_TYPES.map(t => [t.value, t.label])) }
  return known[type] ?? type
}

const EMPTY_FORM = { employee_id: '', loan_type: 'sss_salary', principal: '', installment_amount: '', term_count: '', start_cutoff: '', notes: '' }

export default function LoansPage() {
  const { user } = useAuth()
  const canManage = ['admin', 'hr', 'accounting'].includes(user?.role)
  const [statusFilter, setStatusFilter] = useState('active')
  const [page, setPage] = useState(1)
  const [createModal, setCreateModal] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [createError, setCreateError] = useState('')
  const qc = useQueryClient()

  const { data: loanData, isLoading } = useQuery({
    queryKey: loanKeys.list({ status: statusFilter, page }),
    queryFn: () => getLoans({ status: statusFilter || undefined, page }),
  })
  const { data: employees } = useQuery({
    queryKey: employeeKeys.list({ status: 'active' }),
    queryFn: () => getEmployees({ status: 'active' }),
    enabled: canManage,
  })

  const createMutation = useMutation({
    mutationFn: createLoan,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: loanKeys.all })
      setCreateModal(false)
      setForm(EMPTY_FORM)
      setCreateError('')
    },
    onError: (err) => setCreateError(err?.response?.data?.message || 'Failed to create loan'),
  })

  const loans = loanData?.data ?? []
  const pagination = loanData?.pagination
  const activeEmps = employees?.data ?? []
  const f = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const handleCreateSubmit = () => {
    setCreateError('')
    if (!form.employee_id || !form.principal || !form.installment_amount || !form.term_count || !form.start_cutoff) {
      return setCreateError('All fields except notes are required.')
    }
    createMutation.mutate({
      employee_id: form.employee_id,
      loan_type: form.loan_type,
      principal: Number(form.principal),
      installment_amount: Number(form.installment_amount),
      term_count: Number(form.term_count),
      start_cutoff: form.start_cutoff,
      notes: form.notes || null,
    })
  }

  return (
    <div>
      <PageHeader
        title="Loans"
        description={canManage ? 'Employee loans, cash advances, and government loan tracking' : 'Your loans and balances'}
        action={canManage && (
          <button onClick={() => setCreateModal(true)} className="btn-primary">
            <Plus size={14} /> New Government Loan
          </button>
        )}
      />

      <div className="flex flex-wrap items-center gap-3 mb-5">
        <div className="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
          {['active', 'paid_off', 'pending', 'rejected', 'cancelled'].map(s => (
            <button key={s} onClick={() => { setStatusFilter(s); setPage(1) }}
              className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors capitalize ${statusFilter === s ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>
              {s.replace('_', ' ')}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? <PageSpinner /> : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>{['Employee', 'Type', 'Principal', 'Installment', 'Balance', 'Status'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                ))}</tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {loans.map(loan => (
                  <tr key={loan.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{loan.employee?.first_name} {loan.employee?.last_name}</td>
                    <td className="px-4 py-3 text-gray-600">{formatLoanType(loan.loan_type)}</td>
                    <td className="px-4 py-3 text-gray-600">₱{Number(loan.principal).toLocaleString()}</td>
                    <td className="px-4 py-3 text-gray-600">₱{Number(loan.installment_amount).toLocaleString()}</td>
                    <td className="px-4 py-3 font-semibold text-gray-900">₱{Number(loan.balance).toLocaleString()}</td>
                    <td className="px-4 py-3"><StatusBadge status={loan.status} /></td>
                  </tr>
                ))}
                {loans.length === 0 && (
                  <tr><td colSpan={6} className="py-12 text-center">
                    <Wallet size={32} className="text-gray-200 mx-auto mb-2" />
                    <p className="text-sm text-gray-400">No {statusFilter.replace('_', ' ')} loans</p>
                  </td></tr>
                )}
              </tbody>
            </table>
          </div>
          {pagination && pagination.last_page > 1 && (
            <div className="px-4 py-3 border-t border-gray-100 bg-gray-50">
              <PagePagination pagination={pagination} onPageChange={setPage} />
            </div>
          )}
        </div>
      )}

      {canManage && (
      <Modal open={createModal} onClose={() => setCreateModal(false)} title="New Government Loan"
        footer={
          <div className="flex justify-end gap-2">
            <button onClick={() => setCreateModal(false)} className="btn-secondary">Cancel</button>
            <button onClick={handleCreateSubmit} disabled={createMutation.isPending} className="btn-primary">{createMutation.isPending ? <Spinner size="sm" /> : 'Create'}</button>
          </div>
        }
      >
        <div className="space-y-4">
          <FormField label="Employee" required>
            <select value={form.employee_id} onChange={e => f('employee_id', e.target.value)} className="input">
              <option value="">Select employee…</option>
              {activeEmps.map(e => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
            </select>
          </FormField>
          <FormField label="Loan Type" required>
            <select value={form.loan_type} onChange={e => f('loan_type', e.target.value)} className="input">
              {LOAN_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </FormField>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormField label="Principal (₱)" required><input type="number" min="1" step="0.01" value={form.principal} onChange={e => f('principal', e.target.value)} className="input" /></FormField>
            <FormField label="Installment per Cutoff (₱)" required><input type="number" min="1" step="0.01" value={form.installment_amount} onChange={e => f('installment_amount', e.target.value)} className="input" /></FormField>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormField label="Term (number of cutoffs)" required><input type="number" min="1" step="1" value={form.term_count} onChange={e => f('term_count', e.target.value)} className="input" /></FormField>
            <FormField label="Start Cutoff" required><input type="date" value={form.start_cutoff} onChange={e => f('start_cutoff', e.target.value)} className="input" /></FormField>
          </div>
          <FormField label="Notes"><textarea value={form.notes} onChange={e => f('notes', e.target.value)} className="input h-16 resize-none" /></FormField>
          {createError && <p className="text-sm text-red-600">{createError}</p>}
        </div>
      </Modal>
      )}
    </div>
  )
}
