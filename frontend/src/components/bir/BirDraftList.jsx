import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getBirDrafts } from '../../api/queries'
import { PagePagination, StatusBadge } from '../ui/index.jsx'
import { describePeriod } from './BirChatPanel'
import { signingChip } from './BirSigningStatus'

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
// The list names a 1601-C's month in words ("October 2026"); a 2316 keeps "Tax year 2026".
export const periodName = (formType, period) => (formType === '1601-C' && /^\d{4}-\d{2}$/.test(period)
  ? `${MONTH_NAMES[Number(period.slice(5, 7)) - 1]} ${period.slice(0, 4)}` : describePeriod(formType, period))

const STATUSES = [['', 'All statuses'], ['draft', 'Draft'], ['pending', 'Waiting for review'], ['approved', 'Approved'], ['finalized', 'Finalized (filed)'], ['void', 'Void']]
const PER_PAGE = 8
// With versions grouped, a page holds whole forms: fetch more at once.
const PER_PAGE_GROUPED = 50

// A form's label (contract §2): "#64" for an original, "#64v2" for its second version. Older
// API responses have no label; the record number stands in.
export const formLabel = (d) => d.label ?? `#${d.id}`

// What a version is: the original, a 1601-C's amendment, or a 2316's later version.
export const versionLabel = (d) => (d.version <= 1 ? 'Original' : d.form_type === '1601-C' ? `Amendment ${d.version - 1}` : `Version ${d.version}`)

// A draft the reviewer sent back: still a draft, with the reviewer's reason.
const isReturned = (d) => d.status === 'draft' && !!d.rejection_reason

function DraftRow({ d, activeId, onOpen, nested = false }) {
  return (
    <li>
      <button
        onClick={() => onOpen(d)}
        aria-current={d.id === activeId ? 'true' : undefined}
        className={clsx('w-full py-2.5 text-left flex items-center gap-3 hover:bg-gray-50', nested ? 'pl-9 pr-4' : 'px-4',
          d.id === activeId && 'bg-brand-50/60')}
      >
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-gray-900 truncate">
            {d.form_type} · {periodName(d.form_type, d.period)}
            {d.employee_name && <span className="font-normal text-gray-600"> · {d.employee_name}</span>}
          </p>
          <p className="text-[11px] text-gray-400">
            {formLabel(d)}{formLabel(d) !== `#${d.id}` && ` (#${d.id})`} · {versionLabel(d)}{d.prepared_by && ` · prepared by ${d.prepared_by.name}`}
          </p>
        </div>
        {isReturned(d) && <span className="badge-yellow" title={d.rejection_reason}>Returned</span>}
        {signingChip(d.signing, d.form_type) && <span className={signingChip(d.signing, d.form_type)[1]}>{signingChip(d.signing, d.form_type)[0]}</span>}
        <StatusBadge status={d.status} />
      </button>
    </li>
  )
}

/** Every saved form, newest first. Filtering and paging happen on the server (contract §6). */
export default function BirDraftList({ formTypes, activeId, onOpen }) {
  const [status, setStatus] = useState('')
  const [formType, setFormType] = useState('')
  const [page, setPage] = useState(1)
  const [grouped, setGrouped] = useState(false)

  const params = { page, per_page: grouped ? PER_PAGE_GROUPED : PER_PAGE, ...(status && { status }), ...(formType && { form_type: formType }) }
  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: birKeys.list(params),
    queryFn: () => getBirDrafts(params),
    placeholderData: (prev) => prev,
  })
  const drafts = data?.data ?? []
  // Versions of one form together, oldest first, under the form's original number. Forms keep
  // the list's newest-first order (a Map keeps the order forms are first seen in).
  const groups = [...drafts.reduce((all, d) => {
    const root = d.root_id ?? d.id
    if (!all.has(root)) all.set(root, { root, versions: [] })
    all.get(root).versions.push(d)
    return all
  }, new Map()).values()].map(g => ({ ...g, versions: [...g.versions].sort((a, b) => a.version - b.version) }))

  return (
    <div>
      <div className="flex flex-wrap gap-2 px-4 py-3 border-b border-gray-100">
        <select className="input w-auto py-1 text-xs" value={status} aria-label="Filter by status"
          onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          {STATUSES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        <select className="input w-auto py-1 text-xs" value={formType} aria-label="Filter by form"
          onChange={(e) => { setFormType(e.target.value); setPage(1) }}>
          <option value="">All forms</option>
          {formTypes.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        <label className="self-center flex items-center gap-1.5 text-[11px] text-gray-500">
          <input type="checkbox" checked={grouped} onChange={(e) => { setGrouped(e.target.checked); setPage(1) }} />
          Group versions
        </label>
        {data?.pagination && (
          <span className="self-center text-[11px] text-gray-400">
            {data.pagination.total} form{data.pagination.total === 1 ? '' : 's'}{isFetching && ' · updating…'}
          </span>
        )}
      </div>

      {isLoading ? (
        <div className="p-4 space-y-2 animate-pulse">{[0, 1, 2].map(i => <div key={i} className="h-10 rounded bg-gray-50" />)}</div>
      ) : isError ? (
        <div className="p-4 text-center text-xs text-red-600">
          Could not load the saved forms.
          <button onClick={() => refetch()} className="btn-ghost mx-auto mt-2 text-xs"><RefreshCw size={12} /> Try again</button>
        </div>
      ) : !drafts.length ? (
        <p className="px-4 py-6 text-center text-xs text-gray-400">
          {status || formType ? 'No forms match these filters.' : 'No saved forms yet. Ask the assistant or pick a form to start one.'}
        </p>
      ) : (
        <ul className="divide-y divide-gray-100">
          {grouped
            ? groups.map(g => (g.versions.length === 1 ? <DraftRow key={g.root} d={g.versions[0]} activeId={activeId} onOpen={onOpen} /> : (
              <li key={g.root}>
                <p className="px-4 pt-2 text-[11px] font-medium text-gray-500">
                  Form #{g.root} · {g.versions.length} versions on this page
                </p>
                <ul>{g.versions.map(d => <DraftRow key={d.id} d={d} activeId={activeId} onOpen={onOpen} nested />)}</ul>
              </li>
            )))
            : drafts.map(d => <DraftRow key={d.id} d={d} activeId={activeId} onOpen={onOpen} />)}
        </ul>
      )}

      {data?.pagination?.last_page > 1 && (
        <div className="px-4 py-2 border-t border-gray-100">
          <PagePagination pagination={data.pagination} onPageChange={setPage} />
        </div>
      )}
    </div>
  )
}
