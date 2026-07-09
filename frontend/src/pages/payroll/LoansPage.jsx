import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { getLoans, getLoan, createLoan, updateLoan, deleteLoan, loanKeys, getEmployees, employeeKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, ConfirmModal, FormField, Spinner, PagePagination } from '../../components/ui/index.jsx'
import { useAuth } from '../../store/AuthContext'
import { Plus, Wallet, Eye, Pencil, Ban } from 'lucide-react'

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
  const [viewLoanId, setViewLoanId] = useState(null)
  const [editLoan, setEditLoan] = useState(null)
  const [editForm, setEditForm] = useState(null)
  const [editError, setEditError] = useState('')
  const [cancelLoan, setCancelLoan] = useState(null)
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
  const { data: viewedLoan, isLoading: isLoadingView } = useQuery({
    queryKey: loanKeys.detail(viewLoanId),
    queryFn: () => getLoan(viewLoanId),
    enabled: !!viewLoanId,
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

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateLoan(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: loanKeys.all })
      setEditLoan(null)
      setEditForm(null)
      setEditError('')
    },
    onError: (err) => setEditError(err?.response?.data?.message || 'Failed to update loan'),
  })

  const cancelMutation = useMutation({
    mutationFn: deleteLoan,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: loanKeys.all })
      setCancelLoan(null)
    },
  })

  const loans = loanData?.data ?? []
  const pagination = loanData?.pagination
  const activeEmps = employees?.data ?? []
  const f = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const ef = (k, v) => setEditForm(p => ({ ...p, [k]: v }))

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

  const openEdit = (loan) => {
    setEditError('')
    setEditLoan(loan)
    setEditForm({
      principal: String(loan.principal),
      interest_rate: String(loan.interest_rate ?? 0),
      term_count: String(loan.term_count),
      installment_amount: String(loan.installment_amount),
      start_cutoff: loan.start_cutoff?.slice(0, 10) ?? '',
      notes: loan.notes ?? '',
      status: loan.status === 'cancelled' ? 'cancelled' : 'active',
    })
  }

  const isUnpaid = editLoan && Number(editLoan.balance) === Number(editLoan.total_payable)

  const handleEditSubmit = () => {
    setEditError('')
    if (!editForm.installment_amount || !editForm.start_cutoff) {
      return setEditError('Installment amount and start cutoff are required.')
    }
    const data = {
      installment_amount: Number(editForm.installment_amount),
      start_cutoff: editForm.start_cutoff,
      notes: editForm.notes || null,
      status: editForm.status,
    }
    if (isUnpaid) {
      data.principal = Number(editForm.principal)
      data.interest_rate = editForm.interest_rate ? Number(editForm.interest_rate) : 0
      data.term_count = Number(editForm.term_count)
    }
    updateMutation.mutate({ id: editLoan.id, data })
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
                <tr>{['Employee', 'Type', 'Principal', 'Installment', 'Balance', 'Status', 'Actions'].map(h => (
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
                    <td className="px-4 py-3">
                      <div className="flex gap-1">
                        <button onClick={() => setViewLoanId(loan.id)} className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50" title="View"><Eye size={14} /></button>
                        {canManage && <>
                          <button onClick={() => openEdit(loan)} className="btn-ghost p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100" title="Edit"><Pencil size={14} /></button>
                          {loan.status === 'active' && (
                            <button onClick={() => setCancelLoan(loan)} className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50" title="Cancel"><Ban size={14} /></button>
                          )}
                        </>}
                      </div>
                    </td>
                  </tr>
                ))}
                {loans.length === 0 && (
                  <tr><td colSpan={7} className="py-12 text-center">
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

      {/* View Loan modal */}
      <Modal open={!!viewLoanId} onClose={() => setViewLoanId(null)} title="Loan Details" size="md">
        {isLoadingView ? <PageSpinner /> : viewedLoan && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Employee</p><p className="text-sm font-semibold text-gray-900">{viewedLoan.employee?.first_name} {viewedLoan.employee?.last_name}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p><StatusBadge status={viewedLoan.status} /></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p><p className="text-sm text-gray-700">{formatLoanType(viewedLoan.loan_type)}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Start Cutoff</p><p className="text-sm text-gray-700">{viewedLoan.start_cutoff ? format(new Date(viewedLoan.start_cutoff), 'MMM d, yyyy') : '—'}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Principal</p><p className="text-sm text-gray-700">₱{Number(viewedLoan.principal).toLocaleString()}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Interest Rate</p><p className="text-sm text-gray-700">{(Number(viewedLoan.interest_rate) * 100).toFixed(2)}%</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Installment / Cutoff</p><p className="text-sm text-gray-700">₱{Number(viewedLoan.installment_amount).toLocaleString()}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Total Payable</p><p className="text-sm text-gray-700">₱{Number(viewedLoan.total_payable).toLocaleString()}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Balance</p><p className="text-sm font-semibold text-gray-900">₱{Number(viewedLoan.balance).toLocaleString()}</p></div>
              <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Approved By</p><p className="text-sm text-gray-700">{viewedLoan.approver?.name ?? '—'}</p></div>
            </div>
            {viewedLoan.notes && <div><p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Notes</p><div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700">{viewedLoan.notes}</div></div>}
            <div>
              <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Payment History</p>
              {viewedLoan.payments?.length > 0 ? (
                <div className="border border-gray-100 rounded-lg divide-y divide-gray-100">
                  {viewedLoan.payments.map(p => (
                    <div key={p.id} className="flex justify-between items-center px-3 py-2 text-sm">
                      <span className="text-gray-600">{format(new Date(p.cutoff_start), 'MMM d')} – {format(new Date(p.cutoff_end), 'MMM d, yyyy')}</span>
                      <span className="font-medium text-gray-900">₱{Number(p.amount).toLocaleString()}</span>
                    </div>
                  ))}
                </div>
              ) : <p className="text-sm text-gray-400">No payments deducted yet.</p>}
            </div>
            <div className="flex justify-end pt-2"><button onClick={() => setViewLoanId(null)} className="btn-primary px-6">Close</button></div>
          </div>
        )}
      </Modal>

      {/* Edit Loan modal */}
      <Modal open={!!editLoan} onClose={() => { setEditLoan(null); setEditForm(null) }} title="Edit Loan"
        footer={
          <div className="flex justify-end gap-2">
            <button onClick={() => { setEditLoan(null); setEditForm(null) }} className="btn-secondary">Cancel</button>
            <button onClick={handleEditSubmit} disabled={updateMutation.isPending} className="btn-primary">{updateMutation.isPending ? <Spinner size="sm" /> : 'Save'}</button>
          </div>
        }
      >
        {editForm && (
          <div className="space-y-4">
            {!isUnpaid && (
              <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                Principal, interest rate, and term are locked — payments have already been deducted against this loan.
              </p>
            )}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label="Principal (₱)"><input type="number" min="1" step="0.01" disabled={!isUnpaid} value={editForm.principal} onChange={e => ef('principal', e.target.value)} className="input disabled:bg-gray-50 disabled:text-gray-400" /></FormField>
              <FormField label="Interest Rate"><input type="number" min="0" max="1" step="0.01" disabled={!isUnpaid} value={editForm.interest_rate} onChange={e => ef('interest_rate', e.target.value)} className="input disabled:bg-gray-50 disabled:text-gray-400" /></FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label="Term (cutoffs)"><input type="number" min="1" step="1" disabled={!isUnpaid} value={editForm.term_count} onChange={e => ef('term_count', e.target.value)} className="input disabled:bg-gray-50 disabled:text-gray-400" /></FormField>
              <FormField label="Installment / Cutoff (₱)" required><input type="number" min="1" step="0.01" value={editForm.installment_amount} onChange={e => ef('installment_amount', e.target.value)} className="input" /></FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label="Start Cutoff" required><input type="date" value={editForm.start_cutoff} onChange={e => ef('start_cutoff', e.target.value)} className="input" /></FormField>
              <FormField label="Status">
                <select value={editForm.status} onChange={e => ef('status', e.target.value)} className="input">
                  <option value="active">Active</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </FormField>
            </div>
            <FormField label="Notes"><textarea value={editForm.notes} onChange={e => ef('notes', e.target.value)} className="input h-16 resize-none" /></FormField>
            {editError && <p className="text-sm text-red-600">{editError}</p>}
          </div>
        )}
      </Modal>

      {/* Cancel Loan confirm */}
      <ConfirmModal
        open={!!cancelLoan}
        onClose={() => setCancelLoan(null)}
        onConfirm={() => cancelMutation.mutate(cancelLoan.id)}
        title="Cancel Loan"
        message={`Cancel this loan for ${cancelLoan?.employee?.first_name} ${cancelLoan?.employee?.last_name}? Future payroll runs will stop deducting it.`}
        type="danger"
        confirmLabel="Cancel Loan"
      />
    </div>
  )
}
