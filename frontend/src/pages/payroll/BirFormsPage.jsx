import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, FlaskConical, ListChecks } from 'lucide-react'
import { birKeys, getBirConfig, getBirDraft, createBirDraft, BIR_USE_MOCKS } from '../../api/queries'
import { PageHeader, EmptyState, Spinner, StatusBadge } from '../../components/ui/index.jsx'
import BirChatPanel, { describePeriod } from '../../components/bir/BirChatPanel'

const CY = new Date().getFullYear()
const YEAR_OPTIONS = Array.from({ length: 4 }, (_, i) => CY - i)
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// Week 3 replaces this summary with BirFieldPreview.
function CurrentDraft({ draftId }) {
  const { data: draft, isLoading, isError } = useQuery({
    queryKey: birKeys.detail(draftId),
    queryFn: () => getBirDraft(draftId),
    enabled: !!draftId,
  })

  if (!draftId) {
    return (
      <EmptyState
        icon={FileText}
        title="No draft yet"
        description="Ask the assistant for a form, or pick one below. The draft will appear here."
      />
    )
  }
  if (isLoading) return <div className="py-14 flex justify-center"><Spinner /></div>
  if (isError || !draft) return <p className="py-10 text-center text-sm text-red-600">Could not load draft #{draftId}.</p>

  const entries = Object.values(draft.fields ?? {})
  const count = (origin) => entries.filter(e => e.origin === origin).length
  const pending = count('pending')

  return (
    <div className="p-4 space-y-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs text-gray-400">Draft #{draft.id} · version {draft.version}</p>
          <p className="text-base font-semibold text-gray-900">BIR Form {draft.form_type}</p>
          <p className="text-sm text-gray-600">
            {describePeriod(draft.form_type, draft.period)}
            {draft.employee_name && ` · ${draft.employee_name}`}
          </p>
        </div>
        <StatusBadge status={draft.status} />
      </div>
      <dl className="grid grid-cols-2 gap-2 text-center">
        {[
          ['From payroll', count('payroll')],
          ['From settings', count('settings')],
          ['Entered by user', count('user')],
          ['Awaiting input', pending],
        ].map(([label, n]) => (
          <div key={label} className="rounded-lg bg-gray-50 px-2 py-2.5">
            <dd className="text-lg font-semibold text-gray-900 tabular-nums">{n}</dd>
            <dt className="text-[11px] text-gray-500">{label}</dt>
          </div>
        ))}
      </dl>
      {pending > 0 && (
        <p className="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
          {pending} field{pending === 1 ? '' : 's'} can't be filled from payroll and will need your input.
        </p>
      )}
    </div>
  )
}

// Works without the assistant — the chat is a convenience, not the only way in.
function ManualPicker({ formTypes, onCreated }) {
  const qc = useQueryClient()
  const [formType, setFormType] = useState('1601-C')
  const [year, setYear] = useState(CY)
  const [month, setMonth] = useState(new Date().getMonth() || 12)

  const create = useMutation({
    mutationFn: createBirDraft,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      onCreated(res.data)
    },
  })

  const period = formType === '1601-C' ? `${year}-${String(month).padStart(2, '0')}` : String(year)

  return (
    <form
      className="p-4 space-y-3"
      onSubmit={(e) => { e.preventDefault(); create.mutate({ form_type: formType, period }) }}
    >
      <div className="grid grid-cols-3 gap-2">
        <label className="text-xs text-gray-500">
          Form
          <select className="input mt-1" value={formType} onChange={(e) => setFormType(e.target.value)}>
            {formTypes.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500">
          Year
          <select className="input mt-1" value={year} onChange={(e) => setYear(Number(e.target.value))}>
            {YEAR_OPTIONS.map(y => <option key={y} value={y}>{y}</option>)}
          </select>
        </label>
        {formType === '1601-C' && (
          <label className="text-xs text-gray-500">
            Month
            <select className="input mt-1" value={month} onChange={(e) => setMonth(Number(e.target.value))}>
              {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
            </select>
          </label>
        )}
      </div>
      {formType === '2316' && (
        <p className="text-xs text-gray-400">Choosing the employee for a 2316 arrives with employee lookup in week 3.</p>
      )}
      {create.isError && (
        <p className="text-xs text-red-600">{create.error?.response?.data?.message || 'Could not create the draft.'}</p>
      )}
      <button type="submit" className="btn-secondary w-full justify-center" disabled={create.isPending}>
        {create.isPending ? 'Creating…' : 'Create draft'}
      </button>
    </form>
  )
}

export default function BirFormsPage() {
  const [draftId, setDraftId] = useState(null)

  const { data: config } = useQuery({
    queryKey: birKeys.config,
    queryFn: getBirConfig,
    staleTime: Infinity,
  })

  const onDraft = (draft) => setDraftId(draft.id)

  return (
    <div>
      <PageHeader
        title="BIR Forms"
        description="Prepare Form 1601-C (monthly) and Form 2316 (annual, per employee) from payroll data."
        action={BIR_USE_MOCKS && (
          <span className="badge-purple gap-1" title="Set VITE_BIR_MOCK=false to use the real API">
            <FlaskConical size={12} /> Mock data
          </span>
        )}
      />

      <div className="grid gap-5 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <BirChatPanel onDraftCreated={onDraft} />
        </div>

        <div className="lg:col-span-2 space-y-5">
          <section className="card">
            <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
              <FileText size={15} className="text-brand-600" /> Current draft
            </h2>
            <CurrentDraft draftId={draftId} />
          </section>

          <section className="card">
            <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
              <ListChecks size={15} className="text-brand-600" /> Pick a form manually
            </h2>
            <ManualPicker formTypes={config?.form_types ?? ['1601-C', '2316']} onCreated={onDraft} />
          </section>
        </div>
      </div>
    </div>
  )
}
