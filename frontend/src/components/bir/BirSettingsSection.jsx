import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Power, KeyRound, Building2, Save } from 'lucide-react'
import clsx from 'clsx'
import {
  birKeys, getBirConfig, setBirSwitch, setBirSwitchPassword, adminSettingsKeys, getAdminSettings, updateAdminSetting,
} from '../../api/queries'

// The company details the 1601-C and 2316 read from system settings (keys match the schema keys).
// Patterns are Dev A's FieldFormat; a value that doesn't fit is left off the forms by the backend.
const DETAILS = [
  { key: 'company_name', label: "Registered name (withholding agent)", items: '1601-C item 8 · 2316 item 13', required: true },
  { key: 'company_tin', label: 'TIN', items: '1601-C item 6 · 2316 item 12', required: true, placeholder: '123-456-789-00000',
    pattern: /^\d{3}-\d{3}-\d{3}-\d{5}$/, hint: '14 digits: 123-456-789-00000' },
  { key: 'rdo_code', label: 'RDO code', items: '1601-C item 7', required: true, placeholder: '043', pattern: /^(\d{3}|\d{2}[A-Z])$/, hint: '3 characters, like 043 or 21A' },
  { key: 'company_address', label: 'Registered address', items: '1601-C item 9 · 2316 item 14', required: true },
  { key: 'company_zip', label: 'ZIP code', items: '1601-C item 9A · 2316 item 14A', required: true, placeholder: '1004', pattern: /^\d{4}$/, hint: '4 digits' },
  { key: 'company_contact_number', label: 'Contact number', items: '1601-C item 10 (optional)', placeholder: '09171234567',
    pattern: /^\+?\d{7,13}$/, clean: (v) => v.replace(/[\s().-]/g, ''), hint: 'Like 09171234567 or (02) 8123-4567' },
  { key: 'company_email', label: 'Email address', items: '1601-C item 12 (optional)', placeholder: 'name@company.com', pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, hint: 'An email address' },
]

const errorText = (err) => {
  if (err?.response?.status === 429) {
    const wait = err.response.headers?.['retry-after']
    return `Too many tries. Wait ${wait ? `${wait} seconds` : 'a minute'} and try again.`
  }
  const errors = err?.response?.data?.errors
  return (errors && Object.values(errors)[0]?.[0]) || err?.response?.data?.message || 'Something went wrong. Please try again.'
}

function Header({ icon: Icon, title, description }) {
  return (
    <div className="flex items-center gap-3">
      <div className="w-9 h-9 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600 shrink-0"><Icon size={18} /></div>
      <div>
        <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
        <p className="text-xs text-gray-500 mt-0.5">{description}</p>
      </div>
    </div>
  )
}

const Note = ({ note }) => note && (
  <p className={clsx('text-xs', note.tone === 'error' ? 'text-red-600' : 'text-green-700')} role={note.tone === 'error' ? 'alert' : 'status'}>{note.text}</p>
)

// On/off, guarded by the company password. No toggle: one button for the change (Ms. Kath's request).
function SwitchCard({ config }) {
  const qc = useQueryClient()
  const [password, setPassword] = useState('')
  const [note, setNote] = useState(null)
  const enabled = !!config?.enabled
  const flip = useMutation({
    mutationFn: () => setBirSwitch(!enabled, password),
    onMutate: () => setNote(null),
    onSuccess: (res) => { setNote({ tone: 'ok', text: res.message }); qc.invalidateQueries({ queryKey: birKeys.config }) },
    onError: (err) => setNote({ tone: 'error', text: errorText(err) }),
    onSettled: () => setPassword(''), // the password box is cleared after every try
  })

  return (
    <div className="space-y-3">
      <Header icon={Power} title="BIR Forms switch" description="While it's off, nobody can open or use the BIR Form Assistant." />
      <p className="text-sm">
        BIR Forms: <span className={clsx('font-semibold', enabled ? 'text-green-700' : 'text-gray-500')}>{config ? (enabled ? 'On' : 'Off') : '…'}</span>
      </p>
      {config && !config.switch_password_set ? (
        <p className="text-xs text-amber-700">Set a company password below before turning BIR Forms on.</p>
      ) : (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (password) flip.mutate() }}>
          <label className="text-xs text-gray-600 flex-1 min-w-[200px]">
            Company password
            <input type="password" className="input mt-1" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="off" />
          </label>
          <button type="submit" disabled={!password || flip.isPending || !config} className={enabled ? 'btn-danger' : 'btn-primary'}>
            {flip.isPending ? 'Checking…' : enabled ? 'Turn off' : 'Turn on'}
          </button>
        </form>
      )}
      <Note note={note} />
    </div>
  )
}

