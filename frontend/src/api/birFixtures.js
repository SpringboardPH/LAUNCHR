
// BIR Form Assistant — mock backend for frontend work before the API is complete.
//
// Mirrors docs/bir-api-contract.md: the draft object (§2), field entries (§3),
// the status flow (§5) and the /bir/chat reply shape, including conversation
// memory, reset and employee candidates. Only queries.js imports this, and only
// in dev builds. Week 7 removes it from app code.
//
// Drafts carry every schema field, like real ones: payroll and settings fields
// filled, the rest pending. Money is a plain decimal string ("55010.00") — the
// UI formats it for display.

import { birMockSchemas } from './birSchemaFixtures'

const STATUS_FLOW = {
  draft: ['pending'],
  pending: ['approved', 'draft'],
  approved: ['finalized'],
  finalized: [],
}

const JANE = { id: 5, name: 'Jane Dela Cruz' }
const MARK = { id: 2, name: 'Mark Santos' }

const EMPLOYEES = [
  { id: 11, employee_id: 'EMP011', first_name: 'Ana', last_name: 'Reyes', tin: '111-222-333-000' },
  { id: 12, employee_id: 'EMP012', first_name: 'Paolo', last_name: 'Garcia', tin: '444-555-666-000' },
  { id: 13, employee_id: 'EMP013', first_name: 'Maria', last_name: 'Santos', tin: '222-333-444-000' },
  { id: 14, employee_id: 'EMP014', first_name: 'Maria', last_name: 'Santos', tin: '555-666-777-000' },
]
const fullName = (e) => `${e.first_name} ${e.last_name}`

const SETTINGS = {
  '1601-C': {
    atc_code: 'WW010', company_tin: '000-123-456-000', rdo_code: '047',
    company_name: 'SPRINGBOARD PH INC.', company_address: '12F Tower One, Ayala Ave, Makati City',
    company_zip: '1226', agent_category: 'private',
  },
  '2316': {
    present_employer_tin: '000-123-456-000', present_employer_name: 'SPRINGBOARD PH INC.',
    present_employer_address: '12F Tower One, Ayala Ave, Makati City', present_employer_zip: '1226',
    employer_type: 'main',
  },
}

const money = (n) => n.toFixed(2)

