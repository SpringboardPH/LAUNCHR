import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, FlaskConical, ListChecks } from 'lucide-react'
import { birKeys, getBirConfig, getBirEmployees, createBirDraft, BIR_USE_MOCKS } from '../../api/queries'
import { PageHeader } from '../../components/ui/index.jsx'
import BirChatPanel from '../../components/bir/BirChatPanel'
import BirFieldPreview from '../../components/bir/BirFieldPreview'

const CY = new Date().getFullYear()
const YEAR_OPTIONS = Array.from({ length: 4 }, (_, i) => CY - i)
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// Works without the assistant — the chat is a convenience, not the only way in.
function ManualPicker({ formTypes, onCreated }) {
  const qc = useQueryClient()
  const [formType, setFormType] = useState('1601-C')
  const [year, setYear] = useState(CY)
  const [month, setMonth] = useState(new Date().getMonth() || 12)
  const [employeeId, setEmployeeId] = useState('')

  const { data: employees, isLoading: loadingEmployees } = useQuery({
    queryKey: birKeys.employees,
    queryFn: getBirEmployees,
    enabled: formType === '2316',
    staleTime: 5 * 60_000,
  })

  const create = useMutation({
    mutationFn: createBirDraft,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      onCreated(res.data)
    },
  })

  const is2316 = formType === '2316'
  const period = is2316 ? String(year) : `${year}-${String(month).padStart(2, '0')}`
  const sortedEmployees = [...(employees?.data ?? [])]
    .sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))

  return (
    <form
      className="p-4 space-y-3"
      onSubmit={(e) => {
        e.preventDefault()
        create.mutate({ form_type: formType, period, ...(is2316 && { employee_id: Number(employeeId) }) })
      }}
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
        {is2316 ? (
          <label className="text-xs text-gray-500">
            Employee
            <select className="input mt-1" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} disabled={loadingEmployees}>
              <option value="">{loadingEmployees ? 'Loading…' : 'Choose…'}</option>
              {sortedEmployees.map(emp => (
                <option key={emp.id} value={emp.id}>{emp.last_name}, {emp.first_name} ({emp.employee_id})</option>
              ))}
            </select>
          </label>
        ) : (
          <label className="text-xs text-gray-500">
            Month
            <select className="input mt-1" value={month} onChange={(e) => setMonth(Number(e.target.value))}>
              {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
            </select>
          </label>
        )}
      </div>
      {create.isError && (
        <p className="text-xs text-red-600">{create.error?.response?.data?.message || 'Could not create the draft.'}</p>
      )}
      <button type="submit" className="btn-secondary w-full justify-center" disabled={create.isPending || (is2316 && !employeeId)}>
        {create.isPending ? 'Creating…' : 'Create draft'}
      </button>
    </form>
  )
}

export default function BirFormsPage() {
  // The open draft lives in the URL (?draft=5) so a draft can be linked to and reopened.
  const [params, setParams] = useSearchParams()
  const draftId = Number(params.get('draft')) || null

  const { data: config } = useQuery({
    queryKey: birKeys.config,
    queryFn: getBirConfig,
    staleTime: Infinity,
  })

  const onDraft = (draft) => setParams({ draft: String(draft.id) })

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
        <div className="lg:col-span-2">
          <BirChatPanel onDraftCreated={onDraft} />
        </div>

        <div className="lg:col-span-3 space-y-5">
          <section className="card overflow-hidden">
            <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
              <FileText size={15} className="text-brand-600" /> Form preview
            </h2>
            <BirFieldPreview draftId={draftId} />
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