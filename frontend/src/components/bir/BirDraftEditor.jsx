import { useEffect, useRef, useState } from 'react'
import { AlertTriangle, HelpCircle, MessageCircle, Pencil, RotateCcw, Check, X } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import clsx from 'clsx'

// How each kind of value looks. `edited` is a user value that replaced a calculated one.
export const KINDS = {
  payroll:  { label: 'From payroll',      tag: 'bg-blue-50 text-blue-700',     bar: 'bg-blue-400' },
  settings: { label: 'Company settings',  tag: 'bg-purple-50 text-purple-700', bar: 'bg-purple-400' },
  user:     { label: 'Typed by user',     tag: 'bg-green-50 text-green-700',   bar: 'bg-green-400' },
  edited:   { label: 'Manually changed',  tag: 'bg-amber-100 text-amber-800',  bar: 'bg-amber-500', row: 'bg-amber-50/60' },
  pending:  { label: 'Awaiting input',    tag: 'bg-gray-100 text-gray-500',    bar: 'bg-gray-200' },
  manual:   { label: 'Signed on paper',   tag: 'bg-gray-100 text-gray-500',    bar: 'bg-gray-200' },
}
export const kindOf = (entry) => (entry.edited ? 'edited' : (KINDS[entry.origin] ? entry.origin : 'pending'))

