// BIR Form Assistant — mock backend for frontend work before the API is complete.
//
// Mirrors docs/bir-api-contract.md: the draft object (§2), field entries and the
// rules for editing and clearing them (§3), validation entries with a severity
// (§3), the status flow (§5), and validate / submit / approve refusing a form with
// errors (§6). The chat lives in birMockChat.js. Only queries.js imports this, and
// only in dev builds. Week 7 removes it from app code.
//
// Drafts carry every schema field, like real ones: payroll and settings fields
// filled, the rest pending. Money is a plain decimal string ("55010.00") — the
// UI formats it for display. Unlike the real PUT, totals are not recalculated.

import { birMockSchemas } from './birSchemaFixtures'
import { mockChat } from './birMockChat'

const STATUS_FLOW = {
  draft: ['pending'],
  pending: ['approved', 'draft'],
  approved: ['finalized'],
  finalized: [],
}

const JANE = { id: 5, name: 'Jane Dela Cruz' }
const MARK = { id: 2, name: 'Mark Santos' }

const EMPLOYEES = [
  { id: 11, employee_id: 'EMP011', first_name: 'Ana', last_name: 'Reyes', tin: '111-222-333-00000' , group: 'Lazada' },
  // A phone recorded without enough digits: his 2316 reports it as invalid_format.
  { id: 12, employee_id: 'EMP012', first_name: 'Paolo', last_name: 'Garcia', tin: '444-555-666-00000', phone: '0917-55' , group: 'Shopee' },
  { id: 13, employee_id: 'EMP013', first_name: 'Maria', last_name: 'Santos', tin: '222-333-444-00000' , group: 'Lazada' },
  { id: 14, employee_id: 'EMP014', first_name: 'Maria', last_name: 'Santos', tin: '555-666-777-00000' , group: 'Shopee' },
  // Paid at minimum wage: nothing taxable, so the 2316's taxable section does not apply.
  { id: 15, employee_id: 'EMP015', first_name: 'Jose', last_name: 'Dizon', tin: '666-777-888-00000', annual: 168000 , group: 'Lazada' },
]

const SETTINGS = {
  '1601-C': {
    atc_code: 'WW010', company_tin: '000-123-456-00000', rdo_code: '047',
    company_name: 'SPRINGBOARD PH INC.', company_address: '12F Tower One, Ayala Ave, Makati City',
    company_zip: '1226', agent_category: 'private',
  },
  '2316': {
    present_employer_tin: '000-123-456-00000', present_employer_name: 'SPRINGBOARD PH INC.',
    present_employer_address: '12F Tower One, Ayala Ave, Makati City', present_employer_zip: '1226',
    employer_type: 'main',
  },
}

// The mock has payroll for 2026 only (and 2025 for the 2316 fixtures); any other 1601-C
// period is empty, so its figures are zero and validate warns about it (empty_period).
const MOCK_PAYROLL_YEAR = '2026'

const money = (n) => n.toFixed(2)

// Payroll figures that add up the way the real aggregation's do. `seed` varies them per period.
const payroll1601c = (seed = 0, empty = false) => {
  const gross = empty ? 0 : 842300 + seed * 9475
  const thirteenth = 0, deMinimis = empty ? 0 : 18000, contributions = empty ? 0 : 78450 + seed * 520
  const nontax = thirteenth + deMinimis + contributions
  const taxable = gross - nontax
  const withheld = Math.round(taxable * 0.0821 * 100) / 100
  return {
    has_taxes_withheld: withheld > 0, total_compensation: money(gross), mwe_statutory_wage: '0.00',
    mwe_premium_pay: '0.00', thirteenth_month_and_benefits: money(thirteenth),
    de_minimis_benefits: money(deMinimis), statutory_contributions_ee: money(contributions),
    total_nontaxable_compensation: money(nontax), total_taxable_compensation: money(taxable),
    exempt_250k_compensation: '0.00', net_taxable_compensation: money(taxable),
    total_taxes_withheld: money(withheld), taxes_withheld_for_remittance: money(withheld),
    total_remittances_made: '0.00', tax_still_due: money(withheld), total_penalties: '0.00',
    total_amount_due: money(withheld),
  }
}

