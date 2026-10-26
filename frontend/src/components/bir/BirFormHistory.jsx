import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { History, ChevronDown } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import clsx from 'clsx'
import { birKeys, getBirDraftHistory } from '../../api/queries'
import { formatValue } from './BirDraftEditor'

// One color per kind of step, with the legend shown above the list.
const EVENTS = {
  created:   ['Created', 'bg-gray-400'],
  edited:    ['Boxes changed', 'bg-amber-500'],
  submitted: ['Submitted', 'bg-blue-500'],
  returned:  ['Returned', 'bg-orange-500'],
  reopened:  ['Back to draft', 'bg-amber-500'],
  approved:  ['Approved', 'bg-green-500'],
  filed:     ['Filed', 'bg-indigo-500'],
  voided:    ['Voided', 'bg-red-500'],
  signed:    ['Signed', 'bg-teal-500'],
  wet_signature: ['Signed by hand', 'bg-purple-500'],
  deleted:   ['Deleted', 'bg-red-500'],
}
const LEGEND = ['created', 'edited', 'submitted', 'returned', 'approved', 'filed', 'signed', 'wet_signature', 'voided']

const when = (at) => (at ? format(parseISO(at), 'MMM d, yyyy · h:mm a') : 'When it was first made')
const shown = (value, type) => formatValue(value, type) ?? 'blank'

/**
 * Everything that happened to the form (GET /bir/drafts/{id}/history, from the audit log):
 * who created, changed, submitted, returned, approved, filed, signed or voided it, and when, with the
 * old and new value of every box changed. Loaded when opened; refreshed when the form changes.
 */
export default function BirFormHistory({ draft }) {
  const [open, setOpen] = useState(false)
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: birKeys.history(draft.id),
    queryFn: () => getBirDraftHistory(draft.id),
    enabled: open,
    staleTime: 0,
  })
  const entries = data?.data ?? []

  useEffect(() => { if (open) refetch() }, [draft.status, draft.fields]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="border-b border-gray-100">
      <button
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        className="w-full px-4 py-2.5 flex items-center gap-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
      >
        <History size={13} className="text-brand-600" /> History of this form
        {data && <span className="text-gray-400 font-normal">({entries.length})</span>}
        <ChevronDown size={13} className={clsx('ml-auto text-gray-400 transition-transform', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="px-4 pb-3 space-y-2">
          <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-500" aria-label="Legend">
            {LEGEND.map(key => (
              <span key={key} className="inline-flex items-center gap-1">
                <span aria-hidden="true" className={clsx('h-2 w-2 rounded-full', EVENTS[key][1])} /> {EVENTS[key][0]}
              </span>
            ))}
          </div>
          {isLoading && <p className="text-xs text-gray-400">Loading the history…</p>}
          {isError && <p className="text-xs text-red-600">Could not load the history.</p>}

          <ol className="relative ml-1 border-l border-gray-200">
            {entries.map(e => (
              <li key={e.id} className="relative pl-4 pb-3 last:pb-0">
                <span aria-hidden="true" className={clsx('absolute -left-[5px] top-1 h-2.5 w-2.5 rounded-full ring-2 ring-white', (EVENTS[e.event] ?? EVENTS.created)[1])} />
                <p className="text-xs text-gray-800">
                  <span className="font-medium">{e.text}</span>
                  {e.by && <span className="text-gray-500"> · {e.by.name}</span>}
                </p>
                <p className="text-[11px] text-gray-400">{when(e.at)}</p>
                {e.reason && <p className="mt-0.5 text-[11px] text-gray-700">Reason: {e.reason}</p>}
                {e.changes?.length > 0 && (
                  <ul className="mt-1 space-y-0.5 text-[11px] text-gray-600">
                    {e.changes.map(c => (
                      <li key={c.key}>
                        <span className="text-gray-400">Item {c.item}</span> {c.label}:{' '}
                        <span className="line-through text-gray-400">{shown(c.from, c.type)}</span> → <span className="font-medium text-gray-800">{shown(c.to, c.type)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ol>
        </div>
      )}
    </div>
  )
}