export const peso = (n) => `₱${Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
export const isNumeric = (v) => v !== null && v !== '' && !Number.isNaN(Number(v))

const isYes = (v) => v === true || v === 'true' || v === '1'

// A box no one can change (PUT refuses it with 422): a schema 'fixed' value, checked by key
// since it can be false or null (1601-C item 13 No, item 13A blank), or 'system_filled'
// (item 2, No on the original and Yes on an amended return). Null for any other box.
export const lockedNote = (field) => {
  if ('fixed' in field) {
    return `Always ${field.fixed === null ? 'blank' : formatValue(field.fixed, field.type)} on this form.`
  }
  return field.system_filled ? 'Filled in automatically: No on the original, Yes on an amended return.' : null
}

export const formatValue = (value, type) => {
  if (value === null || value === undefined || value === '') return null
  if (type === 'boolean' || typeof value === 'boolean') return isYes(value) ? 'Yes' : 'No'
  if (type === 'decimal' && isNumeric(value)) return peso(value)
  // Stored as YYYY-MM-DD; shown MM/DD/YYYY, the way BIR forms write dates.
  const ymd = type === 'date' && /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value))
  if (ymd) return `${ymd[2]}/${ymd[3]}/${ymd[1]}`
  return String(value)
}

export const formatWhen = (iso) => {
  try { return format(parseISO(iso), 'MM/dd/yyyy h:mm a') } catch { return iso }
}

// Fields compared against a filed form — the same rule as `php artisan bir:compare`.
export const isCompared = (field) => field.source === 'payroll' && field.type === 'decimal' && field.item != null

export const TOLERANCE = 0.005

const PLACEHOLDER = { decimal: '0.00', integer: '0', month: 'MM/YYYY' }

// The editor's text for a stored value, and the value PUT receives for the editor's text.
// Empty means "clear": the server restores a calculated figure or empties a typed one (§3).
const toInput = (value, type) => {
  if (value === null || value === undefined) return ''
  if (type === 'boolean') return isYes(value) ? 'true' : 'false'
  return String(value)
}
const fromInput = (text, type) => {
  const t = text.trim()
  if (t === '') return null
  if (type === 'boolean') return t === 'true'
  if (type === 'decimal') return t.replace(/[₱,\s]/g, '')
  return t
}

function ValueInput({ field, value, onChange, onKeyDown, inputRef }) {
  const common = { ref: inputRef, value, onKeyDown, onChange: (e) => onChange(e.target.value), className: 'input py-1 text-sm' }
  if (field.type === 'boolean' || field.type === 'enum') {
    const options = field.type === 'boolean' ? [['true', 'Yes'], ['false', 'No']] : (field.options ?? []).map(o => [o, o])
    return (
      <select {...common}>
        <option value="">— Not filled —</option>
        {options.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
      </select>
    )
  }
  if (field.type === 'text') return <textarea {...common} rows={2} maxLength={1000} />
  return (
    <input
      {...common}
      type={field.type === 'date' ? 'date' : 'text'}
      inputMode={field.type === 'decimal' ? 'decimal' : field.type === 'integer' ? 'numeric' : undefined}
      placeholder={PLACEHOLDER[field.type]}
      maxLength={field.type === 'string' ? 255 : undefined}
    />
  )
}

/**
 * The editable draft view, one box at a time. When `onSave` is given the box can be edited
 * in place; the server checks the answer (422 per field) and records who changed a calculated
 * figure. Validation problems are shown on the box they belong to.
 */
export default function FieldRow({
  field, entry, issues = [], asked, rowRef, compare, filed, onFiled, notApplicable,
  editing, onEdit, onCancel, onSave, saving, saveError,
}) {
  const [help, setHelp] = useState(false)
  const [text, setText] = useState('')
  const inputRef = useRef(null)
  const hasError = issues.some(i => i.severity !== 'warning')
  const kind = kindOf(entry)
  const style = KINDS[kind]
  const shown = formatValue(entry.value, field.type)
  const compared = compare && isCompared(field)
  const diff = compared && isNumeric(filed) && isNumeric(entry.value) ? Number(entry.value) - Number(filed) : null
  const matches = diff !== null && Math.abs(diff) < TOLERANCE
  const locked = lockedNote(field)
  const editable = !!onSave && field.type !== 'manual' && !locked
  const restorable = editable && entry.edited && entry.system_value != null

  useEffect(() => {
    if (!editing) return
    setText(toInput(entry.value, field.type))
    setTimeout(() => inputRef.current?.focus(), 0)
  }, [editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const save = () => onSave(field.key, fromInput(text, field.type))
  const onKeyDown = (e) => {
    if (e.key === 'Escape') onCancel()
    if (e.key === 'Enter' && field.type !== 'text') { e.preventDefault(); save() }
  }

  return (
    <li
      ref={rowRef}
      className={clsx('relative pl-4 pr-3 py-2 scroll-mt-2',
        notApplicable && !editing ? 'bg-gray-50 opacity-50' : style.row,
        editing ? 'ring-2 ring-inset ring-brand-300 bg-white' : asked ? 'ring-2 ring-inset ring-brand-400 bg-brand-50/60' : hasError && 'ring-1 ring-inset ring-red-200')}
      title={notApplicable ? 'Does not apply to a non-taxable employee' : undefined}
    >
      <span aria-hidden="true" className={clsx('absolute inset-y-0 left-0 w-1', style.bar)} />
      <div className="flex items-start gap-3">
        <span className="w-8 shrink-0 pt-0.5 text-[11px] font-medium text-gray-400 tabular-nums">{field.item ?? ''}</span>
        <div className="min-w-0 flex-1">
          <p className="text-xs text-gray-500 leading-snug">
            {field.label}{field.required && kind === 'pending' && <span className="text-red-500"> *</span>}
            {field.guidance && (
              <button
                onClick={() => setHelp(h => !h)}
                className="ml-1 align-middle text-gray-400 hover:text-brand-600"
                title="What does this mean?"
                aria-label={`What does ${field.label} mean?`}
                aria-expanded={help}
              >
                <HelpCircle size={12} />
              </button>
            )}
          </p>
          {help && field.guidance && (
            <p className="mt-1 mb-1 rounded-md bg-white/80 border border-gray-200 px-2 py-1.5 text-[11px] leading-relaxed text-gray-600">
              {field.guidance}
            </p>
          )}
          {editing ? (
            <div className="mt-1 space-y-1.5">
              <ValueInput field={field} value={text} onChange={setText} onKeyDown={onKeyDown} inputRef={inputRef} />
              {['payroll', 'settings'].includes(field.source) && (
                <p className="text-[11px] text-gray-500">
                  {entry.edited ? 'Leave it empty to go back to the calculated figure.' : 'This replaces the calculated figure. The change is recorded with your name.'}
                </p>
              )}
              {saveError && <p className="text-[11px] text-red-600">{saveError}</p>}
              <div className="flex gap-2">
                <button onClick={save} disabled={saving} className="btn-primary py-1 px-2.5 text-xs">
                  <Check size={12} /> {saving ? 'Saving…' : 'Save'}
                </button>
                <button onClick={onCancel} disabled={saving} className="btn-ghost py-1 px-2.5 text-xs"><X size={12} /> Cancel</button>
              </div>
            </div>
          ) : shown !== null ? (
            <p className={clsx('text-sm font-medium text-gray-900 break-words', field.type === 'decimal' && 'tabular-nums')}>{shown}</p>
          ) : (
            <p className="text-sm italic text-gray-400">{kind === 'manual' ? 'Signed by hand after printing' : locked ? 'Blank' : 'Not filled yet'}</p>
          )}
          {locked && !editing && <p className="mt-0.5 text-[11px] text-gray-500">{locked}</p>}
          {/* An override carries the figure it replaced and who changed it; an older edit may
              have neither, so show only what is actually known. */}
          {entry.edited && !editing && (
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
          {!editing && issues.map((issue, i) => (
            <p key={i} className={clsx('mt-0.5 flex gap-1 text-[11px]', issue.severity === 'warning' ? 'text-amber-700' : 'text-red-600')}>
              {issue.severity === 'warning' && <AlertTriangle size={11} className="mt-0.5 shrink-0" />}
              <span>{issue.message}</span>
            </p>
          ))}
          {!editing && saveError && <p className="mt-0.5 text-[11px] text-red-600">{saveError}</p>}
          {!editing && (editable || restorable) && (
            <div className="mt-1 flex gap-3">
              {editable && (
                <button onClick={onEdit} className="inline-flex items-center gap-1 text-[11px] font-medium text-brand-600 hover:text-brand-700">
                  <Pencil size={11} /> {shown === null ? 'Fill in' : 'Change'}
                </button>
              )}
              {restorable && (
                <button
                  onClick={() => onSave(field.key, null)}
                  disabled={saving}
                  className="inline-flex items-center gap-1 text-[11px] font-medium text-gray-500 hover:text-gray-700"
                >
                  <RotateCcw size={11} /> Restore calculated
                </button>
              )}
            </div>
          )}
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
        {asked && !editing && (
          <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-medium text-white">
            <MessageCircle size={10} /> Being asked
          </span>
        )}
        <span className={clsx('shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium', notApplicable ? 'bg-gray-200 text-gray-600' : style.tag)}>
          {notApplicable ? 'Not applicable' : style.label}
        </span>
      </div>
    </li>
  )
}