const payroll2316 = (employee, annual) => {
  const thirteenth = annual / 12, contributions = annual * 0.072
  // At or under P250,000 nothing is taxable: basic pay moves to item 29, as the real aggregation does.
  const exempt = annual <= 250000
  const nontax = exempt ? annual : thirteenth + contributions + 12000
  const taxable = annual - nontax
  const tax = Math.max(0, Math.round((taxable - 250000) * 0.15 * 100) / 100)
  return {
    ...(exempt && { nontax_mwe_basic: money(annual - thirteenth - contributions - 12000) }),
    employee_tin: employee.tin, employee_last_name: employee.last_name,
    ...(employee.phone && { employee_contact_number: employee.phone }),
    employee_first_name: employee.first_name, basic_salary_annual: money(annual - thirteenth),
    gross_compensation_present: money(annual), less_nontaxable_present: money(nontax),
    taxable_income_present: money(taxable), gross_taxable_income: money(taxable), tax_due: money(tax),
    taxes_withheld_present: money(tax), taxes_withheld_payroll: money(tax), year_end_refund: '0.00',
    total_taxes_withheld_adjusted: money(tax),
    total_taxes_withheld_final: money(tax), nontax_thirteenth_month: money(thirteenth),
    nontax_de_minimis: '12000.00', nontax_statutory_contributions: money(contributions),
    nontax_total: money(nontax), tax_basic_salary: money(taxable), tax_regular_total: money(taxable),
  }
}

const schemaOf = (formType) => birMockSchemas[formType]
const fieldOf = (formType, key) => schemaOf(formType).find(f => f.key === key)
const blank = { edited: false, system_value: null, edited_by: null, edited_at: null }
const pending = () => ({ value: null, origin: 'pending', ...blank })

// Every schema field: filled from `values` with the schema's source as origin, else pending.
// A fixed field always has its schema value; a fixed blank is "" (null only when pending).
const buildFields = (formType, values) => Object.fromEntries(
  schemaOf(formType).map(({ key, source, ...field }) => {
    const v = 'fixed' in field ? (field.fixed ?? '') : values[key]
    const filled = 'fixed' in field || (v !== undefined && v !== null && v !== '')
    return [key, filled ? { value: v, origin: source, ...blank } : pending()]
  }),
)

// An override of a calculated figure, as the real PUT records it.
const override = (fields, key, value, by, at) => ({
  ...fields,
  [key]: { value, origin: 'user', edited: true, system_value: fields[key].value, edited_by: by, edited_at: at },
})

const base = {
  version: 1, parent_id: null, prepared_by: JANE, approved_by: null,
  rejection_reason: null, validation_errors: [],
}

const draft1601c = (id, period, status, seed, extra = {}) => {
  const empty = !period.startsWith(MOCK_PAYROLL_YEAR)
  return {
    ...base, id, status, form_type: '1601-C', period, employee_id: null, employee_name: null, empty_period: empty,
    fields: buildFields('1601-C', {
      return_period: `${period.slice(5, 7)}/${period.slice(0, 4)}`, is_amended: (extra.version ?? 1) > 1,
      ...SETTINGS['1601-C'], ...payroll1601c(seed, empty),
    }),
    ...extra,
  }
}

const draft2316 = (id, employee, annual, status, extra = {}) => ({
  ...base, id, status, form_type: '2316', period: '2025', empty_period: false,
  employee_id: employee.id, employee_name: `${employee.last_name}, ${employee.first_name}`,
  fields: buildFields('2316', { ...SETTINGS['2316'], ...payroll2316(employee, annual) }),
  ...extra,
})

