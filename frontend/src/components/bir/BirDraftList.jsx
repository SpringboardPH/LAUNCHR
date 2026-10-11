import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getBirDrafts } from '../../api/queries'
import { PagePagination, StatusBadge } from '../ui/index.jsx'
import { describePeriod } from './BirChatPanel'

const STATUSES = [['', 'All statuses'], ['draft', 'Draft'], ['pending', 'Waiting for review'], ['approved', 'Approved'], ['finalized', 'Finalized (filed)']]
const PER_PAGE = 8

/** Every saved form, newest first. Filtering and paging happen on the server (contract §6). */
export default function BirDraftList({ formTypes, activeId, onOpen }) {
  const [status, setStatus] = useState('')
  const [formType, setFormType] = useState('')
  const [page, setPage] = useState(1)

  const params = { page, per_page: PER_PAGE, ...(status && { status }), ...(formType && { form_type: formType }) }
  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: birKeys.list(params),
    queryFn: () => getBirDrafts(params),
    placeholderData: (prev) => prev,
  })
  const drafts = data?.data ?? []

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
          {drafts.map(d => (
            <li key={d.id}>
              <button
                onClick={() => onOpen(d)}
                aria-current={d.id === activeId ? 'true' : undefined}
                className={clsx('w-full px-4 py-2.5 text-left flex items-center gap-3 hover:bg-gray-50',
                  d.id === activeId && 'bg-brand-50/60')}
              >
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-gray-900 truncate">
                    {d.form_type} · {describePeriod(d.form_type, d.period)}
                    {d.employee_name && <span className="font-normal text-gray-600"> · {d.employee_name}</span>}
                  </p>
                  <p className="text-[11px] text-gray-400">
                    #{d.id}{d.version > 1 && ` · version ${d.version}`}{d.prepared_by && ` · prepared by ${d.prepared_by.name}`}
                  </p>
                </div>
                <StatusBadge status={d.status} />
              </button>
            </li>
          ))}
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