// Payroll figures that add up the way the real aggregation's do. `seed` varies them per period.
const payroll1601c = (seed = 0) => {
  const gross = 842300 + seed * 9475
  const thirteenth = 0, deMinimis = 18000, contributions = 78450 + seed * 520
  const nontax = thirteenth + deMinimis + contributions
  const taxable = gross - nontax
  const withheld = Math.round(taxable * 0.0821 * 100) / 100
  return {
    has_taxes_withheld: true, total_compensation: money(gross), mwe_statutory_wage: '0.00',
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
  const nontax = thirteenth + contributions + 12000
  const taxable = annual - nontax
  const tax = Math.max(0, Math.round((taxable - 250000) * 0.15 * 100) / 100)
  return {
    employee_tin: employee.tin, employee_last_name: employee.last_name,
    employee_first_name: employee.first_name, basic_salary_annual: money(annual - thirteenth),
    gross_compensation_present: money(annual), less_nontaxable_present: money(nontax),
    taxable_income_present: money(taxable), gross_taxable_income: money(taxable), tax_due: money(tax),
    taxes_withheld_present: money(tax), total_taxes_withheld_adjusted: money(tax),
    total_taxes_withheld_final: money(tax), nontax_thirteenth_month: money(thirteenth),
    nontax_de_minimis: '12000.00', nontax_statutory_contributions: money(contributions),
    nontax_total: money(nontax), tax_basic_salary: money(taxable), tax_regular_total: money(taxable),
  }
}

// Every schema field: filled from `values` with the schema's source as origin, else pending.
const buildFields = (formType, values) => Object.fromEntries(
  birMockSchemas[formType].map(({ key, source }) => {
    const v = values[key]
    const filled = v !== undefined && v !== null && v !== ''
    return [key, {
      value: filled ? v : null, origin: filled ? source : 'pending',
      edited: false, system_value: null, edited_by: null, edited_at: null,
    }]
  }),
)

const edit = (fields, key, value, by, at) => ({
  ...fields,
  [key]: { value, origin: 'user', edited: true, system_value: fields[key].value, edited_by: by, edited_at: at },
})

const base = {
  version: 1, parent_id: null, prepared_by: JANE, approved_by: null,
  rejection_reason: null, validation_errors: [],
}

const draft1601c = (id, period, status, seed, extra = {}) => ({
  ...base, id, status, form_type: '1601-C', period, employee_id: null, employee_name: null,
  fields: buildFields('1601-C', { ...SETTINGS['1601-C'], ...payroll1601c(seed) }),
  ...extra,
})

const draft2316 = (id, employee, annual, status, extra = {}) => ({
  ...base, id, status, form_type: '2316', period: '2025',
  employee_id: employee.id, employee_name: `${employee.last_name}, ${employee.first_name}`,
  fields: buildFields('2316', { ...SETTINGS['2316'], ...payroll2316(employee, annual) }),
  ...extra,
})

const seed = () => {
  const d2 = draft1601c(2, '2026-06', 'pending', 1)
  d2.fields = edit(d2.fields, 'total_taxes_withheld', '61204.15', JANE, '2026-07-08T10:22:00+08:00')

  const d5 = draft1601c(5, '2026-05', 'draft', 2, {
    rejection_reason: 'Item 25 total tax withheld does not match the payroll register — please recheck May.',
    validation_errors: [{ field: 'total_taxes_withheld', message: 'Does not match calculated payroll total.' }],
  })
  d5.fields = edit(d5.fields, 'total_taxes_withheld', '55010.00', JANE, '2026-06-08T09:47:00+08:00')

  const d6 = draft2316(6, EMPLOYEES[1], 612000, 'draft', { version: 2, parent_id: 4 })
  d6.fields = edit(d6.fields, 'taxes_withheld_present', '39880.00', JANE, '2026-01-26T14:05:00+08:00')

  return [
    draft1601c(1, '2026-08', 'draft', 3),
    d2,
    draft2316(3, EMPLOYEES[0], 480000, 'approved', { approved_by: MARK }),
    draft2316(4, EMPLOYEES[1], 612000, 'finalized', { approved_by: MARK }),
    d5,
    d6,
  ]
}

// Session-lived store: mutations stick until the page reloads.
let drafts = seed()
let nextId = 100

const clone = (x) => structuredClone(x)
const delay = (ms = 450) => new Promise(res => setTimeout(res, ms))

// Rejects the way axios does, so components handle mock and real errors alike.
const fail = (status, message) => {
  const err = new Error(message)
  err.response = { status, data: { success: false, message } }
  return Promise.reject(err)
}
const ok = (data, message) => ({ success: true, data: clone(data), message })
const find = (id) => drafts.find(d => d.id === Number(id))

const getConfig = async () => {
  await delay(150)
  return {
    enabled: true, form_types: ['1601-C', '2316'], status_flow: clone(STATUS_FLOW),
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
    data: clone(data),
    pagination: {
      total: matching.length, count: data.length, per_page: perPage,
      current_page: Number(page), last_page: Math.max(1, Math.ceil(matching.length / perPage)),
    },
  }
}

const getDraft = async (id) => {
  await delay()
  const d = find(id)
  return d ? clone(d) : fail(404, 'Draft not found')
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
    draft = { ...draft2316(nextId++, employee, 540000, 'draft'), period }
  }
  drafts.push(draft)
  return ok(draft, 'Draft created (mock)')
}
const updateDraft = async (id, fields = {}) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status !== 'draft') return fail(400, `Only drafts can be edited; this form is in ${d.status} status`)
  const now = new Date().toISOString()
  Object.entries(fields).forEach(([key, value]) => {
    const prev = d.fields[key] ?? buildFields(d.form_type, {})[key]
    if (value == null || value === '') {
      d.fields[key] = { value: null, origin: 'pending', edited: false, system_value: null, edited_by: null, edited_at: null }
    } else {
      const replacesCalculated = prev.edited || ['payroll', 'settings'].includes(prev.origin)
      d.fields[key] = replacesCalculated
        ? { value: String(value), origin: 'user', edited: true,
            system_value: prev.edited ? prev.system_value : prev.value, edited_by: JANE, edited_at: now }
        : { value: String(value), origin: 'user', edited: false, system_value: null, edited_by: null, edited_at: null }
    }
  })
  return ok(d, 'Draft updated (mock)')
}

const transition = async (id, to, reason = null) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (!(STATUS_FLOW[d.status] ?? []).includes(to)) {
    return fail(400, `Cannot move a form in ${d.status} status to ${to}`)
  }
  if (d.status === 'pending' && to === 'draft' && !reason?.trim()) {
    return fail(422, 'A reason is required to return a form.')
  }
  d.status = to
  d.rejection_reason = to === 'draft' ? reason : null
  if (to === 'approved') d.approved_by = MARK
  return ok(d, `Draft moved to ${to} (mock)`)
}

const revise = async (id) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status !== 'finalized') {
    return fail(400, `Only finalized forms can be revised; this one is in ${d.status} status`)
  }
  const revision = {
    ...clone(d), id: nextId++, status: 'draft', version: d.version + 1, parent_id: d.id,
    approved_by: null, rejection_reason: null, prepared_by: JANE,
  }
  drafts.push(revision)
  return ok(revision, 'Revision created (mock)')
}

const exportDraft = async () => {
  await delay()
  return fail(501, 'PDF export is not implemented yet (Dev C, Week 6-7)')
}

// ─── Chat ─────────────────────────────────────────────────────
// A rule-based stand-in for BirChatController. Like the real one it remembers
// what earlier messages established until a request is complete (or `reset`),
// and returns { understood, intent, candidates, reply }.