const seed = () => {
  const d2 = draft1601c(2, '2026-06', 'pending', 1)
  d2.fields = override(d2.fields, 'total_taxes_withheld', '61204.15', JANE, '2026-07-08T10:22:00+08:00')

  const d5 = draft1601c(5, '2026-05', 'draft', 2, {
    rejection_reason: 'Item 25 total tax withheld does not match the payroll register — please recheck May.',
  })
  d5.fields = override(d5.fields, 'total_taxes_withheld', '55010.00', JANE, '2026-06-08T09:47:00+08:00')

  const d6 = draft2316(6, EMPLOYEES[1], 612000, 'draft', { version: 2, parent_id: 4 })
  d6.fields = override(d6.fields, 'taxes_withheld_present', '39880.00', JANE, '2026-01-26T14:05:00+08:00')

  const all = [
    draft1601c(1, '2026-08', 'draft', 3), d2,
    draft2316(3, EMPLOYEES[0], 480000, 'approved', { approved_by: MARK }),
    draft2316(4, EMPLOYEES[1], 612000, 'finalized', { approved_by: MARK }),
    d5, d6,
  ]
  all.forEach(d => { d.validation_errors = checks(d) })
  return all
}

// ─── Validation ───────────────────────────────────────────────
// The same entry shape as BirDraftValidator: { field, code, severity, message }, in printed
// order. Conditional (required_when) fields and total checks are left to the real backend.

const quoted = (f) => `"${f.label}"`
const peso = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const WITHHELD = { '1601-C': 'total_taxes_withheld', 2316: 'taxes_withheld_present' }

// Fields a person still has to supply: required, empty, and not signed on paper.
const missingFor = (draft) => schemaOf(draft.form_type)
  .filter(f => f.required && f.source !== 'manual' && draft.fields[f.key]?.origin === 'pending')
  .map(f => ({ field: f.key, reason: ['payroll', 'settings'].includes(f.source) ? 'record_gap' : 'required' }))

// Dev A's FieldFormat: what each format accepts, and how a message describes it.
const FORMATS = {
  tin: ['a 14-digit TIN like 123-456-789-00000 (9 digits and a 5-digit branch code)', (v) => /^\d{3}-\d{3}-\d{3}-\d{5}$/.test(v)],
  rdo: ['a 3-character RDO code like 043 or 21A', (v) => /^(\d{3}|\d{2}[A-Z])$/.test(v)],
  zip: ['a 4-digit ZIP code like 1004', (v) => /^\d{4}$/.test(v)],
  phone: ['a phone number like 09171234567 or (02) 8123-4567', (v) => /^\+?\d{7,13}$/.test(v.replace(/[\s().-]/g, ''))],
  email: ['an email address like name@company.com', (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)],
  mmdd: ['a date as MM/DD, like 07/01', (v) => {
    const m = v.match(/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/)
    return !!m && Number(m[2]) <= new Date(2024, Number(m[1]), 0).getDate() // a leap year allows 02/29
  }],
}
// Blank text is not format-checked; whether it may be blank is the field's `required`.
const formatProblem = (f, value) => {
  if (!f.format || value === '' || value == null) return null
  const [describe, matches] = FORMATS[f.format]
  return typeof value === 'string' && matches(value) ? null : `${quoted(f)} must be ${describe}.`
}

