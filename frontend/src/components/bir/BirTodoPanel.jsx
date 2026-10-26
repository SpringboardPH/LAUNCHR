import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ClipboardCheck, ChevronRight, Plus, CheckCircle2 } from 'lucide-react'
import { format } from 'date-fns'
import clsx from 'clsx'
import { birKeys, getAllBirDrafts, getBirEmployees, createBirDraft, systemClockKeys, getSystemClock } from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { periodName, formLabel } from './BirDraftList'
import { FILING_DAYS, dueItems, attentionGroups, whenDue } from './birTodo'

const FILING_KEY = 'bir_filing_day'
const SHOWN = 4

const readFilingDay = () => {
  try { return Number(localStorage.getItem(FILING_KEY)) || 10 } catch { return 10 }
}

/**
 * The top of BIR Forms: what is due (1601-C each month, the year's 2316s) and what needs the
 * signed-in user (review, returned to them, not filed, not signed). Everything is worked out
 * from the forms list, on the system's date (the virtual clock), so it follows a moved date.
 */
export default function BirTodoPanel({ onOpen, onShowEmployees }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [filingDay, setFilingDay] = useState(readFilingDay)
  const [showAll, setShowAll] = useState({})
  const [error, setError] = useState(null)

  const { data: forms = [], isLoading } = useQuery({ queryKey: birKeys.allDrafts, queryFn: getAllBirDrafts, staleTime: 30_000 })
  const { data: people } = useQuery({ queryKey: birKeys.employees, queryFn: getBirEmployees, staleTime: 5 * 60_000 })
  const { data: clock } = useQuery({ queryKey: systemClockKeys.all, queryFn: getSystemClock, staleTime: 60_000 })
  const today = clock?.date ? new Date(`${clock.date}T00:00:00`) : new Date()

  const employees = (people?.data ?? []).filter(e => !e.status || e.status === 'active')
  const due = dueItems(forms, employees, today, filingDay)
  const groups = attentionGroups(forms, user)

  const create = useMutation({
    mutationFn: createBirDraft,
    onMutate: () => setError(null),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: birKeys.lists }); onOpen(res.data) },
    onError: (err) => {
      const existing = err?.response?.data?.data?.existing
      if (existing) onOpen(existing)
      else setError(err?.response?.data?.message || 'Could not start the form. Please try again.')
    },
  })
  const pickDay = (e) => {
    const day = Number(e.target.value)
    setFilingDay(day)
    try { localStorage.setItem(FILING_KEY, String(day)) } catch { /* kept for this visit only */ }
  }
  const limited = (key, list) => (showAll[key] ? list : list.slice(0, SHOWN))
  const more = (key, list) => list.length > SHOWN && (
    <button onClick={() => setShowAll(s => ({ ...s, [key]: !s[key] }))} className="mt-1 text-[11px] font-medium text-brand-600 hover:text-brand-700">
      {showAll[key] ? 'Show less' : `Show ${list.length - SHOWN} more`}
    </button>
  )

  if (isLoading) return <div className="card h-28 mb-5 animate-pulse bg-gray-50" aria-label="Loading what is due" />

  return (
    <section className="card mb-5 grid divide-y divide-gray-100 lg:grid-cols-2 lg:divide-y-0 lg:divide-x" aria-label="To do">
      {/* What's due */}
      <div className="p-4 space-y-2">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2">
            <CalendarClock size={15} className="text-brand-600" /> What's due
          </h2>
          <select value={filingDay} onChange={pickDay} className="ml-auto rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] text-gray-600"
            aria-label="How the 1601-C is filed" title="The day of the next month the 1601-C is due">
            {FILING_DAYS.map(([day, label]) => <option key={day} value={day}>{label}</option>)}
          </select>
        </div>
        {due.length === 0 ? (
          <p className="flex items-center gap-1.5 text-xs text-green-700"><CheckCircle2 size={13} /> Nothing due in the next 30 days.</p>
        ) : (
          <ul className="space-y-1.5">
            {limited('due', due).map(item => (
              <li key={item.key} className="flex items-center gap-3 rounded-lg border border-gray-100 px-3 py-2">
                <span className={clsx('h-2 w-2 shrink-0 rounded-full', item.overdue ? 'bg-red-500' : 'bg-amber-400')} aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium text-gray-900">{item.title}</p>
                  <p className="text-[11px] text-gray-500 truncate">{item.detail} · due {format(item.due, 'MMM d, yyyy')}</p>
                </div>
                <span className={clsx('shrink-0 text-[11px] font-medium', item.overdue ? 'text-red-600' : 'text-amber-700')}>{whenDue(item.due, today)}</span>
                {item.form && (
                  <button onClick={() => onOpen(item.form)} className="btn-ghost shrink-0 py-1 text-xs">Open <ChevronRight size={12} /></button>
                )}
                {item.create && (
                  <button onClick={() => create.mutate(item.create)} disabled={create.isPending} className="btn-secondary shrink-0 py-1 text-xs">
                    <Plus size={12} /> {create.isPending && create.variables?.period === item.create.period ? 'Starting…' : 'Start'}
                  </button>
                )}
                {item.employees && (
                  <button onClick={onShowEmployees} className="btn-ghost shrink-0 py-1 text-xs">Employees <ChevronRight size={12} /></button>
                )}
              </li>
            ))}
          </ul>
        )}
        {more('due', due)}
        {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
        <p className="text-[10px] text-gray-400">Deadlines on a weekend move to the Monday; holidays are not counted.</p>
      </div>

      {/* Needs your attention */}
      <div className="p-4 space-y-3">
        <h2 className="text-sm font-semibold text-gray-900 flex items-center gap-2">
          <ClipboardCheck size={15} className="text-brand-600" /> Needs your attention
        </h2>
        {groups.length === 0 && (
          <p className="flex items-center gap-1.5 text-xs text-green-700"><CheckCircle2 size={13} /> You're all caught up.</p>
        )}
        {groups.map(group => (
          <div key={group.key}>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
              {group.title} <span className="ml-1 rounded-full bg-brand-50 px-1.5 text-brand-700">{group.forms.length}</span>
            </p>
            <ul className="mt-1 space-y-0.5">
              {limited(group.key, group.forms).map(f => (
                <li key={f.id}>
                  <button onClick={() => onOpen(f)} className="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-xs hover:bg-gray-50">
                    <span className="font-medium text-gray-900">{f.form_type} · {periodName(f.form_type, f.period)}</span>
                    {f.employee_name && <span className="text-gray-500 truncate">{f.employee_name}</span>}
                    <span className="ml-auto shrink-0 text-[11px] text-gray-400">{formLabel(f)}</span>
                    <ChevronRight size={12} className="shrink-0 text-gray-300" />
                  </button>
                  {group.key === 'returned' && <p className="px-2 text-[11px] text-amber-700 truncate" title={f.rejection_reason}>“{f.rejection_reason}”</p>}
                </li>
              ))}
            </ul>
            {more(group.key, group.forms)}
          </div>
        ))}
      </div>
    </section>
  )
}