const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july',
  'august', 'september', 'october', 'november', 'december']

let memory = {}

const parse = (message) => {
  const text = message.toLowerCase()
  const today = new Date()
  const out = {}

  if (/1601\s*-?\s*c?\b|monthly remittance/.test(text)) out.form_type = '1601-C'
  else if (/2316|certificate|annual/.test(text)) out.form_type = '2316'

  MONTHS.forEach((name, i) => {
    if (new RegExp(`\\b${name}\\b|\\b${name.slice(0, 3)}\\b`).test(text)) out.tax_month = i + 1
  })
  const year = Number(text.match(/\b(20\d{2})\b/)?.[1])
  if (year) out.tax_year = year

  if (/last month/.test(text)) {
    const d = new Date(today.getFullYear(), today.getMonth() - 1, 1)
    out.tax_month = d.getMonth() + 1
    out.tax_year = d.getFullYear()
  } else if (/this month/.test(text)) {
    out.tax_month = today.getMonth() + 1
    out.tax_year = today.getFullYear()
  } else if (/last year/.test(text)) out.tax_year = today.getFullYear() - 1
  else if (/this year/.test(text)) out.tax_year = today.getFullYear()

  const code = message.match(/\bEMP\d+\b/i)?.[0]
  const named = message.match(/\bfor\s+([A-Za-zÑñ.'-]+(?:\s+[A-Za-zÑñ.'-]+){0,3}?)(?=\s+(?:for|in|covering|year|20\d{2})\b|[,.?!]|$)/i)?.[1]
  const employee = code ?? named
  if (employee) out.employee_query = employee.trim()
  return out
}


const resolveEmployee = (query) => {
  const q = query.toLowerCase()
  const byCode = EMPLOYEES.filter(e => e.employee_id.toLowerCase() === q)
  if (byCode.length) return byCode
  return EMPLOYEES.filter(e => q.split(/\s+/).every(t => fullName(e).toLowerCase().includes(t)))
}

const periodOf = (m) => {
  if (!m.form_type || !m.tax_year) return null
  if (m.form_type === '1601-C') return m.tax_month ? `${m.tax_year}-${String(m.tax_month).padStart(2, '0')}` : null
  return String(m.tax_year)
}

const chat = async (message, { reset = false } = {}) => {
  await delay(1100)
  if (!message?.trim()) return fail(422, 'The message field is required.')
  if (/\boffline\b|\boutage\b/i.test(message)) {
    // Lets the UI's "assistant unavailable" state be exercised on demand.
    return fail(503, 'The assistant is unavailable right now. You can still pick a form and period manually.')
  }
  if (reset) memory = {}

  const parsed = parse(message)
  if (parsed.employee_query) parsed.employee_id = null
  memory = { ...memory, ...parsed }
  if (memory.form_type === '2316') memory.tax_month = null
  if (memory.form_type === '1601-C') { memory.employee_query = null; memory.employee_id = null }
  const intent = () => ({
    form_type: memory.form_type ?? null, tax_year: memory.tax_year ?? null,
    tax_month: memory.tax_month ?? null, employee_query: memory.employee_query ?? null,
    employee_id: memory.employee_id ?? null, period: periodOf(memory),
  })
  const reply = (understood, text, candidates = []) => ({ understood, intent: intent(), candidates, reply: text })

  const missing = []
  if (!memory.form_type) missing.push('which form')
  if (!memory.tax_year) missing.push('the year')
  if (memory.form_type === '1601-C' && !memory.tax_month) missing.push('the month')
  if (memory.form_type === '2316' && !memory.employee_query) missing.push('which employee')
  if (missing.length) {
    const last = missing.pop()
    return reply(false, `Could you tell me ${missing.length ? `${missing.join(', ')} and ${last}` : last}?`)
  }

  if (memory.form_type === '1601-C') {
    const done = reply(true, `Understood: a 1601-C covering ${periodOf(memory)}.`)
    memory = {}
    return done
  }

  const hits = resolveEmployee(memory.employee_query)
  if (!hits.length) {
    return reply(false, `I could not find anyone matching "${memory.employee_query}". Could you check the spelling, or give their employee ID?`)
  }
  if (hits.length > 1) {
    const candidates = hits.map(e => ({ id: e.id, employee_id: e.employee_id, name: fullName(e) }))
    const names = candidates.map(c => `${c.name} (${c.employee_id})`).join(', ')
    return reply(false, `More than one person matches that: ${names}. Which one did you mean?`, candidates)
  }
  const e = hits[0]
  memory.employee_id = e.id
  const done = reply(true, `Understood: a 2316 for ${fullName(e)} (${e.employee_id}), covering ${periodOf(memory)}.`)
  memory = {}
  return done
}

const getEmployees = async () => {
  await delay(200)
  return { data: clone(EMPLOYEES), pagination: { total: EMPLOYEES.length } }
}

export const birMockApi = {
  getConfig, getDrafts, getDraft, createDraft, updateDraft, transition, revise, exportDraft, chat, getEmployees,
}