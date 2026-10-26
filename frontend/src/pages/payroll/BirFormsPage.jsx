import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, FlaskConical, ListChecks, FolderOpen, Maximize2, MessageCircle, X, Power, Users, BookOpen } from 'lucide-react'
import clsx from 'clsx'
import {
  birKeys, getBirConfig, getBirEmployees, createBirDraft, setBirMockUser, BIR_USE_MOCKS, BIR_DISABLED_EVENT, BIR_DISABLED_MESSAGE,
} from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { PageHeader, EmptyState, AlertModal } from '../../components/ui/index.jsx'
import BirChatPanel, { formName } from '../../components/bir/BirChatPanel'
import BirFieldPreview from '../../components/bir/BirFieldPreview'
import BirDraftList from '../../components/bir/BirDraftList'
import BirEmployeesView from '../../components/bir/BirEmployeesView'
import BirCreateError from '../../components/bir/BirExistingForm'
import BirAccountantGuide from '../../components/bir/BirAccountantGuide'

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
      <label className="block text-xs text-gray-500">
        Form
        <select className="input mt-1" value={formType} onChange={(e) => setFormType(e.target.value)}>
          {formTypes.map(t => <option key={t} value={t}>{formName(t)}</option>)}
        </select>
      </label>
      <div className={is2316 ? 'grid grid-cols-[1fr_2fr] gap-2' : 'grid grid-cols-2 gap-2'}>
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
      {create.isError && <BirCreateError error={create.error} onOpen={onCreated} fallback="Could not create the draft." />}
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
  // The box the chat is asking about, highlighted in the preview.
  const [askingField, setAskingField] = useState(null)
  const [tab, setTab] = useState('forms')
  // The assistant opens like a Messenger chat; it stays mounted while closed so the conversation is kept.
  const [chatOpen, setChatOpen] = useState(false)
  const [unread, setUnread] = useState(false)
  const [turnedOff, setTurnedOff] = useState(false)
  const { user } = useAuth()
  // The BIR Forms guide opens by itself the first time each accountant comes here (after the
  // app's own first-login tour, so the two never stack); the Guide button reopens it for anyone.
  const guideKey = `bir_guide_v1_${user?.id}`
  const [guideOpen, setGuideOpen] = useState(() => {
    try { return user?.role === 'accounting' && !!localStorage.getItem('hr_onboarding_v1') && !localStorage.getItem(guideKey) } catch { return false }
  })
  const closeGuide = () => {
    try { localStorage.setItem(guideKey, '1') } catch { /* private window: it just opens again next time */ }
    setGuideOpen(false)
  }
  const navigate = useNavigate()
  const qc = useQueryClient()

  // Mock mode only: drafts you create are prepared by you, and the role rules follow your login.
  useEffect(() => { setBirMockUser(user) }, [user])

  const { data: config } = useQuery({
    queryKey: birKeys.config,
    queryFn: getBirConfig,
    staleTime: Infinity,
  })

  // The switch was turned off while this page was open: every BIR call now answers 403.
  useEffect(() => {
    const onDisabled = () => setTurnedOff(true)
    window.addEventListener(BIR_DISABLED_EVENT, onDisabled)
    return () => window.removeEventListener(BIR_DISABLED_EVENT, onDisabled)
  }, [])
  const leave = () => {
    qc.invalidateQueries({ queryKey: birKeys.config })
    navigate('/hr')
  }

  // Counts every time a form is opened, so reopening the one already in the URL (after
  // "New conversation", say) still moves the chat to it.
  const [opened, setOpened] = useState(0)
  const onDraft = (draft) => {
    setAskingField(null)
    setParams({ draft: String(draft.id) })
    setOpened(n => n + 1)
  }
  // A voided form stays open, read-only and marked void; the note says which one.
  const [voidedNote, setVoidedNote] = useState(null)
  const [voidedId, setVoidedId] = useState(null)
  const onVoided = (draft, message) => {
    setAskingField(null)
    setVoidedId(draft.id) // the chat lets go of it: it can no longer be changed
    setVoidedNote(message || `Form ${draft.label ?? `#${draft.id}`} voided`)
  }
  useEffect(() => { setVoidedNote(null) }, [draftId])

  const onQuestion = (field) => {
    setAskingField(field)
    if (field && !chatOpen) setUnread(true)
  }
  const toggleChat = () => { setChatOpen(o => !o); setUnread(false) }

  const formTypes = config?.form_types ?? ['1601-C', '2316']

  if (config && config.enabled === false) {
    return (
      <div>
        <PageHeader title="BIR Forms" />
        <div className="card">
          <EmptyState
            icon={Power}
            title="The BIR Form Assistant is turned off"
            description={user?.role === 'admin'
              ? 'Turn it on in System Settings, under BIR Forms, with the company password.'
              : 'An administrator can turn it on in System Settings.'}
            action={user?.role === 'admin' && <Link to="/admin/system-settings" className="btn-primary text-sm">Open System Settings</Link>}
          />
        </div>
      </div>
    )
  }

  return (
    <div className="pb-36">{/* room for the chat bubble */}
      <PageHeader
        title="Compliance"
        action={(
          <div className="flex items-center gap-2">
            <button onClick={() => setGuideOpen(true)} className="btn-secondary text-xs" title="How to prepare, check, file and sign BIR forms">
              <BookOpen size={13} /> Guide
            </button>
            {BIR_USE_MOCKS && (
              <span className="badge-purple gap-1" title="Set VITE_BIR_MOCK=false to use the real API">
                <FlaskConical size={12} /> Mock data
              </span>
            )}
          </div>
        )}
      />
      <BirAccountantGuide open={guideOpen} onDismiss={closeGuide} />

      <div className="grid gap-5 lg:grid-cols-5">
        <section className="card overflow-hidden lg:col-span-3 self-start">
          <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
            <FileText size={15} className="text-brand-600" /> Form preview
            {draftId && (
              <Link to={`/hr/bir-forms/${draftId}`} className="ml-auto inline-flex items-center gap-1 text-xs font-normal text-gray-500 hover:text-brand-600">
                <Maximize2 size={12} /> Full view
              </Link>
            )}
          </h2>
          {voidedNote && <p className="px-4 pt-3 text-xs text-green-700" role="status">{voidedNote}</p>}
          <BirFieldPreview draftId={draftId} askingField={askingField} onOpenDraft={onDraft} onVoided={onVoided} />
        </section>

        <div className="lg:col-span-2 space-y-5">
          <section className="card overflow-hidden">
            <div className="flex border-b border-gray-100" role="tablist">
              {[['forms', FolderOpen, 'Saved forms'], ['employees', Users, 'Employees']].map(([key, Icon, label]) => (
                <button
                  key={key}
                  role="tab"
                  aria-selected={tab === key}
                  onClick={() => setTab(key)}
                  className={clsx('flex-1 px-4 py-3 text-sm font-semibold flex items-center justify-center gap-2 border-b-2 -mb-px',
                    tab === key ? 'border-brand-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700')}
                >
                  <Icon size={15} className={tab === key ? 'text-brand-600' : 'text-gray-400'} /> {label}
                </button>
              ))}
            </div>
            {tab === 'forms'
              ? <BirDraftList formTypes={formTypes} activeId={draftId} onOpen={onDraft} />
              : <BirEmployeesView activeId={draftId} onOpen={onDraft} />}
          </section>

          <section className="card">
            <h2 className="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900 flex items-center gap-2">
              <ListChecks size={15} className="text-brand-600" /> Pick a form manually
            </h2>
            <ManualPicker formTypes={formTypes} onCreated={onDraft} />
          </section>
        </div>
      </div>

      {/* Raised above the island bubble in the corner, and the same size as it. */}
      <div className="fixed bottom-24 right-5 z-40 flex flex-col items-end gap-3">
        <div className={chatOpen ? 'block' : 'hidden'}>
          <BirChatPanel
            className="w-[min(400px,calc(100vw-2.5rem))] h-[min(600px,calc(100vh-12rem))] shadow-2xl"
            onClose={toggleChat}
            onDraftCreated={onDraft}
            onQuestion={onQuestion}
            openDraftId={draftId}
            openKey={opened}
            voidedDraftId={voidedId}
          />
        </div>
        <button
          onClick={toggleChat}
          className="relative w-12 h-12 rounded-full bg-brand-600 text-white shadow-lg hover:bg-brand-700 flex items-center justify-center transition-colors"
          aria-label={chatOpen ? 'Close the form assistant' : 'Open the form assistant'}
          aria-expanded={chatOpen}
          title="Form assistant"
        >
          {chatOpen ? <X size={20} /> : <MessageCircle size={22} />}
          {unread && !chatOpen && <span className="absolute top-0.5 right-0.5 w-3 h-3 rounded-full bg-red-500 ring-2 ring-white" />}
        </button>
      </div>

      <AlertModal open={turnedOff} onClose={leave} title="BIR Forms turned off" message={`${BIR_DISABLED_MESSAGE}. An administrator turned it off.`} type="error" />
    </div>
  )
}
