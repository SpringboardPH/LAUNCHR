import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ClipboardCheck, ChevronRight, Plus, CheckCircle2, Users } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getAllBirDrafts, getBirEmployees, createBirDraft, systemClockKeys, getSystemClock } from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { periodName, formLabel } from './BirDraftList'
import { dueItems, attentionGroups, whenDue, stateOf } from './birTodo'

const SHOWN = 4
const TONE = {
  overdue:  { dot: 'bg-red-500',   text: 'text-red-600' },
  open:     { dot: 'bg-amber-400', text: 'text-amber-700' },
  upcoming: { dot: 'bg-gray-300',  text: 'text-gray-500' },
}

/**
 * The top of BIR Forms, on the same grid as the form preview and the saved forms below it:
 * Pending (each month's 1601-C, the year's 2316s) and what needs the signed-in user (review,
 * returned to them, not filed, not signed). Worked out from the forms list, on the system's
 * date (the virtual clock), so it follows a moved date.
 */
export default function BirTodoPanel({ onOpen, onShowEmployees }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [showAll, setShowAll] = useState({})
  const [error, setError] = useState(null)

  const { data: forms = [], isLoading } = useQuery({ queryKey: birKeys.allDrafts, queryFn: getAllBirDrafts, staleTime: 30_000 })
  const { data: people } = useQuery({ queryKey: birKeys.employees, queryFn: getBirEmployees, staleTime: 5 * 60_000 })
  const { data: clock } = useQuery({ queryKey: systemClockKeys.all, queryFn: getSystemClock, staleTime: 60_000 })
  const today = clock?.date ? new Date(`${clock.date}T00:00:00`) : new Date()

  const employees = (people?.data ?? []).filter(e => !e.status || e.status === 'active')
  const pending = dueItems(forms, employees, today)
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
  const limited = (key, list) => (showAll[key] ? list : list.slice(0, SHOWN))
  const more = (key, list) => list.length > SHOWN && (
    <button onClick={() => setShowAll(s => ({ ...s, [key]: !s[key] }))} className="mt-2 text-[11px] font-medium text-brand-600 hover:text-brand-700">
      {showAll[key] ? 'Show less' : `Show ${list.length - SHOWN} more`}
    </button>
  )
  const action = (item) => {
    if (item.form) return <button onClick={() => onOpen(item.form)} className="btn-secondary w-full justify-center py-1 text-xs">Open <ChevronRight size={12} /></button>
    if (item.create) {
      return (
        <button onClick={() => create.mutate(item.create)} disabled={create.isPending} className="btn-secondary w-full justify-center py-1 text-xs">
          <Plus size={12} /> {create.isPending && create.variables?.period === item.create.period ? 'Starting…' : 'Start'}
        </button>
      )
    }
    return <button onClick={onShowEmployees} className="btn-secondary w-full justify-center py-1 text-xs"><Users size={12} /> Employees</button>
  }

  if (isLoading) return <div className="card h-28 mb-5 animate-pulse bg-gray-50" aria-label="Loading what is pending" />

  return (
    <div className="grid gap-5 mb-5 lg:grid-cols-5" aria-label="To do">
      {/* Pending: same width as the form preview below */}
      <section className="card lg:col-span-3 self-start">
        <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
          <CalendarClock size={15} className="text-brand-600" /> Pending
        </h2>
        <div className="p-4">
          {pending.length === 0 ? (
            <p className="flex items-center gap-1.5 text-xs text-green-700"><CheckCircle2 size={13} /> Nothing pending in the next 30 days.</p>
          ) : (
            <ul className="space-y-1.5">
              {limited('pending', pending).map(item => {
                const tone = TONE[stateOf(item, today)]
                return (
                  // Fixed columns, so every row's status and button line up.
                  <li key={item.key} className="grid grid-cols-[0.5rem_minmax(0,1fr)_8.5rem_6.5rem] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2">
                    <span className={clsx('h-2 w-2 rounded-full', tone.dot)} aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="text-xs font-medium text-gray-900">{item.title}</p>
                      <p className="text-[11px] text-gray-500 truncate" title={item.detail}>{item.detail}</p>
                    </div>
                    <span className={clsx('text-[11px] font-medium', tone.text)}>{whenDue(item, today)}</span>
                    {action(item)}
                  </li>
                )
              })}
            </ul>
          )}
          {more('pending', pending)}
          {error && <p className="mt-2 text-xs text-red-600" role="alert">{error}</p>}
          <p className="mt-2 text-[10px] text-gray-400">1601-C: filed from the 1st to the 10th of the next month · 2316: filed January 1–31 of the next year</p>
        </div>
      </section>

      {/* Needs your attention: same width as the saved forms below */}
      <section className="card lg:col-span-2 self-start">
        <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
          <ClipboardCheck size={15} className="text-brand-600" /> Needs your attention
        </h2>
        <div className="p-4 space-y-3">
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
    </div>
  )
}
