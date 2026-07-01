import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format, subMonths } from 'date-fns'
import { Upload, FileText, Trash2, AlertCircle, CheckCircle2 } from 'lucide-react'
import { getDtrConfig, dtrKeys, getDtrs, uploadDtr, deleteDtr } from '../../api/queries'
import { PageHeader, PageSpinner, ConfirmModal } from '../../components/ui/index.jsx'

function buildPeriodOptions(count = 6) {
  return Array.from({ length: count }, (_, i) => format(subMonths(new Date(), i), 'MMMM yyyy'))
}

function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd']
  const v = n % 100
  return n + (s[(v - 20) % 10] || s[v] || s[0])
}

function cutoffLabels(c1, c2) {
  return { cutoff1: `${ordinal(c1)} Cutoff`, cutoff2: `${ordinal(c2)} Cutoff`, monthly: 'Monthly' }
}

export default function DtrUploadPage() {
  const fileInputRef = useRef(null)

  const { data: config, isLoading: configLoading } = useQuery({
    queryKey: dtrKeys.config,
    queryFn: getDtrConfig,
  })

  const [activeTab, setActiveTab]       = useState('cutoff1')
  const [period, setPeriod]             = useState(buildPeriodOptions(1)[0])
  const [file, setFile]                 = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)

  const qc = useQueryClient()

  const { data: dtrs = [], isLoading } = useQuery({
    queryKey: dtrKeys.list({}),
    queryFn: () => getDtrs({}),
    enabled: !!config?.enabled,
  })

  const upload = useMutation({
    mutationFn: (fd) => uploadDtr(fd),
    onSuccess: () => { qc.invalidateQueries({ queryKey: dtrKeys.list({}) }); setFile(null) },
  })

  const remove = useMutation({
    mutationFn: (id) => deleteDtr(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: dtrKeys.list({}) }); setDeleteTarget(null) },
  })

  if (configLoading) return <PageSpinner />

  if (!config?.enabled) {
    return (
      <div className="max-w-2xl">
        <PageHeader title="DTR Upload" description="Upload your Daily Time Record" />
        <div className="card p-10 flex flex-col items-center text-center gap-3">
          <AlertCircle size={32} className="text-gray-300" />
          <p className="text-sm text-gray-400">DTR upload is not currently enabled.</p>
          <p className="text-xs text-gray-400">Please contact your HR administrator.</p>
        </div>
      </div>
    )
  }

  if (config.upload_allowed === false) {
    return (
      <div className="max-w-2xl">
        <PageHeader title="DTR Upload" description="Upload your Daily Time Record" />
        <div className="card p-10 flex flex-col items-center text-center gap-3">
          <AlertCircle size={32} className="text-gray-300" />
          <p className="text-sm text-gray-400">DTR upload has been disabled for your account.</p>
          <p className="text-xs text-gray-400">Please contact your HR administrator.</p>
        </div>
      </div>
    )
  }

  const isSemiMonthly = config.frequency === 'semi_monthly'
  const tabs          = isSemiMonthly ? ['cutoff1', 'cutoff2'] : ['monthly']
  const currentCutoff = isSemiMonthly ? activeTab : 'monthly'
  const existing      = dtrs.find(d => d.cutoff_type === currentCutoff && d.period_label === period)
  const periods       = buildPeriodOptions(6)
  const CUTOFF_LABEL  = cutoffLabels(config.cutoff1_day ?? 10, config.cutoff2_day ?? 25)

  function handleUpload(e) {
    e.preventDefault()
    if (!file) return
    const fd = new FormData()
    fd.append('file', file)
    fd.append('cutoff_type', currentCutoff)
    fd.append('period_label', period)
    upload.mutate(fd)
  }

  return (
    <div className="max-w-2xl">
      <PageHeader
        title="DTR Upload"
        description="Upload your Daily Time Record for each payroll cutoff"
        help={[
          { heading: 'Selecting a Cutoff', items: [
            'Use the tabs at the top to switch between the 1st Cutoff and 2nd Cutoff (or Monthly, if configured that way).',
            'Use the Period dropdown to choose which month you are uploading for.',
          ]},
          { heading: 'Uploading a File', items: [
            'Click the upload area or Choose File button to select your DTR file (PDF, JPG, or PNG).',
            'After selecting, click Upload to submit it. Only one file per cutoff per period is allowed.',
            'Re-uploading replaces the previously submitted file.',
          ]},
          { heading: 'Managing Uploaded Files', items: [
            'Previously uploaded DTRs are listed below the upload area.',
            'Click the trash icon to delete a file if you need to replace it with a corrected version.',
          ]},
        ]}
      />

      <div className="space-y-5">
        {/* Period + cutoff selector — inline row */}
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Select Period</h2>
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex flex-col gap-1">
              <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Month</label>
              <select
                value={period}
                onChange={e => setPeriod(e.target.value)}
                className="input h-10 w-44"
              >
                {periods.map(p => <option key={p} value={p}>{p}</option>)}
              </select>
            </div>

            {isSemiMonthly && (
              <div className="flex flex-col gap-1">
                <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Cutoff</label>
                <div className="flex gap-1 bg-gray-100 p-1 rounded-lg">
                  {tabs.map(tab => (
                    <button
                      key={tab}
                      onClick={() => setActiveTab(tab)}
                      className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${
                        activeTab === tab
                          ? 'bg-white text-gray-900 shadow-sm'
                          : 'text-gray-500 hover:text-gray-700'
                      }`}
                    >
                      {CUTOFF_LABEL[tab]}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Current status for selected period */}
        {!isLoading && existing && (
          <div className="card p-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center text-green-600 shrink-0">
                <CheckCircle2 size={20} />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">{existing.original_filename}</p>
                <p className="text-xs text-gray-500 mt-0.5">
                  Uploaded {format(new Date(existing.created_at), 'MMM d, yyyy h:mm a')}
                  <span className="text-gray-300 mx-1.5">·</span>
                  Auto-deletes {format(new Date(existing.auto_delete_at), 'MMM d, yyyy')}
                </p>
              </div>
              <button
                onClick={() => setDeleteTarget(existing)}
                className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 shrink-0"
                title="Delete"
              >
                <Trash2 size={15} />
              </button>
            </div>
          </div>
        )}

        {/* Upload card */}
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">
            {existing ? 'Replace DTR' : 'Upload DTR'}
            <span className="ml-2 text-xs font-normal text-gray-400">
              {period} — {CUTOFF_LABEL[currentCutoff]}
            </span>
          </h2>

          <form onSubmit={handleUpload} className="space-y-4">
            {/* Drop zone */}
            <div
              onClick={() => fileInputRef.current?.click()}
              className={`relative border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors ${
                file
                  ? 'border-brand-400 bg-brand-50/40'
                  : 'border-gray-200 hover:border-brand-300 hover:bg-gray-50'
              }`}
            >
              <input
                ref={fileInputRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={e => setFile(e.target.files[0] || null)}
                className="hidden"
              />
              <div className="flex flex-col items-center gap-2">
                {file ? (
                  <>
                    <div className="w-10 h-10 rounded-lg bg-brand-100 flex items-center justify-center text-brand-600">
                      <FileText size={20} />
                    </div>
                    <p className="text-sm font-medium text-brand-700 max-w-xs truncate">{file.name}</p>
                    <p className="text-xs text-brand-500">
                      {(file.size / 1024 / 1024).toFixed(2)} MB — click to change
                    </p>
                  </>
                ) : (
                  <>
                    <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                      <Upload size={20} />
                    </div>
                    <p className="text-sm font-medium text-gray-600">Click to select a file</p>
                    <p className="text-xs text-gray-400">PDF, JPG, PNG — max 10 MB</p>
                  </>
                )}
              </div>
            </div>

            {upload.isError && (
              <div className="flex items-center gap-2 text-sm text-red-500">
                <AlertCircle size={14} className="shrink-0" />
                {upload.error?.response?.data?.message || 'Upload failed. Please try again.'}
              </div>
            )}

            <button
              type="submit"
              disabled={!file || upload.isPending}
              className="btn-primary flex items-center gap-2"
            >
              <Upload size={15} />
              {upload.isPending ? 'Uploading…' : existing ? 'Replace DTR' : 'Upload DTR'}
            </button>
          </form>
        </div>

        {/* History table */}
        {dtrs.length > 0 && (
          <div className="card overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100">
              <h2 className="text-sm font-semibold text-gray-700">All My DTR Uploads</h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    {['File', 'Period', 'Cutoff', 'Uploaded', ''].map(h => (
                      <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {dtrs.map(d => (
                    <tr key={d.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium text-gray-900 max-w-[180px] truncate" title={d.original_filename}>
                        {d.original_filename}
                      </td>
                      <td className="px-4 py-3 text-gray-600">{d.period_label}</td>
                      <td className="px-4 py-3 text-gray-600">{CUTOFF_LABEL[d.cutoff_type] ?? d.cutoff_type}</td>
                      <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                        {format(new Date(d.created_at), 'MMM d, yyyy')}
                        <br /><span className="text-[9px] text-gray-300">{format(new Date(d.created_at), 'h:mm a')}</span>
                      </td>
                      <td className="px-4 py-3">
                        <button
                          onClick={() => setDeleteTarget(d)}
                          className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50"
                          title="Delete"
                        >
                          <Trash2 size={14} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => remove.mutate(deleteTarget.id)}
        title="Delete DTR"
        message={`Delete "${deleteTarget?.original_filename}"? This cannot be undone.`}
        type="danger"
        confirmLabel="Delete"
      />
    </div>
  )
}
