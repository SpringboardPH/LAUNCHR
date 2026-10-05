import { useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileText, AlertTriangle, RefreshCw, Scale, Upload, X, Info } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import clsx from 'clsx'
import { birKeys, getBirConfig, getBirDraft } from '../../api/queries'
import { EmptyState, StatusBadge } from '../ui/index.jsx'
import { describePeriod, formName } from './BirChatPanel'

// How each kind of value looks. `edited` is a user value that replaced a calculated one.
const KINDS = {
  payroll:  { label: 'From payroll',      tag: 'bg-blue-50 text-blue-700',     bar: 'bg-blue-400' },
  settings: { label: 'Company settings',  tag: 'bg-purple-50 text-purple-700', bar: 'bg-purple-400' },
  user:     { label: 'Typed by user',     tag: 'bg-green-50 text-green-700',   bar: 'bg-green-400' },
  edited:   { label: 'Manually changed',  tag: 'bg-amber-100 text-amber-800',  bar: 'bg-amber-500', row: 'bg-amber-50/60' },
  pending:  { label: 'Awaiting input',    tag: 'bg-gray-100 text-gray-500',    bar: 'bg-gray-200' },
  manual:   { label: 'Signed on paper',   tag: 'bg-gray-100 text-gray-500',    bar: 'bg-gray-200' },
}
const kindOf = (entry) => (entry.edited ? 'edited' : (KINDS[entry.origin] ? entry.origin : 'pending'))

