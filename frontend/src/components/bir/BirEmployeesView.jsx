import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Search, Plus, RefreshCw } from 'lucide-react'
import { birKeys, getBirEmployees, getAllBir2316, createBirDraft } from '../../api/queries'
import { StatusBadge } from '../ui/index.jsx'
import { formLabel, versionLabel } from './BirDraftList'
import BirCreateError from './BirExistingForm'

const CY = new Date().getFullYear()
const YEARS = Array.from({ length: 4 }, (_, i) => CY - i)

// The client an employee works on. LAUNCHR has no client field yet, so the employee's
// Group (set on their profile, e.g. "Lazada") stands in for it.
const clientOf = (employee) => employee.group?.trim() || null

/**
 * Every employee with their Certificates of Compensation (2316) for a year, filterable by
 * client: open a form, or start one for someone who doesn't have it yet.
 */
export default function BirEmployeesView({ activeId, onOpen }) {
  const qc = useQueryClient()
  const [client, setClient] = useState('')
  const [search, setSearch] = useState('')
  const [year, setYear] = useState(CY)

  const employeesQ = useQuery({ queryKey: birKeys.employees, queryFn: getBirEmployees, staleTime: 5 * 60_000 })
  const formsQ = useQuery({ queryKey: birKeys.all2316, queryFn: getAllBir2316 })

  const create = useMutation({
    mutationFn: (employee) => createBirDraft({ form_type: '2316', period: String(year), employee_id: employee.id }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      onOpen(res.data)
    },
  })

  const employees = employeesQ.data?.data ?? []
  const clients = useMemo(() => [...new Set(employees.map(clientOf).filter(Boolean))].sort(), [employees])
  const formsByEmployee = useMemo(() => (formsQ.data ?? [])
    .filter(d => d.period === String(year))
    .reduce((acc, d) => ({ ...acc, [d.employee_id]: [...(acc[d.employee_id] ?? []), d] }), {}), [formsQ.data, year])

  const term = search.trim().toLowerCase()
  const shown = employees
    .filter(e => !client || (client === '__none' ? !clientOf(e) : clientOf(e) === client))
    .filter(e => !term || `${e.first_name} ${e.last_name} ${e.employee_id}`.toLowerCase().includes(term))
    .sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))

  if (employeesQ.isLoading) {
    return <div className="p-4 space-y-2 animate-pulse">{[0, 1, 2, 3].map(i => <div key={i} className="h-12 rounded bg-gray-50" />)}</div>
  }
  if (employeesQ.isError) {
    return (
      <div className="p-6 text-center text-sm text-red-600">
        Could not load the employees.
        <button onClick={() => employeesQ.refetch()} className="btn-ghost mx-auto mt-2 text-xs"><RefreshCw size={12} /> Try again</button>
      </div>
    )
  }

  return (
    <div>
      <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-gray-100">
        <select className="input w-auto py-1 text-xs" value={client} onChange={(e) => setClient(e.target.value)} aria-label="Filter by client">
          <option value="">All clients</option>
          {clients.map(c => <option key={c} value={c}>{c}</option>)}
          <option value="__none">No client set</option>
        </select>
        <select className="input w-auto py-1 text-xs" value={year} onChange={(e) => setYear(Number(e.target.value))} aria-label="Tax year">
          {YEARS.map(y => <option key={y} value={y}>{y}</option>)}
        </select>
        <label className="relative flex-1 min-w-[160px]">
          <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <input className="input py-1 pl-8 text-xs" placeholder="Search name or employee ID" value={search} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <span className="text-[11px] text-gray-400">{shown.length} employee{shown.length === 1 ? '' : 's'}</span>
      </div>

      {create.isError && <div className="px-4 pt-2"><BirCreateError error={create.error} onOpen={onOpen} /></div>}

      {!shown.length ? (
        <p className="px-4 py-8 text-center text-xs text-gray-400">No employees match these filters.</p>
      ) : (
        <ul className="divide-y divide-gray-100 max-h-[calc(100vh-320px)] min-h-[200px] overflow-y-auto">
          {shown.map(e => {
            const forms = (formsByEmployee[e.id] ?? []).sort((a, b) => a.version - b.version)
            return (
              <li key={e.id} className="px-4 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-gray-900 truncate">{e.last_name}, {e.first_name}</p>
                  <p className="text-[11px] text-gray-400">
                    {e.employee_id}{clientOf(e) && <> · <span className="text-gray-600">{clientOf(e)}</span></>}
                    {e.status && e.status !== 'active' && ` · ${e.status}`}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  {formsQ.isLoading && <span className="text-[11px] text-gray-400">Loading forms…</span>}
                  {forms.map(d => (
                    <button
                      key={d.id}
                      onClick={() => onOpen(d)}
                      title={`2316 ${d.period} · ${versionLabel(d)}`}
                      className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] hover:border-brand-300 ${d.id === activeId ? 'border-brand-300 bg-brand-50' : 'border-gray-200'}`}
                    >
                      2316 {formLabel(d)} <StatusBadge status={d.status} />
                    </button>
                  ))}
                  {!formsQ.isLoading && !forms.length && (
                    <button
                      onClick={() => create.mutate(e)}
                      disabled={create.isPending}
                      className="btn-ghost py-0.5 px-2 text-[11px]"
                    >
                      <Plus size={11} /> {create.isPending && create.variables?.id === e.id ? 'Creating…' : `Create ${year} 2316`}
                    </button>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
