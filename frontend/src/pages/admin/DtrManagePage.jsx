import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { Download, Trash2, Users, FileText, AlertCircle } from 'lucide-react'
import {
  getDtrs, dtrKeys, deleteDtr,
  getDtrConfig, getDtrEmployeeAccess, toggleDtrEmployeeAccess,
} from '../../api/queries'
import { PageHeader, PageSpinner, ConfirmModal } from '../../components/ui/index.jsx'
import api from '../../api/axios'

function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd']
  const v = n % 100
  return n + (s[(v - 20) % 10] || s[v] || s[0])
}

export default function DtrManagePage() {
  const PER_PAGE = 15

  const [tab, setTab]                   = useState('uploads')
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [filterName, setFilterName]     = useState('')
  const [filterPeriod, setFilterPeriod] = useState('')
  const [filterCutoff, setFilterCutoff] = useState('')
  const [page, setPage]                 = useState(1)
  const [pending, setPending]           = useState({})

  const resetPage = () => setPage(1)
  const qc = useQueryClient()

  const { data: config } = useQuery({ queryKey: dtrKeys.config, queryFn: getDtrConfig })

  const { data: dtrs = [], isLoading } = useQuery({
    queryKey: dtrKeys.list({}),
    queryFn: () => getDtrs({}),
  })

  const { data: employeeAccess = [], isLoading: loadingAccess, isError: accessError, error: accessErrorDetail } = useQuery({
    queryKey: dtrKeys.employeeAccess,
    queryFn: getDtrEmployeeAccess,
    retry: 1,
  })

  const remove = useMutation({
    mutationFn: (id) => deleteDtr(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: dtrKeys.list({}) }); setDeleteTarget(null) },
  })

  const saveAccess = useMutation({
    mutationFn: (changes) => Promise.all(
      Object.entries(changes).map(([id, enabled]) => toggleDtrEmployeeAccess(Number(id), enabled))
    ),
    onSuccess: (_, changes) => {
      qc.setQueryData(dtrKeys.employeeAccess, (old = []) =>
        old.map(emp => changes[emp.id] !== undefined
          ? { ...emp, dtr_upload_enabled: changes[emp.id] }
          : emp
        )
      )
      qc.invalidateQueries({ queryKey: dtrKeys.employeeAccess })
      setPending({})
    },
  })

  function handleDownload(id, filename) {
    api.get(`/dtr/${id}/download`, { responseType: 'blob' }).then(res => {
      const url = window.URL.createObjectURL(new Blob([res.data]))
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      window.URL.revokeObjectURL(url)
    })
  }

  const cutoffLabel = (type) => {
    if (type === 'monthly') return 'Monthly'
    if (type === 'cutoff1') return `${ordinal(config?.cutoff1_day ?? 10)} Cutoff`
    if (type === 'cutoff2') return `${ordinal(config?.cutoff2_day ?? 25)} Cutoff`
    return type
  }

  const periods      = [...new Set(dtrs.map(d => d.period_label))].sort((a, b) => new Date(b) - new Date(a))
  const cutoffTypes  = config?.frequency === 'semi_monthly' ? ['cutoff1', 'cutoff2'] : ['monthly']

  const filtered = dtrs.filter(d => {
    if (filterName   && !d.employee?.full_name?.toLowerCase().includes(filterName.toLowerCase())) return false
    if (filterPeriod && d.period_label !== filterPeriod) return false
    if (filterCutoff && d.cutoff_type  !== filterCutoff) return false
    return true
  })

  const totalPages = Math.ceil(filtered.length / PER_PAGE)
  const paginated  = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE)

  if (isLoading) return <PageSpinner />

  return (
    <div>
      <PageHeader
        title="DTR Management"
        description="View and download employee Daily Time Record uploads"
        help={[
          { heading: 'Tabs', items: [
            'Uploads tab — shows all DTR files submitted by employees, with filters for name, period, and cutoff.',
            'Employee Access tab — manage per-employee DTR upload permissions (only shown if per-employee restriction is enabled in System Settings).',
          ]},
          { heading: 'Uploads Tab', items: [
            'Filter by employee name, period (month), and cutoff (1st, 2nd, or monthly) using the controls at the top.',
            'Click the download icon on any row to download that employee\'s submitted DTR file.',
            'Click the trash icon to permanently delete a submitted DTR (a confirmation dialog will appear).',
          ]},
          { heading: 'Employee Access Tab', items: [
            'Each employee has a toggle showing whether they are allowed to upload DTRs.',
            'Toggle individual employees on or off, then click Save Changes to apply.',
            'This tab is only relevant when "Per-Employee Restriction" is enabled in System Settings → DTR Upload.',
          ]},
        ]}
      />

      {!config?.enabled && (
        <div className="mb-5 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800">
          DTR upload is currently disabled. Enable it in System Settings → DTR Upload.
        </div>
      )}

      {/* Tab + filter bar */}
      <div className="flex flex-wrap items-center gap-3 mb-5">
        <div className="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
          <button
            onClick={() => setTab('uploads')}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${
              tab === 'uploads' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            <FileText size={13} /> Uploads
          </button>
          <button
            onClick={() => setTab('access')}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${
              tab === 'access' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            <Users size={13} /> Employee Access
          </button>
        </div>

        {tab === 'uploads' && (
          <>
            <input
              type="text"
              placeholder="Search by employee…"
              value={filterName}
              onChange={e => { setFilterName(e.target.value); resetPage() }}
              className="input py-1.5 text-sm w-44"
            />
            <select
              value={filterPeriod}
              onChange={e => { setFilterPeriod(e.target.value); resetPage() }}
              className="input py-1.5 text-sm w-36"
            >
              <option value="">All periods</option>
              {periods.map(p => <option key={p} value={p}>{p}</option>)}
            </select>
            <select
              value={filterCutoff}
              onChange={e => { setFilterCutoff(e.target.value); resetPage() }}
              className="input py-1.5 text-sm w-36"
            >
              <option value="">All cutoffs</option>
              {cutoffTypes.map(c => <option key={c} value={c}>{cutoffLabel(c)}</option>)}
            </select>
            {(filterName || filterPeriod || filterCutoff) && (
              <button
                onClick={() => { setFilterName(''); setFilterPeriod(''); setFilterCutoff(''); resetPage() }}
                className="text-xs text-gray-400 hover:text-gray-600"
              >
                Clear filters
              </button>
            )}
          </>
        )}
      </div>

      {/* Uploads table */}
      {tab === 'uploads' && (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  {['Employee', 'Period', 'Cutoff', 'File', 'Uploaded', 'Auto-delete', ''].map(h => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {paginated.map(d => (
                  <tr key={d.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{d.employee?.full_name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{d.period_label}</td>
                    <td className="px-4 py-3 text-gray-600">{cutoffLabel(d.cutoff_type)}</td>
                    <td className="px-4 py-3 text-gray-500 max-w-[200px] truncate" title={d.original_filename}>
                      {d.original_filename}
                    </td>
                    <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                      {format(new Date(d.created_at), 'MMM d, yyyy')}
                      <br /><span className="text-[9px] text-gray-300">{format(new Date(d.created_at), 'h:mm a')}</span>
                    </td>
                    <td className="px-4 py-3 text-gray-400 text-xs">
                      {format(new Date(d.auto_delete_at), 'MMM d, yyyy')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex gap-1">
                        <button
                          onClick={() => handleDownload(d.id, d.original_filename)}
                          className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50"
                          title="Download"
                        >
                          <Download size={14} />
                        </button>
                        <button
                          onClick={() => setDeleteTarget(d)}
                          className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50"
                          title="Delete"
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {paginated.length === 0 && (
                  <tr>
                    <td colSpan={7} className="py-12 text-center">
                      <FileText size={32} className="text-gray-200 mx-auto mb-2" />
                      <p className="text-sm text-gray-400">No DTR uploads found</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Pagination */}
      {tab === 'uploads' && filtered.length > 0 && (
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
              {(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, filtered.length)} of {filtered.length} uploads · Page {page}/{totalPages}
            </span>
            <button
              className="px-3 py-1.5 hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              onClick={() => setPage(p => p + 1)}
              disabled={page >= totalPages}
            >
              Next ›
            </button>
          </div>
        </div>
      )}

      {/* Employee Access */}
      {tab === 'access' && (
        loadingAccess ? <PageSpinner /> :
        accessError ? (
          <div className="max-w-lg flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            <AlertCircle size={16} className="shrink-0 mt-0.5" />
            <div>
              <p className="font-medium">Failed to load employee access</p>
              <p className="text-xs text-red-500 mt-0.5">{accessErrorDetail?.response?.data?.message || accessErrorDetail?.message || 'Unknown error'}</p>
            </div>
          </div>
        ) : (
          <div className="max-w-lg space-y-3">
            {!config?.per_employee_restriction && (
              <div className="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800">
                Per-employee restriction is off — these toggles will take effect once enabled in System Settings → DTR Upload.
              </div>
            )}

            <div className="card overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Employee</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide w-28">ID</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide w-20">Upload</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {employeeAccess.map(emp => {
                    const value   = pending[emp.id] !== undefined ? pending[emp.id] : emp.dtr_upload_enabled
                    const changed = pending[emp.id] !== undefined
                    return (
                      <tr key={emp.id} className={changed ? 'bg-amber-50' : 'hover:bg-gray-50'}>
                        <td className="px-4 py-2.5 font-medium text-gray-900 text-sm">{emp.first_name} {emp.last_name}</td>
                        <td className="px-4 py-2.5 text-gray-400 text-xs">{emp.employee_id}</td>
                        <td className="px-4 py-2.5">
                          <button
                            onClick={() => setPending(p => ({ ...p, [emp.id]: !value }))}
                            className={`w-10 h-5 rounded-full flex items-center p-0.5 transition-colors ${
                              value ? 'bg-brand-600' : 'bg-gray-300'
                            }`}
                          >
                            <div className={`w-4 h-4 rounded-full bg-white shadow-sm transition-transform ${value ? 'translate-x-5' : ''}`} />
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                  {employeeAccess.length === 0 && (
                    <tr>
                      <td colSpan={3} className="py-10 text-center">
                        <Users size={28} className="text-gray-200 mx-auto mb-2" />
                        <p className="text-sm text-gray-400">No employees found</p>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>

              {saveAccess.isError && (
                <div className="px-4 py-3 border-t border-red-100 bg-red-50 flex items-center gap-2 text-xs text-red-600">
                  <AlertCircle size={13} className="shrink-0" />
                  {saveAccess.error?.response?.data?.message || 'Save failed — check your connection and try again.'}
                </div>
              )}

              {Object.keys(pending).length > 0 && (
                <div className="px-4 py-3 border-t border-amber-100 bg-amber-50 flex items-center justify-between">
                  <p className="text-xs text-amber-700">
                    {Object.keys(pending).length} unsaved change{Object.keys(pending).length !== 1 ? 's' : ''}
                  </p>
                  <div className="flex gap-2">
                    <button onClick={() => { setPending({}); saveAccess.reset() }} className="btn-secondary text-xs py-1 px-3" disabled={saveAccess.isPending}>
                      Discard
                    </button>
                    <button onClick={() => saveAccess.mutate(pending)} className="btn-primary text-xs py-1 px-3" disabled={saveAccess.isPending}>
                      {saveAccess.isPending ? 'Saving…' : 'Save'}
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )
      )}

      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => remove.mutate(deleteTarget.id)}
        title="Delete DTR"
        message={`Delete "${deleteTarget?.original_filename}" for ${deleteTarget?.employee?.full_name}? This cannot be undone.`}
        type="danger"
        confirmLabel="Delete"
      />
    </div>
  )
}