const humanize = (key) => key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
const peso = (n) => `₱${Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const isNumeric = (v) => v !== null && v !== '' && !Number.isNaN(Number(v))

const formatValue = (value, type) => {
  if (value === null || value === undefined || value === '') return null
  if (type === 'boolean' || typeof value === 'boolean') return value === true || value === 'true' || value === '1' ? 'Yes' : 'No'
  if (type === 'decimal' && isNumeric(value)) return peso(value)
  return String(value)
}

const formatWhen = (iso) => {
  try { return format(parseISO(iso), 'MMM d, yyyy h:mm a') } catch { return iso }
}

// Fields compared against a filed form — the same rule as `php artisan bir:compare`.
const isCompared = (field) => field.source === 'payroll' && field.type === 'decimal' && field.item != null

// 2316 Part IV-B Section B — taxable compensation, items 39 to 52 (including 44A/B, 51A/B).
const isTaxableSection = (field) => {
  const n = parseInt(field.item, 10)
  return n >= 39 && n <= 52
}

// An employee with pay but nothing taxable (item 52 is zero) — a minimum wage earner, or
// at or below the P250,000 exemption. The backend classifies them; the figures show it.
const nonTaxableReason = (draft) => {
  if (draft.form_type !== '2316') return null
  const value = (key) => draft.fields?.[key]?.value
  const taxable = value('tax_regular_total'), gross = value('gross_compensation_present')
  if (!isNumeric(taxable) || Number(taxable) !== 0 || !isNumeric(gross) || Number(gross) <= 0) return null
  const mwe = value('is_mwe') === true || value('is_mwe') === 'true'
  return mwe ? 'is a minimum wage earner' : 'has no taxable compensation (a minimum wage earner, or ₱250,000 or less for the year)'
}
const TOLERANCE = 0.005

// Minimal CSV reader for the bir:compare template (quoted labels contain commas).
const parseCsv = (text) => {
  const rows = []
  let row = [], cell = '', quoted = false
  for (let i = 0; i < text.length; i++) {
    const c = text[i]
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++ }
      else if (c === '"') quoted = false
      else cell += c
    } else if (c === '"') quoted = true
    else if (c === ',') { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++
      row.push(cell); rows.push(row); row = []; cell = ''
    } else cell += c
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row) }
  return rows
}

function SkeletonRows() {
  return (
    <div className="p-4 space-y-2 animate-pulse" aria-label="Loading draft">
      <div className="h-5 w-2/3 rounded bg-gray-100" />
      <div className="h-2 w-full rounded bg-gray-100" />
      {Array.from({ length: 7 }, (_, i) => <div key={i} className="h-9 rounded bg-gray-50" />)}
    </div>
  )
}

function FieldRow({ field, entry, error, compare, filed, onFiled, notApplicable }) {
  const kind = kindOf(entry)
  const style = KINDS[kind]
  const shown = formatValue(entry.value, field.type)
  const compared = compare && isCompared(field)
  const diff = compared && isNumeric(filed) && isNumeric(entry.value) ? Number(entry.value) - Number(filed) : null
  const matches = diff !== null && Math.abs(diff) < TOLERANCE

  return (
    <li
      className={clsx('relative pl-4 pr-3 py-2', notApplicable ? 'bg-gray-50 opacity-50' : style.row, error && 'ring-1 ring-inset ring-red-200')}
      title={notApplicable ? 'Does not apply to a non-taxable employee' : undefined}
    >
      <span aria-hidden="true" className={clsx('absolute inset-y-0 left-0 w-1', style.bar)} />
      <div className="flex items-start gap-3">
        <span className="w-8 shrink-0 pt-0.5 text-[11px] font-medium text-gray-400 tabular-nums">{field.item ?? ''}</span>
        <div className="min-w-0 flex-1">
          <p className="text-xs text-gray-500 leading-snug">
            {field.label}{field.required && kind === 'pending' && <span className="text-red-500"> *</span>}
          </p>
          {shown !== null ? (
            <p className={clsx('text-sm font-medium text-gray-900 break-words', field.type === 'decimal' && 'tabular-nums')}>{shown}</p>
          ) : (
            <p className="text-sm italic text-gray-400">{kind === 'manual' ? 'Signed by hand after printing' : 'Not filled yet'}</p>
          )}
                    {/* The server records the replaced figure and who changed it from Week 6; until
              then an edit arrives with neither, so show only what is actually known. */}
          {entry.edited && (
            <p className="mt-0.5 text-[11px] text-amber-800">
              {entry.system_value == null && !entry.edited_by
                ? 'Changed by hand. The calculated figure and who changed it are not on record for this edit.'
                : <>
                    {entry.system_value != null && (
                      <>Calculated: <span className="line-through tabular-nums">{formatValue(entry.system_value, field.type)}</span></>
                    )}
                    {entry.system_value != null && entry.edited_by && ' · '}
                    {entry.edited_by && `Changed by ${entry.edited_by.name}${entry.edited_at ? `, ${formatWhen(entry.edited_at)}` : ''}`}
                  </>}
            </p>
          )}
          {error && <p className="mt-0.5 text-[11px] text-red-600">{error}</p>}
          {compared && (
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              <label className="text-[11px] text-gray-500">
                Filed
                <input
                  inputMode="decimal"
                  value={filed ?? ''}
                  onChange={(e) => onFiled(field.key, e.target.value)}
                  placeholder="0.00"
                  className="input ml-1.5 inline-block w-32 py-1 text-xs tabular-nums"
                />
              </label>
              {diff !== null && (
                <span className={clsx('text-[11px] font-medium', matches ? 'text-green-700' : 'text-red-600')}>
                  {matches ? 'Matches' : `Differs by ${peso(Math.abs(diff))} (${diff > 0 ? 'ours higher' : 'ours lower'})`}
                </span>
              )}
            </div>
          )}
        </div>
        <span className={clsx('shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium', notApplicable ? 'bg-gray-200 text-gray-600' : style.tag)}>
          {notApplicable ? 'Not applicable' : style.label}
        </span>
      </div>
    </li>
  )
}

/**
 * The draft as the form will read: every box in printed order, with where each
 * figure came from. Week 4 adds a comparison against a form that was already
 * filed, for the go/no-go check.
 */
export default function BirFieldPreview({ draftId }) {
  const [filter, setFilter] = useState('all')
  const [compare, setCompare] = useState(false)
  const [filedByDraft, setFiledByDraft] = useState({})
  const [importNote, setImportNote] = useState(null)
  const fileRef = useRef(null)

  const { data: config } = useQuery({ queryKey: birKeys.config, queryFn: getBirConfig, staleTime: Infinity })
  const { data: draft, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: birKeys.detail(draftId),
    queryFn: () => getBirDraft(draftId),
    enabled: !!draftId,
  })

  const filed = filedByDraft[draftId] ?? {}
  const setFiled = (key, value) => setFiledByDraft(prev => ({ ...prev, [draftId]: { ...(prev[draftId] ?? {}), [key]: value } }))

  // Schema order and labels when the server provides them; otherwise the draft's own keys.
  const schema = draft ? config?.schemas?.[draft.form_type] : null
  const rows = useMemo(() => {
    if (!draft) return []
    const fields = draft.fields ?? {}
    const known = schema ?? []
    const listed = known.filter(f => f.key in fields)
    const extra = Object.keys(fields)
      .filter(k => !known.some(f => f.key === k))
      .map(k => ({ key: k, item: null, label: humanize(k), type: isNumeric(fields[k]?.value) && String(fields[k].value).includes('.') ? 'decimal' : 'string', source: fields[k]?.origin, required: false }))
    return [...listed, ...extra].map(field => ({ field, entry: fields[field.key] }))
  }, [draft, schema])

  if (!draftId) {
    return <EmptyState icon={FileText} title="No draft yet" description="Ask the assistant for a form, or pick one below. Its boxes will fill in here." />
  }
  if (isLoading) return <SkeletonRows />
  if (isError || !draft) {
    const status = error?.response?.status
    return (
      <div className="p-6 text-center">
        <AlertTriangle size={22} className="mx-auto mb-2 text-red-400" />
        <p className="text-sm text-red-600">
          {status === 404 ? `Draft #${draftId} no longer exists.` : error?.response?.data?.message || `Could not load draft #${draftId}.`}
        </p>
        {status !== 404 && (
          <button onClick={() => refetch()} className="btn-secondary mx-auto mt-3 text-xs"><RefreshCw size={12} /> Try again</button>
        )}
      </div>
    )
  }

  const errors = Object.fromEntries((draft.validation_errors ?? []).map(e => [e.field, e.message]))
  const nonTaxable = nonTaxableReason(draft)
  const counts = rows.reduce((acc, { entry }) => ({ ...acc, [kindOf(entry)]: (acc[kindOf(entry)] ?? 0) + 1 }), {})
  const fillable = rows.filter(({ field }) => field.type !== 'manual')
  const filledCount = fillable.filter(({ entry }) => entry.value !== null && entry.value !== '').length
  const requiredMissing = rows.filter(({ field, entry }) => field.required && kindOf(entry) === 'pending').length
  const visible = rows.filter(({ entry }) => filter === 'all' || kindOf(entry) === filter)

  const comparedRows = rows.filter(({ field }) => isCompared(field))
  const tally = comparedRows.reduce((acc, { field, entry }) => {
    const f = filed[field.key]
    if (!isNumeric(f)) return { ...acc, blank: acc.blank + 1 }
    return Math.abs(Number(entry.value ?? 0) - Number(f)) < TOLERANCE ? { ...acc, match: acc.match + 1 } : { ...acc, differ: acc.differ + 1 }
  }, { match: 0, differ: 0, blank: 0 })

  const importCsv = async (file) => {
    const table = parseCsv(await file.text())
    const header = (table.shift() ?? []).map(h => h.replace(/^﻿/, '').trim().toLowerCase())
    const keyCol = header.indexOf('key'), filedCol = header.indexOf('filed')
    if (keyCol < 0 || filedCol < 0) {
      setImportNote('That file needs "key" and "filed" columns — start from the bir:compare template.')
      return
    }
    const values = {}
    table.forEach(r => {
      const key = r[keyCol]?.trim(), v = (r[filedCol] ?? '').replace(/[^\d.-]/g, '')
      if (key && isNumeric(v)) values[key] = v
    })
    setFiledByDraft(prev => ({ ...prev, [draftId]: { ...(prev[draftId] ?? {}), ...values } }))
    setImportNote(`Imported ${Object.keys(values).length} filed figure(s).`)
  }

  return (
    <div>
      <div className="px-4 pt-4 pb-3 space-y-3 border-b border-gray-100">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs text-gray-400">Draft #{draft.id} · version {draft.version}{isFetching && ' · updating…'}</p>
            <p className="text-base font-semibold text-gray-900 leading-snug">{formName(draft.form_type)}</p>
            <p className="text-sm text-gray-600">
              {describePeriod(draft.form_type, draft.period)}{draft.employee_name && ` · ${draft.employee_name}`}
            </p>
          </div>
          <StatusBadge status={draft.status} />
        </div>

        <div>
          <div className="flex justify-between text-[11px] text-gray-500">
            <span>{filledCount} of {fillable.length} boxes filled</span>
            {requiredMissing > 0 && <span className="text-amber-700">{requiredMissing} required still empty</span>}
          </div>
          <div className="mt-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
            <div className="h-full bg-brand-500 transition-all" style={{ width: `${fillable.length ? (filledCount / fillable.length) * 100 : 0}%` }} />
          </div>
        </div>

        {nonTaxable && (
          <p className="flex gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
            <Info size={14} className="mt-0.5 shrink-0 text-gray-400" />
            <span>
              {draft.employee_name ? draft.employee_name.split(', ').reverse().join(' ') : 'This employee'} {nonTaxable}. The taxable compensation section (items 39–52) does not
              apply, so it is greyed out.
            </span>
          </p>
        )}

        {draft.rejection_reason && (
          <p className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-xs text-red-700">
            <span className="font-medium">Returned by reviewer:</span> {draft.rejection_reason}
          </p>
        )}

        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filter boxes by source">
          {[['all', 'All', rows.length], ...Object.entries(KINDS).filter(([k]) => counts[k]).map(([k, v]) => [k, v.label, counts[k]])].map(([key, label, n]) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              aria-pressed={filter === key}
              className={clsx('rounded-full border px-2.5 py-1 text-[11px] transition-colors',
                filter === key ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-gray-300')}
            >
              {label} <span className="tabular-nums text-gray-400">{n}</span>
            </button>
          ))}
        </div>

        {!schema && (
          <p className="text-[11px] text-gray-400">Item numbers and official labels appear once the server provides the form layout.</p>
        )}

        {comparedRows.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            <button onClick={() => setCompare(c => !c)} className={clsx('text-xs', compare ? 'btn-primary py-1.5' : 'btn-secondary py-1.5')}>
              {compare ? <X size={12} /> : <Scale size={12} />} {compare ? 'Close comparison' : 'Compare with filed form'}
            </button>
            {compare && (
              <>
                <button onClick={() => fileRef.current?.click()} className="btn-ghost text-xs py-1.5"><Upload size={12} /> Import CSV</button>
                <input ref={fileRef} type="file" accept=".csv,text/csv" className="hidden"
                  onChange={(e) => { if (e.target.files?.[0]) importCsv(e.target.files[0]); e.target.value = '' }} />
                <span className="text-[11px] text-gray-500">
                  <span className="text-green-700">{tally.match} match</span> · <span className="text-red-600">{tally.differ} differ</span> · {tally.blank} not entered
                </span>
              </>
            )}
          </div>
        )}
        {compare && importNote && <p className="text-[11px] text-gray-500">{importNote}</p>}
      </div>

      <ul className="divide-y divide-gray-100 max-h-[calc(100vh-420px)] min-h-[200px] overflow-y-auto">
        {visible.map(({ field, entry }) => (
          <FieldRow
            key={field.key}
            field={field}
            entry={entry}
            error={errors[field.key]}
            compare={compare}
            filed={filed[field.key]}
            onFiled={setFiled}
            notApplicable={!!nonTaxable && isTaxableSection(field)}
          />
        ))}
        {!visible.length && <li className="px-4 py-8 text-center text-xs text-gray-400">No boxes in this group.</li>}
      </ul>
    </div>
  )
}