const checks = (draft) => {
  const byKey = Object.fromEntries(schemaOf(draft.form_type).map(f => [f.key, f]))
  const entries = missingFor(draft).map(({ field, reason }) => {
    const f = byKey[field]
    return {
      field, code: reason, severity: 'error',
      message: reason === 'record_gap'
        ? `${quoted(f)} should come from ${f.source === 'settings' ? 'the company settings' : 'the employee or payroll records'}, but nothing was found there. Fix the record, or enter it here.`
        : `${quoted(f)} is required.`,
    }
  })
  // Stored text PUT never checked (older drafts, figures from payroll or settings).
  schemaOf(draft.form_type).forEach(f => {
    const message = draft.fields[f.key] && formatProblem(f, draft.fields[f.key].value)
    if (message) entries.push({ field: f.key, code: 'invalid_format', severity: 'error', message })
  })
  const withheld = draft.fields[WITHHELD[draft.form_type]]
  if (withheld?.edited && withheld.system_value != null && Number(withheld.value) !== Number(withheld.system_value)) {
    entries.push({
      field: WITHHELD[draft.form_type], code: 'payroll_mismatch', severity: 'warning',
      message: `${quoted(byKey[WITHHELD[draft.form_type]])} is ${peso(withheld.value)}, but the payroll this draft was built from withheld ${peso(withheld.system_value)}.`,
    })
  }
  if (draft.empty_period) {
    const anchor = draft.form_type === '1601-C' ? 'return_period' : 'tax_year'
    entries.push({
      field: anchor, code: 'empty_period', severity: 'warning',
      message: `There is no finalized or paid payroll for ${draft.period}, so every payroll figure on this form is zero.`,
    })
  }
  const order = Object.keys(byKey)
  return entries.sort((a, b) => order.indexOf(a.field) - order.indexOf(b.field))
}
const errorCount = (draft) => checks(draft).filter(e => e.severity === 'error').length

// ─── Answer checks (PUT) ──────────────────────────────────────
// One bad answer refuses the whole request with 422, keyed fields.<key>, as the real PUT does.

const TRUE = ['true', '1', 'yes'], FALSE = ['false', '0', 'no']
const answerProblem = (f, value) => {
  if (!f) return 'is not a field on this form.'
  if (f.type === 'manual') return `${quoted(f)} is signed on the printed form, not entered here.`
  const text = String(value)
  switch (f.type) {
    case 'decimal': return typeof value === 'number' || /^-?\d+(\.\d+)?$/.test(text) ? null
      : `${quoted(f)} must be an amount like 1234.50, without commas or a currency sign.`
    case 'boolean': return typeof value === 'boolean' || [...TRUE, ...FALSE].includes(text.toLowerCase()) ? null
      : `${quoted(f)} must be yes or no.`
    case 'enum': return (f.options ?? []).includes(text) ? null : `${quoted(f)} must be one of: ${(f.options ?? []).join(', ')}.`
    case 'integer': return /^\d+$/.test(text) ? null : `${quoted(f)} must be a whole number.`
    case 'month': return /^(0[1-9]|1[0-2])\/\d{4}$/.test(text) ? null : `${quoted(f)} must be a month like 09/2026.`
    case 'date': return /^\d{4}-\d{2}-\d{2}$/.test(text) && !Number.isNaN(Date.parse(text)) ? null
      : `${quoted(f)} must be a real date like 2026-09-30.`
    case 'text': return text.length <= 1000 ? null : `${quoted(f)} must be at most 1000 characters.`
    default: return text.length > 255 ? `${quoted(f)} must be at most 255 characters.` : formatProblem(f, value)
  }
}
const stored = (f, value) => (f.type === 'boolean' && typeof value !== 'boolean'
  ? TRUE.includes(String(value).toLowerCase()) : f.type === 'decimal' ? String(value) : value)

// Session-lived store: mutations stick until the page reloads.
let drafts = seed()
let nextId = 100

const clone = (x) => structuredClone(x)
const delay = (ms = 450) => new Promise(res => setTimeout(res, ms))

