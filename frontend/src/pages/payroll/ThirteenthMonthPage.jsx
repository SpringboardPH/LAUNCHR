import React, { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Save, RefreshCw, Copy, Check, Upload, ChevronRight, ChevronDown, X } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import {
  thirteenthMonthKeys, getThirteenthMonth, saveThirteenthMonth,
  setThirteenthMonthMode, setThirteenthMonthExcludedMonths, employeeKeys, getEmployeeGroups,
} from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import PushToPayrollModal from './PushToPayrollModal'

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
const CY = new Date().getFullYear()
const YEAR_OPTIONS = Array.from({ length: 5 }, (_, i) => CY - i)

const fmt = (n) =>
  n == null || n === '' ? null
  : Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const fmtRange = (start, end) => {
  try {
    const s = parseISO(start), e = parseISO(end)
    return s.getMonth() === e.getMonth()
      ? `${format(s, 'MMM d')}–${format(e, 'd')}`
      : `${format(s, 'MMM d')}–${format(e, 'MMM d')}`
  } catch { return `${start}–${end}` }
}

function CopyCell({ value }) {
  const [copied, setCopied] = useState(false)
  const copy = () => {
    navigator.clipboard.writeText(value.toFixed(2))
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }
  return (
    <div className="flex items-center justify-end gap-1.5 group/copy">
      <span className="font-semibold text-brand-700 tabular-nums">{fmt(value)}</span>
      <button
        onClick={copy}
        title="Copy"
        className="opacity-0 group-hover/copy:opacity-100 transition-opacity p-0.5 rounded text-brand-400 hover:text-brand-600 hover:bg-brand-100"
      >
        {copied ? <Check size={12} className="text-green-500" /> : <Copy size={12} />}
      </button>
    </div>
  )
}

// Inline editable cell — shows formatted text, becomes an input on click
function MonthCell({ value, isOverride, hasPayroll, disabled, excluded, onChange }) {
  const [editing, setEditing] = useState(false)
  const [raw, setRaw] = useState('')
  const inputRef = useRef(null)

  const startEdit = () => {
    setRaw(value != null ? String(value) : '')
    setEditing(true)
  }

  useEffect(() => {
    if (editing) inputRef.current?.select()
  }, [editing])

  const commit = () => {
    setEditing(false)
    const parsed = raw === '' ? null : parseFloat(raw)
    onChange(isNaN(parsed) ? null : parsed)
  }

  const bg = isOverride
    ? 'bg-amber-50 ring-1 ring-amber-300'
    : hasPayroll
      ? 'bg-blue-50 ring-1 ring-blue-200'
      : 'bg-gray-50 ring-1 ring-gray-200'

  const textColor = isOverride ? 'text-amber-800' : hasPayroll ? 'text-blue-800' : 'text-gray-400'

  if (editing) {
    return (
      <input
        ref={inputRef}
        type="number"
        step="0.01"
        min="0"
        value={raw}
        onChange={e => setRaw(e.target.value)}
        onBlur={commit}
        onKeyDown={e => { if (e.key === 'Enter') commit(); if (e.key === 'Escape') setEditing(false) }}
        className={`w-full text-right text-xs px-2 py-1.5 rounded outline-none ring-2 ring-brand-400 bg-white tabular-nums
          [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none`}
        autoFocus
      />
    )
  }

  return (
    <button
      onClick={disabled ? undefined : startEdit}
      className={`w-full text-right text-xs px-2 py-1.5 rounded tabular-nums transition-opacity ${bg} ${textColor}
        ${disabled ? 'cursor-default opacity-30' : 'hover:opacity-80 cursor-text'}
        ${excluded ? 'line-through opacity-40' : ''}`}
    >
      {value != null ? fmt(value) : <span className="text-gray-300 select-none">—</span>}
    </button>
  )
}

