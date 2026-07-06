import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, ArrowRight, CheckCircle, AlertCircle } from 'lucide-react'
import {
  getThirteenthMonthPeriods, pushThirteenthMonthToPayroll,
  getEmployees, employeeKeys, thirteenthMonthKeys, payrollKeys,
} from '../../api/queries'
import { format, parseISO } from 'date-fns'

function fmtPeriod(start, end) {
  try { return `${format(parseISO(start), 'MMM d')} – ${format(parseISO(end), 'MMM d, yyyy')}` }
  catch { return `${start} – ${end}` }
}

export default function PushToPayrollModal({ year, onClose }) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState('')
  const [scope, setScope] = useState('all') // 'all' | 'specific'
  const [employeeId, setEmployeeId] = useState('')
  const [result, setResult] = useState(null)

  const { data: periods = [], isLoading: periodsLoading } = useQuery({
    queryKey: [thirteenthMonthKeys.all, 'periods', year],
    queryFn: () => getThirteenthMonthPeriods(year),
  })

  const { data: empData, isLoading: empLoading } = useQuery({
    queryKey: employeeKeys.list({ status: 'active', per_page: 1000 }),
    queryFn: () => getEmployees({ status: 'active', per_page: 1000 }),
    enabled: scope === 'specific',
  })

  const employees = empData?.data ?? []

  const push = useMutation({
    mutationFn: pushThirteenthMonthToPayroll,
    onSuccess: (data) => {
      setResult(data)
      queryClient.invalidateQueries({ queryKey: payrollKeys.all })
    },
  })

  const handleSubmit = () => {
    if (!selected) return
    const [cutoff_start, cutoff_end] = selected.split('|')
    const payload = { year, cutoff_start, cutoff_end }
    if (scope === 'specific' && employeeId) payload.employee_ids = [Number(employeeId)]
    push.mutate(payload)
  }

  const canSubmit = selected && (scope === 'all' || (scope === 'specific' && employeeId)) && !push.isPending

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 overflow-hidden">

        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div>
            <h2 className="text-sm font-semibold text-gray-900">Push to Payroll</h2>
            <p className="text-xs text-gray-500 mt-0.5">
              Adds 13th month as an allowance on existing draft payrolls.
            </p>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 transition-colors">
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-4 space-y-4">
          {result ? (
            /* ── Result view ── */
            <div className="space-y-3">
              <div className="flex items-start gap-3 p-3 rounded-lg bg-green-50 border border-green-200">
                <CheckCircle size={16} className="text-green-600 mt-0.5 shrink-0" />
                <div className="text-sm text-green-800">
                  <p className="font-medium">{result.pushed} payroll{result.pushed !== 1 ? 's' : ''} updated</p>
                  <p className="text-xs mt-0.5 text-green-700">{result.message}</p>
                </div>
              </div>
              {result.skipped?.length > 0 && (
                <div className="p-3 rounded-lg bg-amber-50 border border-amber-200">
                  <div className="flex items-center gap-2 mb-1.5">
                    <AlertCircle size={14} className="text-amber-600 shrink-0" />
                    <span className="text-xs font-medium text-amber-800">No draft payroll found for:</span>
                  </div>
                  <ul className="text-xs text-amber-700 space-y-0.5 pl-5 list-disc">
                    {result.skipped.map((name, i) => <li key={i}>{name}</li>)}
                  </ul>
                </div>
              )}
            </div>
          ) : (
            /* ── Selection view ── */
            <>
              {/* Scope toggle */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1.5">Push for</label>
                <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-medium">
                  {[['all', 'All employees'], ['specific', 'Specific employee']].map(([val, label]) => (
                    <button
                      key={val}
                      onClick={() => setScope(val)}
                      className={`flex-1 py-2 transition-colors ${
                        scope === val
                          ? 'bg-brand-600 text-white'
                          : 'bg-white text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Employee picker (specific mode) */}
              {scope === 'specific' && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1.5">Employee</label>
                  {empLoading ? (
                    <div className="text-xs text-gray-400 py-1">Loading…</div>
                  ) : (
                    <select
                      className="input w-full"
                      value={employeeId}
                      onChange={e => setEmployeeId(e.target.value)}
                    >
                      <option value="">Select employee…</option>
                      {employees.map(emp => (
                        <option key={emp.id} value={emp.id}>
                          {emp.first_name} {emp.last_name} ({emp.employee_id})
                        </option>
                      ))}
                    </select>
                  )}
                </div>
              )}

              {/* Period picker */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1.5">Target cutoff period</label>
                {periodsLoading ? (
                  <div className="text-xs text-gray-400 py-1">Loading draft periods…</div>
                ) : periods.length === 0 ? (
                  <div className="text-xs text-gray-500 py-2 px-3 bg-gray-50 rounded-lg border border-gray-200">
                    No draft payrolls for {year}. Generate a payroll run first.
                  </div>
                ) : (
                  <select
                    className="input w-full"
                    value={selected}
                    onChange={e => setSelected(e.target.value)}
                  >
                    <option value="">Select a period…</option>
                    {periods.map(p => (
                      <option key={`${p.cutoff_start}|${p.cutoff_end}`} value={`${p.cutoff_start}|${p.cutoff_end}`}>
                        {fmtPeriod(p.cutoff_start, p.cutoff_end)}
                      </option>
                    ))}
                  </select>
                )}
              </div>

              <p className="text-xs text-gray-400">
                Finalized or paid payrolls are not modified. If an employee already has a
                "13th Month Pay" allowance entry, it will be updated to the current computed amount.
              </p>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-2 px-5 py-3 border-t border-gray-100 bg-gray-50">
          <button onClick={onClose} className="btn-ghost text-sm">
            {result ? 'Close' : 'Cancel'}
          </button>
          {!result && (
            <button
              onClick={handleSubmit}
              disabled={!canSubmit}
              className="btn-primary flex items-center gap-1.5 text-sm"
            >
              {push.isPending ? 'Pushing…' : (<>Push <ArrowRight size={13} /></>)}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