// Rejects the way axios does, so components handle mock and real errors alike.
const fail = (status, message, extra = {}) => {
  const err = new Error(message)
  err.response = { status, data: { success: false, message, ...extra } }
  return Promise.reject(err)
}
// Keys the server works out for every draft (contract §2): label, root_id and the 1601-C amendment count.
const rootOf = (d) => (d.parent_id ? rootOf(find(d.parent_id) ?? { id: d.parent_id }) : d.id)
const decorate = (d) => {
  const root = rootOf(d)
  const top = Math.max(...drafts.filter(x => x.form_type === d.form_type && x.period === d.period && x.employee_id === d.employee_id).map(x => x.version))
  return {
    ...d, root_id: root, label: `#${root}${d.version > 1 ? `v${d.version}` : ''}`,
    amendments: d.form_type === '1601-C' ? { made: top - 1, limit: AMENDMENT_LIMIT } : null,
  }
}
const AMENDMENT_LIMIT = 5
const ok = (data, message) => ({ success: true, data: clone(data?.fields ? decorate(data) : data), message })
const find = (id) => drafts.find(d => d.id === Number(id))

// The logged-in user, as the real API takes them from the session (set by setBirMockUser).
// Seeded drafts stay Jane's, so the signed-in user can review and approve them.
let caller = null
const actAs = (user) => { caller = user?.id != null ? { id: user.id, name: user.name, role: user.role } : null }
// Approve, return, mark as filed and changing a pending or approved form are accounting-only (§5).
const isAccounting = () => (caller?.role ?? 'accounting') === 'accounting'
const ACCOUNTING_ONLY = 'Unauthorized. Only accounting users can do this.'
const preparer = () => caller ?? JANE
const approver = () => caller ?? MARK

const getConfig = async () => {
  await delay(150)
  return {
    enabled: switchState.enabled, switch_password_set: !!switchState.password,
    form_types: ['1601-C', '2316'], status_flow: clone(STATUS_FLOW),
    schemas: clone(birMockSchemas),
  }
}

const getDrafts = async ({ status, form_type, per_page = 15, page = 1 } = {}) => {
  await delay()
  const perPage = Math.min(Math.max(Number(per_page) || 15, 1), 100)
  const matching = drafts
    .filter(d => (!status || d.status === status) && (!form_type || d.form_type === form_type))
    .sort((a, b) => b.id - a.id)
  const start = (page - 1) * perPage
  const data = matching.slice(start, start + perPage)
  return {
    data: clone(data.map(decorate)),
    pagination: {
      total: matching.length, count: data.length, per_page: perPage,
      current_page: Number(page), last_page: Math.max(1, Math.ceil(matching.length / perPage)),
    },
  }
}
const getDraft = async (id) => {
  await delay()
  const d = find(id)
  return d ? clone(decorate(d)) : fail(404, 'Draft not found')
}