function PasswordCard({ config }) {
  const qc = useQueryClient()
  const blank = { current_password: '', password: '', password_confirmation: '' }
  const [form, setForm] = useState(blank)
  const [note, setNote] = useState(null)
  const isSet = !!config?.switch_password_set
  const save = useMutation({
    mutationFn: () => setBirSwitchPassword(isSet ? form : { password: form.password, password_confirmation: form.password_confirmation }),
    onMutate: () => setNote(null),
    onSuccess: (res) => { setNote({ tone: 'ok', text: res.message }); setForm(blank); qc.invalidateQueries({ queryKey: birKeys.config }) },
    onError: (err) => setNote({ tone: 'error', text: errorText(err) }),
  })
  const field = (key, label) => (
    <label className="text-xs text-gray-600">
      {label}
      <input type="password" className="input mt-1" value={form[key]} autoComplete="new-password"
        onChange={(e) => setForm(f => ({ ...f, [key]: e.target.value }))} />
    </label>
  )
  const short = form.password.length > 0 && form.password.length < 8
  const mismatch = form.password_confirmation.length > 0 && form.password !== form.password_confirmation

  return (
    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <Header icon={KeyRound} title={isSet ? 'Change company password' : 'Set company password'}
        description="One shared password for the BIR switch, not anyone's login. It is stored hashed and never shown again." />
      <div className="grid gap-3 sm:grid-cols-3">
        {isSet && field('current_password', 'Current password')}
        {field('password', 'New password (at least 8 characters)')}
        {field('password_confirmation', 'Confirm new password')}
      </div>
      {(short || mismatch) && <p className="text-xs text-amber-700">{short ? 'Use at least 8 characters.' : 'The two passwords are different.'}</p>}
      <button type="submit" className="btn-secondary"
        disabled={save.isPending || !config || form.password.length < 8 || mismatch || !form.password_confirmation || (isSet && !form.current_password)}>
        {save.isPending ? 'Saving…' : isSet ? 'Change password' : 'Set password'}
      </button>
      <Note note={note} />
    </form>
  )
}

function DetailsCard() {
  const qc = useQueryClient()
  const { data: settings } = useQuery({ queryKey: adminSettingsKeys.all, queryFn: getAdminSettings })
  const [values, setValues] = useState({})
  const [note, setNote] = useState(null)
  const stored = (key) => (settings ?? []).find(s => s.key === key)?.value ?? ''
  useEffect(() => {
    if (settings) setValues(Object.fromEntries([...DETAILS.map(d => [d.key, stored(d.key)]), ['agent_category', stored('agent_category') || 'private']]))
  }, [settings]) // eslint-disable-line react-hooks/exhaustive-deps

  const problem = (d) => {
    const v = (values[d.key] ?? '').trim()
    if (!v) return d.required ? 'Required for the forms.' : null
    return d.pattern && !d.pattern.test(d.clean ? d.clean(v) : v) ? `Should be ${d.hint.toLowerCase()}.` : null
  }
  const changed = [...DETAILS.map(d => d.key), 'agent_category'].filter(k => (values[k] ?? '').trim() !== String(stored(k)))
  const invalid = DETAILS.some(d => changed.includes(d.key) && problem(d) && (values[d.key] ?? '').trim())

  const save = useMutation({
    // The settings endpoint needs a value, so an emptied optional field is left as it was.
    mutationFn: async () => {
      for (const key of changed) {
        const value = (values[key] ?? '').trim()
        // The setting's description is sent back unchanged: a save without one would erase it.
        const description = (settings ?? []).find(x => x.key === key)?.description ?? undefined
        if (value) await updateAdminSetting(key, value, description, 'string')
      }
    },
    onMutate: () => setNote(null),
    onSuccess: () => { setNote({ tone: 'ok', text: 'Company BIR details saved. New drafts use them.' }); qc.invalidateQueries({ queryKey: adminSettingsKeys.all }) },
    onError: (err) => setNote({ tone: 'error', text: errorText(err) }),
  })

  return (
    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <Header icon={Building2} title="Company BIR details" description="Printed on every 1601-C and 2316. Drafts made before a change keep the old details." />
      <div className="grid gap-3 sm:grid-cols-2">
        {DETAILS.map(d => {
          const p = (values[d.key] ?? '') !== '' || d.required ? problem(d) : null
          return (
            <label key={d.key} className={clsx('text-xs text-gray-600', ['company_name', 'company_address'].includes(d.key) && 'sm:col-span-2')}>
              {d.label} <span className="text-gray-400">· {d.items}</span>
              <input className={clsx('input mt-1', p && 'border-amber-300')} value={values[d.key] ?? ''} placeholder={d.placeholder}
                onChange={(e) => setValues(v => ({ ...v, [d.key]: e.target.value }))} />
              {p && <span className="mt-0.5 block text-[11px] text-amber-700">{p}</span>}
            </label>
          )
        })}
        <label className="text-xs text-gray-600">
          Category of withholding agent <span className="text-gray-400">· 1601-C item 11</span>
          <select className="input mt-1" value={values.agent_category ?? 'private'} onChange={(e) => setValues(v => ({ ...v, agent_category: e.target.value }))}>
            <option value="private">Private</option>
            <option value="government">Government</option>
          </select>
        </label>
      </div>
      <button type="submit" className="btn-primary" disabled={!settings || !changed.length || invalid || save.isPending}>
        <Save size={15} /> {save.isPending ? 'Saving…' : 'Save BIR details'}
      </button>
      <Note note={note} />
    </form>
  )
}

/** System Settings → BIR Forms (admin only): the switch, its company password, and the company's BIR details. */
export default function BirSettingsSection() {
  const { data: config } = useQuery({ queryKey: birKeys.config, queryFn: getBirConfig })
  return (
    <div className="card overflow-hidden">
      <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600"><FileText size={20} /></div>
          <div>
            <h2 className="text-sm font-semibold text-gray-900">BIR Forms</h2>
            <p className="text-xs text-gray-500 mt-0.5">Turn the BIR Form Assistant on or off and keep the company's BIR registration details.</p>
          </div>
        </div>
      </div>
      <div className="p-6 space-y-8 divide-y divide-gray-100 [&>*+*]:pt-8">
        <SwitchCard config={config} />
        <PasswordCard config={config} />
        <DetailsCard />
      </div>
    </div>
  )
}
