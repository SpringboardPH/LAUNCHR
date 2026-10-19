import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Users, ChevronDown, AlertTriangle } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getBirDraftEmployees } from '../../api/queries'
import { peso } from './BirDraftEditor'

const CATEGORY = {
  mwe: ['Minimum wage', 'badge-blue'],
  exempt_250k: ['₱250k or less', 'badge-gray'],
  taxable: ['Taxable', 'badge-green'],
}

// Item numbers next to each column, as they add up on the 1601-C.
const COLUMNS = [
  ['compensation', 'Pay', '14'],
  ['ee', 'Contributions', '19'],
  ['taxable', 'After Contributions', '22'],
  ['tax_withheld', 'Tax withheld', '25'],
]

/**
 * Who is on a 1601-C (GET /bir/drafts/{id}/employees): each employee's share of the totals,
 * frozen when the draft was made, and any item where they don't add up to the form.
 */
export default function BirFormEmployees({ draftId }) {
  const [open, setOpen] = useState(false)
  const { data, isLoading, isError } = useQuery({
    queryKey: birKeys.formEmployees(draftId),
    queryFn: () => getBirDraftEmployees(draftId),
    enabled: open,
  })
  const list = data?.data
  const rows = list?.employees ?? []

  return (
    <div className="border-b border-gray-100">
      <button
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        className="w-full px-4 py-2.5 flex items-center gap-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
      >
        <Users size={13} className="text-brand-600" /> Employees on this form
        {list?.employees && <span className="text-gray-400 font-normal">({rows.length})</span>}
        <ChevronDown size={13} className={clsx('ml-auto text-gray-400 transition-transform', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="px-4 pb-3 space-y-2">
          {isLoading && <p className="text-xs text-gray-400">Loading employees…</p>}
          {isError && <p className="text-xs text-red-600">Could not load the employee list.</p>}
          {list && !list.employees && <p className="text-xs text-gray-500">{data.message}</p>}

          {list?.differences?.length > 0 && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">
              <p className="flex items-center gap-1 font-medium"><AlertTriangle size={12} /> The employees don't add up to the form here:</p>
              <ul className="mt-1 space-y-0.5">
                {list.differences.map(d => (
                  <li key={d.key}>
                    Item {d.item}: employees {peso(d.employees_total)}, form {d.form_value == null ? 'blank' : peso(d.form_value)}
                    {d.difference != null && ` (${Number(d.difference) > 0 ? 'form higher' : 'form lower'} by ${peso(Math.abs(d.difference))})`}
                  </li>
                ))}
              </ul>
              <p className="mt-1 text-amber-700">Usually a figure changed by hand, item 20, or ₱0.01 of rounding.</p>
            </div>
          )}

          {list?.employees && (rows.length === 0 ? (
            <p className="text-xs text-gray-500">No payroll was counted for this month.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-[11px]">
                <thead>
                  <tr className="text-left text-gray-400">
                    <th className="py-1 pr-2 font-medium">Employee</th>
                    {COLUMNS.map(([key, label, item]) => (
                      <th key={key} className="py-1 px-2 font-medium text-right whitespace-nowrap">{label} <span className="text-gray-300">({item})</span></th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {rows.map(r => (
                    <tr key={r.employee_id}>
                      <td className="py-1.5 pr-2">
                        <p className="font-medium text-gray-800">{r.name ?? `Employee #${r.employee_id}`}</p>
                        <p className="text-gray-400">
                          {r.tin ?? 'No TIN'} · {r.payroll_count} payroll{r.payroll_count === 1 ? '' : 's'}{' '}
                          <span className={clsx(CATEGORY[r.category]?.[1] ?? 'badge-gray', 'ml-1')}>{CATEGORY[r.category]?.[0] ?? r.category}</span>
                        </p>
                      </td>
                      {COLUMNS.map(([key]) => <td key={key} className="py-1.5 px-2 text-right tabular-nums">{peso(r[key])}</td>)}
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t border-gray-200 font-semibold text-gray-800">
                    <td className="py-1.5 pr-2">Total</td>
                    {COLUMNS.map(([key]) => <td key={key} className="py-1.5 px-2 text-right tabular-nums">{peso(list.totals[key])}</td>)}
                  </tr>
                </tfoot>
              </table>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
