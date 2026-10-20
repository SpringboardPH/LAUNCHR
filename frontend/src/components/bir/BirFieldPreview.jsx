import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, AlertTriangle, RefreshCw, Scale, Upload, X, Info } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, getBirConfig, getBirDraft, updateBirDraft, validateBirDraft } from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { EmptyState, StatusBadge } from '../ui/index.jsx'
import { describePeriod, formName } from './BirChatPanel'
import { formLabel, versionLabel } from './BirDraftList'
import FieldRow, { KINDS, kindOf, isNumeric, isCompared, TOLERANCE } from './BirDraftEditor'
import BirApprovalBar, { REVIEWED } from './BirApprovalBar'
import BirFormEmployees from './BirFormEmployees'

// Boxes can be changed until a form is filed: approved too, since it isn't filed yet and a
// change sends it back to draft (contract §5). Waiting for review or approved, only accounting
// may change them; anyone else gets 403, so they see the boxes read-only.
const EDITABLE = ['draft', 'pending', 'approved']
const canChange = (draft, role) => EDITABLE.includes(draft.status) && (!REVIEWED.includes(draft.status) || role === 'accounting')

const humanize = (key) => key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

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

const forwardRow = (el, key, refs) => { if (el) refs.current[key] = el; else delete refs.current[key] }

/**
 * The draft as the form will read: every box in printed order, with where each
 * figure came from. Week 4 adds a comparison against a form that was already
 * filed, for the go/no-go check; Week 6 makes the boxes editable and adds the
 * submit / approve / file steps.
 */