const createDraft = async ({ form_type, period, employee_id = null }) => {
  await delay(900)
  if (!['1601-C', '2316'].includes(form_type) || !period) {
    return fail(422, 'The form type and period are required.')
  }
  let draft
  if (form_type === '1601-C') {
    draft = draft1601c(nextId++, period, 'draft', Number(period.slice(5, 7)) % 5)
  } else {
    const employee = EMPLOYEES.find(e => e.id === Number(employee_id))
    if (!employee) return fail(422, 'A 2316 is per employee — an employee is required.')
    draft = { ...draft2316(nextId++, employee, employee.annual ?? 540000, 'draft'), period }
  }
  draft.prepared_by = preparer()
  drafts.push(draft)
  return ok(draft, 'Draft created (mock)')
}
const updateDraft = async (id, fields = {}) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status === 'finalized') {
    return fail(400, 'Only draft, pending and approved forms can be edited; this form is in finalized status')
  }
  if (d.status !== 'draft' && !isAccounting()) return fail(403, 'Only accounting can edit a form that is under review or approved')
  const errors = {}
  Object.entries(fields).forEach(([key, value]) => {
    const f = fieldOf(d.form_type, key)
    const problem = f && ('fixed' in f || f.system_filled) ? `${quoted(f)} is filled in automatically and can't be changed.`
      : value == null ? null : answerProblem(f, value)
    if (problem) errors[`fields.${key}`] = [problem.startsWith('is not') ? `"${key}" ${problem}` : problem]
  })
  if (Object.keys(errors).length) return fail(422, Object.values(errors)[0][0], { errors })

  const now = new Date().toISOString()
  Object.entries(fields).forEach(([key, value]) => {
    const f = fieldOf(d.form_type, key)
    const prev = d.fields[key] ?? pending()
    const calculated = ['payroll', 'settings'].includes(f.source)
    if (value == null) {
      // Clearing undoes an override, never blanks a calculated figure (contract §3).
      if (prev.edited) d.fields[key] = prev.system_value != null ? { value: prev.system_value, origin: f.source, ...blank } : pending()
      else if (!(calculated && prev.origin === f.source)) d.fields[key] = pending()
    } else if (calculated) {
      const original = prev.edited ? prev.system_value : (prev.origin === 'pending' ? null : prev.value)
      d.fields[key] = { value: stored(f, value), origin: 'user', edited: true, system_value: original, edited_by: preparer(), edited_at: now }
    } else {
      // An answer only a person gives replaces nothing: no history.
      d.fields[key] = { value: stored(f, value), origin: 'user', ...blank }
    }
  })
  // A change to an approved form sends it back to draft, to be submitted and approved again.
  if (d.status === 'approved') { d.status = 'draft'; d.approved_by = null }
  return ok(d, 'Draft updated (mock)')
}
const validate = async (id) => {
  await delay(300)
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status === 'finalized') return fail(400, "Finalized forms are locked and can't be revalidated")
  d.validation_errors = checks(d)
  const errors = d.validation_errors.filter(e => e.severity === 'error').length
  const warnings = d.validation_errors.length - errors
  return ok(d, `Draft validated: ${errors} error${errors === 1 ? '' : 's'}, ${warnings} warning${warnings === 1 ? '' : 's'}`)
}

const transition = async (id, to, reason = null) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (!(STATUS_FLOW[d.status] ?? []).includes(to)) {
    return fail(400, `Cannot move a form in ${d.status} status to ${to}`)
  }
  if (['approved', 'finalized'].includes(to) || (d.status === 'pending' && to === 'draft')) {
    if (!isAccounting()) return fail(403, ACCOUNTING_ONLY)
  }
  if (d.status === 'pending' && to === 'draft' && !reason?.trim()) {
    return fail(422, 'A reason is required to return a form.')
  }
  // submit and approve check the form first: any error refuses the move with 422.
  if (to === 'pending' || to === 'approved') {
    d.validation_errors = checks(d)
    const errors = d.validation_errors.filter(e => e.severity === 'error').length
    if (errors) {
      const verb = to === 'pending' ? 'submitting' : 'approving'
      return fail(422, `Draft has ${errors} error${errors === 1 ? '' : 's'}; fix them before ${verb}`, { data: clone(d) })
    }
  }
  d.status = to
  d.rejection_reason = to === 'draft' ? reason : null
  if (to === 'approved') d.approved_by = approver()
  return ok(d, `Draft moved to ${to} (mock)`)
}

const revise = async (id) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status !== 'finalized') {
    return fail(400, `Only finalized forms can be revised; this one is in ${d.status} status`)
  }
  const sameForm = drafts.filter(x => x.form_type === d.form_type && x.period === d.period && x.employee_id === d.employee_id)
  if (d.form_type === '1601-C' && Math.max(...sameForm.map(x => x.version)) > AMENDMENT_LIMIT) {
    return fail(400, `This 1601-C has already been amended ${AMENDMENT_LIMIT} times for ${d.period}, the most allowed for one month`)
  }
  const revision = {
    ...clone(d), id: nextId++, status: 'draft', version: Math.max(...sameForm.map(x => x.version)) + 1, parent_id: d.id,
    approved_by: null, rejection_reason: null, prepared_by: preparer(),
  }
  // Every version after the original is an amended return: item 2 is Yes.
  if (d.form_type === '1601-C') revision.fields.is_amended = { value: true, origin: 'payroll', ...blank }
  drafts.push(revision)
  return ok(revision, 'Revision created (mock)')
}

