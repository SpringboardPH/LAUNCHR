// BIR Form Assistant — mock backend for frontend work before the API is complete.
//
// Mirrors docs/bir-api-contract.md exactly: the draft object (§2), field entries
// (§3), the status flow (§5) and the /bir/chat reply shape. Only queries.js
// imports this, and only in dev builds. Week 7 removes it from app code.
//
// Money is a plain decimal string ("55010.00"), as real drafts will be — the UI
// formats for display. Keys come from Dev A's schemas; this file only uses a
// sample of them, so never treat it as the key list.

const STATUS_FLOW = {
  draft: ['pending'],
  pending: ['approved', 'draft'],
  approved: ['finalized'],
  finalized: [],
}

const JANE = { id: 5, name: 'Jane Dela Cruz' }
const MARK = { id: 2, name: 'Mark Santos' }

// One field entry. Keeps the three contract invariants:
// value null ⟺ origin pending; edited ⟹ origin user; provenance only when edited.
const f = (value, origin) => ({
  value: value ?? null,
  origin: value == null ? 'pending' : origin,
  edited: false,
  system_value: null,
  edited_by: null,
  edited_at: null,
})
const edited = (value, systemValue, by, at) => ({
  value, origin: 'user', edited: true, system_value: systemValue, edited_by: by, edited_at: at,
})

const company = {
  company_tin: f('000-123-456-000', 'settings'),
  rdo_code: f('047', 'settings'),
  company_name: f('SPRINGBOARD PH INC.', 'settings'),
  company_address: f('12F Tower One, Ayala Ave, Makati City', 'settings'),
}

const draft1601c = (period, fields) => ({
  form_type: '1601-C',
  period,
  employee_id: null,
  employee_name: null,
  fields: {
    return_period: f(`${period.slice(5, 7)}/${period.slice(0, 4)}`, 'user'),
    ...company,
    ...fields,
  },
})

const draft2316 = (employeeId, employeeName, tin, fields) => ({
  form_type: '2316',
  period: '2025',
  employee_id: employeeId,
  employee_name: employeeName,
  fields: {
    tax_year: f('2025', 'user'),
    period_from: f('01/01', 'payroll'),
    period_to: f('12/31', 'payroll'),
    employee_tin: f(tin, 'payroll'),
    present_employer_tin: company.company_tin,
    ...fields,
  },
})

const base = {
  version: 1,
  parent_id: null,
  prepared_by: JANE,
  approved_by: null,
  rejection_reason: null,
  validation_errors: [],
}

// One draft per status, plus the awkward cases: a rejected draft carrying a
// validation error, and a version-2 revision of a finalized form.
const SEED = [
  {
    ...base, id: 1, status: 'draft',
    ...draft1601c('2026-08', {
      total_compensation: f('868420.00', 'payroll'),
      total_nontaxable_compensation: f(null),
      total_taxes_withheld: f(null),
    }),
  },
  {
    ...base, id: 2, status: 'pending',
    ...draft1601c('2026-06', {
      total_compensation: f('842300.00', 'payroll'),
      total_nontaxable_compensation: f('96450.00', 'payroll'),
      total_taxable_compensation: f('745850.00', 'payroll'),
      total_taxes_withheld: edited('61204.15', '61024.15', JANE, '2026-07-08T10:22:00+08:00'),
    }),
  },
  {
    ...base, id: 3, status: 'approved', approved_by: MARK,
    ...draft2316(11, 'Reyes, Ana', '111-222-333-000', {
      gross_compensation_present: f('480000.00', 'payroll'),
      nontax_thirteenth_month: f('40000.00', 'payroll'),
      taxes_withheld_present: f('28450.00', 'payroll'),
    }),
  },
  {
    ...base, id: 4, status: 'finalized', approved_by: MARK,
    ...draft2316(12, 'Garcia, Paolo', '444-555-666-000', {
      gross_compensation_present: f('612000.00', 'payroll'),
      taxes_withheld_present: f('39460.00', 'payroll'),
    }),
  },
  {
    ...base, id: 5, status: 'draft',
    rejection_reason: 'Item 25 total tax withheld does not match the payroll register — please recheck May.',
    validation_errors: [
      { field: 'total_taxes_withheld', message: 'Does not match calculated payroll total.' },
    ],
    ...draft1601c('2026-05', {
      total_compensation: f('795150.00', 'payroll'),
      total_taxes_withheld: edited('55010.00', '54872.50', JANE, '2026-06-08T09:47:00+08:00'),
    }),
  },
  {
    ...base, id: 6, status: 'draft', version: 2, parent_id: 4,
    ...draft2316(12, 'Garcia, Paolo', '444-555-666-000', {
      gross_compensation_present: f('612000.00', 'payroll'),
      taxes_withheld_present: edited('39880.00', '39460.00', JANE, '2026-01-26T14:05:00+08:00'),
      previous_employer_tin: f(null),
      taxable_income_previous_employer: f(null),
    }),
  },
]

// Session-lived store: mutations stick until the page reloads.
let drafts = structuredClone(SEED)
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
  return { enabled: true, form_types: ['1601-C', '2316'], status_flow: clone(STATUS_FLOW) }
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
      total: matching.length,
      count: data.length,
      per_page: perPage,
      current_page: Number(page),
      last_page: Math.max(1, Math.ceil(matching.length / perPage)),
    },
  }
}