export default function ThirteenthMonthPage() {
  const [year, setYear] = useState(CY)
  const [group, setGroup] = useState('')
  const [page, setPage] = useState(1)
  const [showPush, setShowPush] = useState(false)
  const [expanded, setExpanded] = useState(new Set())
  const [overrides, setOverrides] = useState({})
  const [excludedLocal, setExcludedLocal] = useState({}) // empId -> array of excluded month numbers
  const [dirty, setDirty] = useState(false)
  const qc = useQueryClient()

  useEffect(() => { setPage(1); setOverrides({}); setExcludedLocal({}); setDirty(false); setExpanded(new Set()) }, [year, group])
  useEffect(() => { setOverrides({}); setExcludedLocal({}); setDirty(false); setExpanded(new Set()) }, [page])

  const params = { year, page, ...(group && { group }) }

  const { data, isLoading, refetch } = useQuery({
    queryKey: thirteenthMonthKeys.list(params),
    queryFn: () => getThirteenthMonth(params),
  })

  const { data: groups = [] } = useQuery({
    queryKey: employeeKeys.groups,
    queryFn: getEmployeeGroups,
  })

  const saveMut = useMutation({
    mutationFn: saveThirteenthMonth,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: thirteenthMonthKeys.list(params) })
      setOverrides({})
      setDirty(false)
    },
  })

  const modeMut = useMutation({
    mutationFn: setThirteenthMonthMode,
    onSuccess: () => {
      setOverrides({})
      setDirty(false)
      qc.invalidateQueries({ queryKey: thirteenthMonthKeys.list(params) })
    },
  })

  const excludeMut = useMutation({
    mutationFn: setThirteenthMonthExcludedMonths,
    onSuccess: () => qc.invalidateQueries({ queryKey: thirteenthMonthKeys.list(params) }),
  })

  const employees = data?.data ?? []

  const getExcluded = (emp) => excludedLocal[emp.id] ?? emp.excluded_months ?? []
  const isExcluded = (emp, m) => getExcluded(emp).includes(m)
  const toggleExclude = (emp, m) => {
    const curr = getExcluded(emp)
    const next = curr.includes(m) ? curr.filter(x => x !== m) : [...curr, m].sort((a, b) => a - b)
    setExcludedLocal(prev => ({ ...prev, [emp.id]: next }))
    excludeMut.mutate({ employee_id: emp.id, year, months: next })
  }

  const cellKey = (empId, m) => `${empId}-${m}`

  const getVal = (emp, m) => {
    const k = cellKey(emp.id, m)
    if (k in overrides) return overrides[k]
    const cell = emp.months[m]
    return cell?.amount ?? null
  }

  const getIsOverride = (emp, m) => {
    const k = cellKey(emp.id, m)
    if (k in overrides) return overrides[k] != null
    return emp.months[m]?.is_override ?? false
  }

  const getHasPayroll = (emp, m) => {
    const k = cellKey(emp.id, m)
    if (k in overrides) return false
    return emp.months[m]?.has_payroll ?? false
  }

  const compute13th = (emp) => {
    const excl = getExcluded(emp)
    let sum = 0
    for (let m = 1; m <= 12; m++) {
      if (excl.includes(m)) continue
      sum += getVal(emp, m) || 0
    }
    return sum / 12
  }

  const handleChange = (emp, m, val) => {
    setOverrides(prev => ({ ...prev, [cellKey(emp.id, m)]: val }))
    setDirty(true)
  }

  const handleRefresh = () => { setOverrides({}); setDirty(false); refetch() }

  const handleSave = () => {
    const records = []
    for (const emp of employees) {
      for (let m = 1; m <= 12; m++) {
        const k = cellKey(emp.id, m)
        if (!(k in overrides)) continue
        const val = overrides[k]
        records.push({
          employee_id: emp.id,
          month: m,
          basic_pay: val == null ? null : val,
          is_override: val != null,
        })
      }
    }
    if (records.length) saveMut.mutate({ year, records })
    else { setOverrides({}); setDirty(false) }
  }

  return (
    <div>
      <PageHeader
        title="13th Month Pay"
        description="Click any cell to override a monthly value. Untick a month to exclude it from that employee's total. 13th month = sum of included months ÷ 12 (DOLE formula)."
        help={[
          { heading: 'Year & Group Filters', items: [
            'Use the year dropdown to select the calendar year for 13th month computation.',
            'Use the group dropdown to filter the employee list by group.',
          ]},
          { heading: 'Auto vs Manual Mode', items: [
            'Auto mode pulls basic pay amounts directly from finalized payroll records.',
            'Manual mode lets you type in per-month amounts directly for each employee.',
            'Click "Refresh from Payroll" to re-sync auto values from payroll records.',
          ]},
          { heading: 'Editing Amounts', items: [
            'In Manual mode, click any amount cell to type a value override.',
            'Overridden cells are highlighted so you can tell which values were manually set.',
          ]},
          { heading: 'Including / Excluding Months', items: [
            'Hover an amount cell and click "exclude" to leave that month out of the employee\'s 13th month total.',
            'Excluded months show struck-through with an amber "excluded" tag — click the tag to include them again.',
            'The selection is per employee and saves immediately — different employees can include different months.',
            'The divisor stays ÷ 12 (DOLE), so excluding a month lowers the total.',
          ]},
          { heading: 'Total Column', items: [
            'The rightmost column shows the computed 13th month pay: sum of included monthly values ÷ 12.',
            'Hover over a total to copy the exact value to your clipboard.',
          ]},
          { heading: 'Save & Push to Payroll', items: [
            'Click Save to persist any manual overrides or mode changes.',
            'Click Push to Payroll to add the computed 13th month amounts as allowances in a selected payroll run.',
          ]},
        ]}
      />

      {/* Controls bar */}
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <select className="input w-28" value={year} onChange={e => setYear(Number(e.target.value))}>
          {YEAR_OPTIONS.map(y => <option key={y} value={y}>{y}</option>)}
        </select>
        <select className="input w-44" value={group} onChange={e => setGroup(e.target.value)}>
          <option value="">All groups</option>
          {groups.map(g => <option key={g} value={g}>{g}</option>)}
        </select>
        <button onClick={handleRefresh} className="btn-ghost flex items-center gap-1.5 text-sm">
          <RefreshCw size={13} /> Refresh from Payroll
        </button>
        <button onClick={() => setShowPush(true)} className="btn-ghost flex items-center gap-1.5 text-sm text-brand-600 hover:text-brand-700">
          <Upload size={13} /> Push to Payroll
        </button>

        <div className="flex items-center gap-3 ml-auto text-xs text-gray-500">
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-3 rounded bg-blue-100 ring-1 ring-blue-200 inline-block" />
            Auto-filled
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-3 rounded bg-amber-100 ring-1 ring-amber-300 inline-block" />
            Manual
          </span>
          <span className="flex items-center gap-1.5 border-l border-gray-200 pl-3">
            <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">D</span>
            Declared
          </span>
          <span className="flex items-center gap-1.5">
            <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-violet-50 text-violet-700 border border-violet-200">U</span>
            Undeclared
          </span>
          <span className="flex items-center gap-1.5 border-l border-gray-200 pl-3">
            <span className="px-1.5 py-0.5 rounded text-[9px] font-semibold uppercase bg-amber-100 text-amber-700">excluded</span>
            Left out of total
          </span>
          {dirty && (
            <div className="flex items-center gap-2 ml-2">
              <button onClick={() => { setOverrides({}); setDirty(false) }} className="btn-ghost text-gray-500">
                Cancel
              </button>
              <button onClick={handleSave} disabled={saveMut.isPending} className="btn-primary flex items-center gap-1.5">
                <Save size={13} /> {saveMut.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          )}
        </div>
      </div>

      {isLoading ? <PageSpinner /> : (<>
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-3 py-2.5 text-left font-medium text-gray-600 sticky left-0 bg-gray-50 z-10 min-w-[180px] border-r border-gray-200">
                    Employee
                  </th>
                  <th className="px-2 py-2.5 text-center font-medium text-gray-600 w-12">
                    Mode
                  </th>
                  {MONTHS.map((label, i) => {
                    const m = i + 1
                    return (
                      <th key={m} className="px-1.5 py-2.5 text-center font-medium min-w-[96px] text-gray-700">
                        {label}
                      </th>
                    )
                  })}
                  <th className="px-3 py-2.5 text-right font-semibold text-brand-700 bg-brand-50 min-w-[120px] border-l border-brand-100">
                    13th Month
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {employees.length === 0 ? (
                  <tr>
                    <td colSpan={15} className="px-4 py-12 text-center text-gray-400">
                      No active employees found.
                    </td>
                  </tr>
                ) : employees.map(emp => {
                  const thirteenth = compute13th(emp)
                  const isExpanded = expanded.has(emp.id)
                  const toggleExpand = () => setExpanded(prev => {
                    const next = new Set(prev)
                    next.has(emp.id) ? next.delete(emp.id) : next.add(emp.id)
                    return next
                  })
                  return (
                    <React.Fragment key={emp.id}>
                    <tr className="hover:bg-gray-50/60 group">
                      <td className="px-3 py-2.5 sticky left-0 bg-white group-hover:bg-gray-50/60 border-r border-gray-100 z-10">
                        <div className="flex items-center gap-1.5">
                          <button onClick={toggleExpand} className="text-gray-400 hover:text-brand-600 transition-colors shrink-0">
                            {isExpanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                          </button>
                          <div>
                            <div className="font-medium text-gray-900 leading-tight">{emp.name}</div>
                            <div className="text-[11px] text-gray-400 mt-0.5">
                              {emp.employee_id}{emp.group ? ` · ${emp.group}` : ''}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="px-2 py-2.5 text-center">
                        <button
                          title={emp.mode === 'declared' ? 'Base pay — click to switch to undeclared' : 'Undeclared (gross − OT) — click to switch to base pay'}
                          onClick={() => modeMut.mutate({ employee_id: emp.id, year, mode: emp.mode === 'declared' ? 'undeclared' : 'declared' })}
                          className={`px-1.5 py-0.5 rounded text-[10px] font-bold border transition-colors ${
                            emp.mode === 'declared'
                              ? 'bg-sky-50 text-sky-700 border-sky-200 hover:bg-sky-100'
                              : 'bg-violet-50 text-violet-700 border-violet-200 hover:bg-violet-100'
                          }`}
                        >
                          {emp.mode === 'declared' ? 'D' : 'U'}
                        </button>
                      </td>
                      {Array.from({ length: 12 }, (_, i) => {
                        const m = i + 1
                        const val = getVal(emp, m)
                        const excluded = isExcluded(emp, m)
                        return (
                          <td key={m} className="px-1 py-1.5 align-top group/cell">
                            <MonthCell
                              value={val}
                              isOverride={getIsOverride(emp, m)}
                              hasPayroll={getHasPayroll(emp, m)}
                              excluded={excluded}
                              onChange={(v) => handleChange(emp, m, v)}
                            />
                            {val != null && (
                              <div className="h-4 mt-0.5 flex justify-end items-center">
                                {excluded ? (
                                  <button
                                    onClick={() => toggleExclude(emp, m)}
                                    title="Excluded from the total — click to include"
                                    className="inline-flex items-center gap-0.5 px-1.5 rounded text-[9px] font-semibold uppercase tracking-wide text-amber-700 bg-amber-100 hover:bg-amber-200 transition-colors"
                                  >
                                    <X size={9} strokeWidth={3} /> excluded
                                  </button>
                                ) : (
                                  <button
                                    onClick={() => toggleExclude(emp, m)}
                                    title="Included in the total — click to exclude"
                                    className="text-[9px] text-gray-300 hover:text-red-500 opacity-0 group-hover/cell:opacity-100 focus:opacity-100 transition-opacity"
                                  >
                                    exclude
                                  </button>
                                )}
                              </div>
                            )}
                          </td>
                        )
                      })}
                      <td className="px-3 py-2.5 bg-brand-50 border-l border-brand-100">
                        {thirteenth > 0
                          ? <CopyCell value={thirteenth} />
                          : <span className="text-gray-300 flex justify-end">—</span>
                        }
                      </td>
                    </tr>
                    {isExpanded && (
                      <tr className="bg-gray-50/70 border-b border-gray-100">
                        <td className="px-3 py-2 sticky left-0 bg-gray-50/70 border-r border-gray-100 z-10">
                          <span className="text-[10px] text-gray-400 italic pl-5">Cutoff breakdown</span>
                        </td>
                        <td />
                        {Array.from({ length: 12 }, (_, i) => {
                          const m = i + 1
                          const cell = emp.months[m]
                          if (cell?.is_override) {
                            return (
                              <td key={m} className="px-2 py-2 align-top">
                                <div className="text-[10px] text-amber-600 italic text-right">manual override</div>
                              </td>
                            )
                          }
                          const breakdown = cell?.breakdown ?? []
                          if (!breakdown.length) return <td key={m} />
                          return (
                            <td key={m} className="px-2 py-2 align-top space-y-2">
                              {breakdown.map((b, bi) => {
                                // exclude the pushed 13th Month Pay from display — it's the output, not an input
                                const visibleAllowances = (b.allowances ?? []).filter(a => a.label !== '13th Month Pay')
                                const hasDeductions = (b.deductions?.length > 0) || visibleAllowances.length > 0
                                const deductionParts = [
                                  ...visibleAllowances.map(a => `−${a.label.replace(' Pay', '')} ${fmt(a.amount)}`),
                                  ...(b.deductions ?? []).map(d => `−${d.label} ${fmt(d.amount)}`),
                                ].join('  ')
                                return (
                                  <div key={bi} className="text-[10px] leading-tight border-l-2 border-gray-200 pl-1.5">
                                    <div className="text-gray-400 mb-0.5">{fmtRange(b.cutoff_start, b.cutoff_end)}</div>
                                    {hasDeductions && (
                                      <div className="text-gray-300 tabular-nums truncate" title={deductionParts}>
                                        {deductionParts}
                                      </div>
                                    )}
                                    <div className={`tabular-nums font-semibold text-right ${b.base === 0 ? 'text-red-300' : 'text-gray-700'}`}>
                                      {b.base === 0 ? '0.00 (fully absent)' : fmt(b.base)}
                                    </div>
                                  </div>
                                )
                              })}
                            </td>
                          )
                        })}
                        <td className="bg-brand-50 border-l border-brand-100" />
                      </tr>
                    )}
                    </React.Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        {data?.pagination && data.pagination.last_page > 1 && (
          <div className="flex items-center justify-end mt-4">
            <div className="inline-flex items-center bg-gray-100 text-gray-600 text-xs font-medium rounded-full overflow-hidden">
              <button
                className="px-3 py-1.5 hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                onClick={() => setPage(p => p - 1)}
                disabled={page <= 1}
              >
                ‹ Prev
              </button>
              <span className="px-3 py-1.5 border-x border-gray-200">
                {(() => {
                  const { current_page, per_page, total, last_page } = data.pagination
                  const from = (current_page - 1) * per_page + 1
                  const to = current_page === last_page ? total : current_page * per_page
                  return `${from}–${to} of ${total} employees · Page ${current_page}/${last_page}`
                })()}
              </span>
              <button
                className="px-3 py-1.5 hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                onClick={() => setPage(p => p + 1)}
                disabled={page >= data.pagination.last_page}
              >
                Next ›
              </button>
            </div>
          </div>
        )}
      </>)}

      {showPush && <PushToPayrollModal year={year} onClose={() => setShowPush(false)} />}
    </div>
  )
}