const exportDraft = async () => {
  await delay()
  return fail(501, 'PDF export is not available in mock mode. Set VITE_BIR_MOCK=false to download the official PDF.')
}

// A 1601-C's employee rows (contract §6): items 14, 19, 22 and 25 shared out, so the totals add up.
const draftEmployees = async (id) => {
  await delay(300)
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.form_type !== '1601-C') return fail(422, 'The employee list is only for a 1601-C')
  const keys = { compensation: 'total_compensation', ee: 'statutory_contributions_ee', taxable: 'total_taxable_compensation', tax_withheld: 'total_taxes_withheld' }
  const share = (key, i) => money(Number(d.fields[keys[key]]?.system_value ?? d.fields[keys[key]]?.value ?? 0) / EMPLOYEES.length)
  const employees = EMPLOYEES.map((e, i) => ({
    employee_id: e.id, name: `${e.last_name}, ${e.first_name}`.toUpperCase(), tin: e.tin, category: e.annual ? 'mwe' : 'taxable',
    payroll_count: 2, compensation: share('compensation', i), mwe_basic: '0.00', mwe_premium: '0.00', thirteenth: '0.00',
    ee: share('ee', i), taxable: share('taxable', i), exempt_250k: '0.00', tax_withheld: share('tax_withheld', i),
  }))
  const totals = Object.fromEntries(['compensation', 'mwe_basic', 'mwe_premium', 'thirteenth', 'ee', 'taxable', 'exempt_250k', 'tax_withheld']
    .map(k => [k, money(employees.reduce((sum, r) => sum + Number(r[k]), 0))]))
  const differences = Object.entries(keys).map(([k, key]) => ({ k, key, form: d.fields[key]?.value }))
    .filter(({ k, form }) => Math.abs(Number(form) - Number(totals[k])) >= 0.005)
    .map(({ k, key, form }) => ({ item: fieldOf('1601-C', key).item, key, employees_total: totals[k], form_value: form, difference: money(Number(form) - Number(totals[k])) }))
  return ok({ draft_id: d.id, form_type: '1601-C', period: d.period, version: d.version, employees, totals, differences }, 'Employee list retrieved')
}

// The admin switch and its company password (contract §6). The mock starts on, with the password "password".
let switchState = { enabled: true, password: 'password' }
const setSwitch = async (enabled, password) => {
  await delay(300)
  if (!switchState.password) return fail(422, 'No company password is set up')
  if (password !== switchState.password) return fail(422, 'The company password is incorrect')
  switchState.enabled = !!enabled
  return ok({ enabled: switchState.enabled }, `The BIR Form Assistant is now ${enabled ? 'on' : 'off'}`)
}
const setSwitchPassword = async ({ password, password_confirmation, current_password }) => {
  await delay(300)
  if (!password || password.length < 8 || password !== password_confirmation) {
    return fail(422, 'The password must be at least 8 characters and match its confirmation.')
  }
  if (switchState.password && current_password !== switchState.password) return fail(422, 'The current company password is incorrect')
  switchState.password = password
  return ok({ switch_password_set: true }, 'Company password saved')
}

const getEmployees = async () => {
  await delay(200)
  return { data: clone(EMPLOYEES), pagination: { total: EMPLOYEES.length } }
}

const chat = mockChat({
  find, schemas: birMockSchemas, missingFor, errorCount, employees: EMPLOYEES, delay, fail,
})

export const birMockApi = {
  getConfig, getDrafts, getDraft, createDraft, updateDraft, validate, transition, revise, exportDraft, chat, getEmployees, actAs,
  draftEmployees, setSwitch, setSwitchPassword,
}