const getDraft = async (id) => {
  await delay()
  const d = find(id)
  return d ? clone(d) : fail(404, 'Draft not found')
}

const createDraft = async ({ form_type, period, employee_id = null, employee_name = null }) => {
  await delay(900)
  if (!['1601-C', '2316'].includes(form_type) || !period) {
    return fail(422, 'The form type and period are required.')
  }
  const shape = form_type === '1601-C'
    ? draft1601c(period, {
        total_compensation: f('851775.00', 'payroll'),
        total_nontaxable_compensation: f('98120.00', 'payroll'),
        total_taxable_compensation: f('753655.00', 'payroll'),
        total_taxes_withheld: f('58940.30', 'payroll'),
        prior_month_adjustment: f(null),
      })
    : { ...draft2316(employee_id, employee_name, '777-888-999-000', {
          gross_compensation_present: f('540000.00', 'payroll'),
          taxes_withheld_present: f('31200.00', 'payroll'),
          previous_employer_tin: f(null),
          taxable_income_previous_employer: f(null),
        }), period }
  const draft = { ...base, id: nextId++, status: 'draft', ...shape }
  drafts.push(draft)
  return ok(draft, 'Draft generated (mock)')
}

const updateDraft = async (id, fields = {}) => {
  await delay()
  const d = find(id)
  if (!d) return fail(404, 'Draft not found')
  if (d.status !== 'draft') return fail(400, `Cannot edit a form in ${d.status} status`)
  const now = new Date().toISOString()
  Object.entries(fields).forEach(([key, value]) => {
    const prev = d.fields[key] ?? f(null)
    const wasCalculated = prev.origin === 'payroll' || prev.origin === 'settings'
    d.fields[key] = value == null || value === ''
      ? f(null)
      : wasCalculated || prev.edited
        ? edited(String(value), prev.edited ? prev.system_value : prev.value, JANE, now)
        : { ...f(String(value), 'user') }
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
// A tiny rule-based stand-in for BirChatController + BirIntentService. It
// returns the same { understood, intent, reply } shape so the chat panel can
// be built and played through without an LLM.

const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july',
  'august', 'september', 'october', 'november', 'december']

const parseIntent = (message) => {
  const text = message.toLowerCase()
  const today = new Date()

  let formType = null
  if (/1601\s*-?\s*c?\b|monthly remittance/.test(text)) formType = '1601-C'
  else if (/2316|certificate|annual/.test(text)) formType = '2316'

  let month = null
  MONTHS.forEach((name, i) => {
    if (new RegExp(`\\b${name}\\b|\\b${name.slice(0, 3)}\\b`).test(text)) month = i + 1
  })
  let year = Number(text.match(/\b(20\d{2})\b/)?.[1]) || null

  if (/last month/.test(text)) {
    const d = new Date(today.getFullYear(), today.getMonth() - 1, 1)
    month = d.getMonth() + 1
    year = d.getFullYear()
  } else if (/this month/.test(text)) {
    month = today.getMonth() + 1
    year = today.getFullYear()
  } else if (/last year/.test(text)) {
    year = today.getFullYear() - 1
  } else if (/this year/.test(text)) {
    year = today.getFullYear()
  }

  const employee = formType === '2316'
    ? message.match(/\bfor\s+([A-Za-zÑñ.'-]+(?:\s+[A-Za-zÑñ.'-]+){0,3}?)(?=\s+(?:for|in|covering|year|20\d{2})\b|[,.?!]|$)/i)?.[1] ?? null
    : null
  if (formType === '2316') month = null

  const missing = []
  if (!formType) missing.push('which form')
  if (!year) missing.push('the year')
  if (formType === '1601-C' && !month) missing.push('the month')
  if (formType === '2316' && !employee) missing.push('which employee')

  const period = !formType || !year ? null
    : formType === '1601-C' ? (month ? `${year}-${String(month).padStart(2, '0')}` : null)
    : String(year)

  return {
    form_type: formType,
    tax_year: year,
    tax_month: month,
    period,
    employee_query: employee,
    needs_clarification: missing.length > 0,
    clarification: missing.length
      ? `Could you tell me ${missing.join(' and ')}? For example: "the August 2026 1601-C" or "the 2025 2316 for Ana Reyes".`
      : null,
  }
}

const chat = async (message) => {
  await delay(1100)
  if (!message?.trim()) return fail(422, 'The message field is required.')
  if (/\boffline\b|\boutage\b/i.test(message)) {
    // Lets the UI's "assistant unavailable" state be exercised on demand.
    return fail(503, 'The assistant is unavailable right now. You can still pick a form and period manually.')
  }
  const intent = parseIntent(message)
  if (intent.needs_clarification) {
    return { understood: false, intent, reply: intent.clarification }
  }
  const reply = intent.employee_query
    ? `Understood: a ${intent.form_type} for ${intent.employee_query}, covering ${intent.period}. Looking up the employee is not wired up yet.`
    : `Understood: a ${intent.form_type} covering ${intent.period}.`
  return { understood: true, intent, reply }
}

export const birMockApi = {
  getConfig, getDrafts, getDraft, createDraft, updateDraft, transition, revise, exportDraft, chat,
}