export default function BirFieldPreview({ draftId, askingField = null, onOpenDraft, onDeleted }) {
  const { user } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const [filter, setFilter] = useState('all')
  const rowRefs = useRef({})
  // Clicking "N errors" / "N warnings" walks through those boxes: the next one each click, briefly lit up.
  const [flash, setFlash] = useState(null)
  const jumpRef = useRef({ severity: null, index: -1 })
  const [compare, setCompare] = useState(false)
  const [filedByDraft, setFiledByDraft] = useState({})
  const [importNote, setImportNote] = useState(null)
  const fileRef = useRef(null)

  const { data: config } = useQuery({ queryKey: birKeys.config, queryFn: getBirConfig, staleTime: Infinity })
  const { data: draft, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: birKeys.detail(draftId),
    // validation_errors is only as fresh as the last validate, so the preview re-checks a draft it
    // opens (a no-op on the server when nothing changed). A finalized form is locked: shown as is.
    queryFn: async () => {
      const draft = await getBirDraft(draftId)
      if (draft.status === 'finalized') return draft
      try {
        return (await validateBirDraft(draftId)).data
      } catch {
        return draft
      }
    },
    enabled: !!draftId,
  })

  // One answer per PUT; the server checks it (422 keyed fields.<key>) and recalculates totals.
  // validate then refreshes validation_errors, which PUT does not touch.
  const save = useMutation({
    mutationFn: async ({ key, value }) => {
      const updated = (await updateBirDraft(draftId, { [key]: value })).data
      try {
        return (await validateBirDraft(draftId)).data
      } catch {
        return updated
      }
    },
    onMutate: () => setSaveError(null),
    onSuccess: (updated) => {
      qc.setQueryData(birKeys.detail(draftId), updated)
      qc.invalidateQueries({ queryKey: birKeys.lists })
      setEditing(null)
    },
    onError: (err, { key }) => {
      const body = err?.response?.data
      setSaveError({ key, text: body?.errors?.[`fields.${key}`]?.[0] || body?.message || 'Could not save this box.' })
      // 400: the form was approved or moved on elsewhere, so reload it (it is now read-only).
      if (err?.response?.status === 400) qc.invalidateQueries({ queryKey: birKeys.detail(draftId) })
    },
  })

  useEffect(() => { setEditing(null); setSaveError(null); setFlash(null); jumpRef.current = { severity: null, index: -1 } }, [draftId])
  useEffect(() => {
    if (!flash) return
    const t = setTimeout(() => setFlash(null), 2500)
    return () => clearTimeout(t)
  }, [flash])

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

  // Bring the box the chat is asking about into view, whatever filter is on.
  useEffect(() => {
    if (!askingField) return
    setFilter('all')
    const t = setTimeout(() => rowRefs.current[askingField]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100)
    return () => clearTimeout(t)
  }, [askingField, draft?.id])

  if (!draftId) {
    return <EmptyState icon={FileText} title="No draft yet" description="Ask the assistant for a form, or pick one below. Its boxes will fill in here." />
  }
  if (isLoading) return <SkeletonRows />
  if (isError || !draft) {
    const status = error?.response?.status
    return (<div className="p-6 text-center">
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

  // validation_errors is the last validate result; entries are read by key, never by position.
  const issues = (draft.validation_errors ?? []).reduce((acc, e) => ({ ...acc, [e.field]: [...(acc[e.field] ?? []), e] }), {})
  const goToIssue = (severity) => {
    const fields = [...new Set((draft.validation_errors ?? [])
      .filter(e => (severity === 'warning') === (e.severity === 'warning')).map(e => e.field))]
    if (!fields.length) return
    const last = jumpRef.current
    const index = last.severity === severity ? (last.index + 1) % fields.length : 0
    jumpRef.current = { severity, index }
    setFilter('all')
    setFlash({ field: fields[index], severity })
    setTimeout(() => rowRefs.current[fields[index]]?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50)
  }
  const errorTotal = (draft.validation_errors ?? []).filter(e => e.severity !== 'warning').length
  const warningTotal = (draft.validation_errors ?? []).length - errorTotal
  const nonTaxable = nonTaxableReason(draft)
  const editable = canChange(draft, user?.role)
  const startEdit = (key) => { setEditing(key); setSaveError(null) }
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
            <p className="text-xs text-gray-400">
              Form {formLabel(draft)}{formLabel(draft) !== `#${draft.id}` && ` (#${draft.id})`} · {versionLabel(draft)}{isFetching && ' · updating…'}
            </p>
            <p className="text-base font-semibold text-gray-900 leading-snug">{formName(draft.form_type)}</p>
            <p className="text-sm text-gray-600">
              {describePeriod(draft.form_type, draft.period)}{draft.employee_name && ` · ${draft.employee_name}`}
            </p>
          </div>
          <StatusBadge status={draft.status} />
        </div>
        {draft.form_type === '2316' && draft.version > 1 && draft.status !== 'finalized' && (
          <p className="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700">
            An earlier version of this certificate has already been filed. Check before filing this one; its PDF says so too.
          </p>
        )}

        <div>
          <div className="flex justify-between text-[11px] text-gray-500">
            <span>{filledCount} of {fillable.length} boxes filled</span>
            {(draft.validation_errors ?? []).length > 0 ? (
              <span>
                {errorTotal > 0 && (
                  <button type="button" onClick={() => goToIssue('error')} className="text-red-600 hover:underline"
                    title={errorTotal > 1 ? 'Show the next box with an error' : 'Show the box with the error'}>
                    {errorTotal} error{errorTotal === 1 ? '' : 's'} to fix before {draft.status === 'pending' ? 'approving' : 'submitting'}
                  </button>
                )}
                {errorTotal > 0 && warningTotal > 0 && ' · '}
                {warningTotal > 0 && (
                  <button type="button" onClick={() => goToIssue('warning')} className="text-amber-700 hover:underline"
                    title={warningTotal > 1 ? 'Show the next box with a warning' : 'Show the box with the warning'}>
                    {warningTotal} warning{warningTotal === 1 ? '' : 's'} to review
                  </button>
                )}
              </span>
            ) : requiredMissing > 0 && <span className="text-amber-700">{requiredMissing} required still empty</span>}
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

      <BirApprovalBar key={draft.id} draft={draft} onOpenDraft={onOpenDraft} onDeleted={onDeleted}
        edits={rows.filter(({ entry }) => entry.edited)} onShowEdits={() => setFilter('edited')} />
      {draft.form_type === '1601-C' && <BirFormEmployees key={`emp-${draft.id}`} draftId={draft.id} />}

      <ul className="divide-y divide-gray-100 max-h-[calc(100vh-220px)] min-h-[520px] overflow-y-auto">
        {visible.map(({ field, entry }) => (
          <FieldRow
            key={field.key}
            field={field}
            entry={entry}
            issues={issues[field.key]}
            asked={field.key === askingField}
            flash={flash?.field === field.key ? flash.severity : null}
            rowRef={(el) => forwardRow(el, field.key, rowRefs)}
            compare={compare}
            filed={filed[field.key]}
            onFiled={setFiled}
            notApplicable={!!nonTaxable && isTaxableSection(field)}
            editing={editing === field.key}
            onEdit={() => startEdit(field.key)}
            onCancel={() => { setEditing(null); setSaveError(null) }}
            onSave={editable ? (key, value) => save.mutate({ key, value }) : undefined}
            saving={save.isPending && save.variables?.key === field.key}
            saveError={saveError?.key === field.key ? saveError.text : null}
          />
        ))}
        {!visible.length && <li className="px-4 py-8 text-center text-xs text-gray-400">No boxes in this group.</li>}
      </ul>
    </div>
  )
}