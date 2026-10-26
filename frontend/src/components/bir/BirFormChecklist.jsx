import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ListTodo, CheckCircle2, Circle } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getBirConfig } from '../../api/queries'
import { periodName } from './BirDraftList'

const DONE = ['pending', 'approved', 'finalized']
const readTicks = (key) => {
  try { return JSON.parse(localStorage.getItem(key) ?? '{}') } catch { return {} }
}

/**
 * The steps to get the open form filed, ticked off as it moves along: most from its status,
 * boxes and signing; a few the system can't know (the PDF checked, the copy given to the
 * employee) are ticked by hand and remembered per browser.
 */
export default function BirFormChecklist({ draftId }) {
  const { data: config } = useQuery({ queryKey: birKeys.config, queryFn: getBirConfig, staleTime: Infinity })
  // The form preview loads the form; this reads the same copy and follows its changes.
  const { data: draft } = useQuery({ queryKey: birKeys.detail(draftId), enabled: false })
  const tickKey = `bir_checklist_${draftId}`
  const [ticks, setTicks] = useState(() => readTicks(tickKey))
  const [tickedFor, setTickedFor] = useState(draftId)
  if (tickedFor !== draftId) { setTickedFor(draftId); setTicks(readTicks(tickKey)) }

  const toggle = (key) => {
    const next = { ...ticks, [key]: !ticks[key] }
    setTicks(next)
    try { localStorage.setItem(tickKey, JSON.stringify(next)) } catch { /* kept for this visit only */ }
  }

  const header = (
    <h2 className="shrink-0 px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
      <ListTodo size={15} className="text-brand-600" /> Checklist
      {draft && <span className="font-normal text-gray-500 truncate">· {draft.form_type} · {draft.form_type === '2316' ? `Tax year ${draft.period}` : periodName(draft.form_type, draft.period)}</span>}
    </h2>
  )

  if (!draftId || !draft) {
    return (
      <section className="card flex min-h-0 flex-col" data-guide="checklist">
        {header}
        <p className="p-4 text-xs text-gray-500">Open a form to see the steps left to file it, ticked off as you go.</p>
      </section>
    )
  }

  const schema = config?.schemas?.[draft.form_type] ?? []
  const missing = schema.filter(f => f.required && f.type !== 'manual' && (draft.fields?.[f.key]?.value ?? '') === '').length
  const errors = (draft.validation_errors ?? []).filter(e => e.severity !== 'warning').length
  const signing = draft.signing ?? {}
  const is2316 = draft.form_type === '2316'
  const items = [
    { key: 'started', label: 'Form started from payroll', done: true },
    { key: 'boxes', label: missing ? `Fill in the required boxes (${missing} left)` : 'Required boxes filled in', done: missing === 0 },
    { key: 'errors', label: errors ? `Fix the errors (${errors} left)` : 'No errors', done: errors === 0 },
    { key: 'pdf', label: 'PDF previewed and checked', manual: true },
    { key: 'submitted', label: 'Submitted for review', done: DONE.includes(draft.status) },
    { key: 'approved', label: 'Approved by accounting', done: ['approved', 'finalized'].includes(draft.status) },
    { key: 'filed', label: 'Filed with the BIR and marked as filed', done: draft.status === 'finalized' },
    { key: 'signed', label: signing.method === 'paper' ? 'Signed by hand on the printout' : 'Signed by the employer (optional)', done: signing.method === 'paper' || !!signing.employer },
    ...(is2316 ? [
      { key: 'employee', label: 'Signed by the employee (optional)', done: signing.method === 'paper' || !!signing.employee },
      { key: 'copy', label: 'Copy given to the employee', manual: true },
    ] : []),
  ].map(item => (item.manual ? { ...item, done: !!ticks[item.key] } : item))
  const doneCount = items.filter(i => i.done).length

  if (draft.status === 'void') {
    return (
      <section className="card flex min-h-0 flex-col" data-guide="checklist">
        {header}
        <p className="p-4 text-xs text-gray-500">This form is void, so there is nothing left to do on it.</p>
      </section>
    )
  }

  return (
    <section className="card flex min-h-0 flex-col" data-guide="checklist">
      {header}
      {/* Scrolls inside its card, so the card fits beside the Pending card. */}
      <div className="p-4 space-y-2 min-h-0 flex-1 overflow-y-auto">
        <div className="flex items-center gap-2 text-[11px] text-gray-500">
          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100">
            <div className="h-full bg-green-500 transition-all" style={{ width: `${(doneCount / items.length) * 100}%` }} />
          </div>
          {doneCount} of {items.length} done
        </div>
        <ul className="space-y-1">
          {items.map(item => {
            const Icon = item.done ? CheckCircle2 : Circle
            const body = (
              <>
                <Icon size={15} className={clsx('shrink-0', item.done ? 'text-green-600' : 'text-gray-300')} />
                <span className={clsx(item.done ? 'text-gray-500 line-through decoration-gray-300' : 'text-gray-800')}>{item.label}</span>
                {item.manual && <span className="ml-auto text-[10px] text-gray-400">tick when done</span>}
              </>
            )
            return (
              <li key={item.key}>
                {item.manual ? (
                  <button onClick={() => toggle(item.key)} aria-pressed={item.done}
                    className="flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-xs hover:bg-gray-50">{body}</button>
                ) : (
                  <div className="flex items-center gap-2 px-1.5 py-1 text-xs">{body}</div>
                )}
              </li>
            )
          })}
        </ul>
      </div>
    </section>
  )
}
