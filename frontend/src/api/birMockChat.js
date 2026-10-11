// BIR Form Assistant — mock /bir/chat for mock mode only. Removed with birFixtures.js in Week 7.
//
// A rule-based stand-in for BirChatController with the same reply shapes:
//   - without a draft: understands a request, remembering earlier messages until it is
//     complete (or `reset`), and returns { understood, intent, candidates, reply };
//   - with { draftId }: asks for the draft's missing fields one at a time and reads the
//     answers, returning { reply, draft_id, question, answer, done }. An answer is only
//     read, never saved: the caller saves it through updateDraft, as with the real API.

const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july',
  'august', 'september', 'october', 'november', 'december']

const DECLINE = 'I can only help fill in and explain this form. For tax questions, please ask accounting.'
const OTHER = 'I can explain any box on this form, or ask you for what is still missing.'

const boxName = (f) => `"${f.item != null ? `Item ${f.item}, ${f.label}` : f.label}"`
const peso = (v) => `₱${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const display = (value, type) => (type === 'boolean' ? (value ? 'yes' : 'no') : type === 'decimal' ? peso(value) : String(value))
const looksLikeQuestion = (text) => /\?\s*$/.test(text) || /^(what|where|why|how|which|who|ano|saan|bakit|paano)\b/i.test(text.trim())

const ASK = {
  boolean: 'Yes or no?', decimal: 'What is the amount in pesos? Enter 0 if there was none.',
  date: 'What is the date (MM/DD/YYYY)?', month: 'Which month (MM/YYYY)?', integer: 'How many?',
}

// The same wording BirConversationService uses for a question.
const questionFor = (field, reason) => {
  const why = reason === 'record_gap'
    ? ` should come from the ${field.source === 'settings' ? 'company settings' : 'employee or payroll records'}, but nothing is recorded there. It is best fixed there, but you can enter it here.`
    : ' is needed for this form.'
  const ask = field.type === 'enum' ? `Which one: ${(field.options ?? []).join(' or ')}?` : (ASK[field.type] ?? 'What is it?')
  return {
    field: field.key, item: field.item, label: field.label, type: field.type,
    options: field.options ?? null, reason, guidance: field.guidance ?? null,
    text: `${boxName(field)}${why} ${ask}`,
  }
}

// Reads a typed answer into the value the field stores, or explains what was wrong.
const parseAnswer = (message, q) => {
  const text = message.trim()
  const bad = (problem) => ({ ok: false, problem })
  switch (q.type) {
    case 'boolean':
      if (/^(yes|y|oo|opo|true|1)$/i.test(text)) return { ok: true, value: true }
      if (/^(no|n|hindi|false|0)$/i.test(text)) return { ok: true, value: false }
      return bad('Please answer yes or no.')
    case 'integer':
      return /^\d+$/.test(text) ? { ok: true, value: Number(text) } : bad('Please enter a whole number, for example 2.')
    case 'decimal': {
      const plain = text.replace(/[₱,\s]|php/gi, '')
      return /^-?\d+(\.\d+)?$/.test(plain) ? { ok: true, value: Number(plain).toFixed(2) } : bad('Please enter an amount in pesos, for example 1500.00.')
    }
    case 'month':
      return /^(0[1-9]|1[0-2])\/\d{4}$/.test(text) ? { ok: true, value: text } : bad('Please enter the month as MM/YYYY, for example 08/2026.')
    case 'date': {
      const m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/)
      return m ? { ok: true, value: `${m[3]}-${m[1].padStart(2, '0')}-${m[2].padStart(2, '0')}` } : bad('Please enter the date as MM/DD/YYYY.')
    }
    case 'enum': {
      const hit = (q.options ?? []).find(o => o.toLowerCase() === text.toLowerCase())
      return hit ? { ok: true, value: hit } : bad(`Please choose one of: ${(q.options ?? []).join(', ')}.`)
    }
    default:
      return text ? { ok: true, value: text } : bad('Please type an answer.')
  }
}

const parseIntent = (message) => {
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
  if (code ?? named) out.employee_query = (code ?? named).trim()
  return out
}

const periodOf = (m) => {
  if (!m.form_type || !m.tax_year) return null
  if (m.form_type === '1601-C') return m.tax_month ? `${m.tax_year}-${String(m.tax_month).padStart(2, '0')}` : null
  return String(m.tax_year)
}

/**
 * @param deps.find          (id) => the stored draft, or undefined
 * @param deps.schemas       form type => schema field list
 * @param deps.missingFor    (draft) => [{ field, reason }] in printed order
 * @param deps.errorCount    (draft) => number of severity "error" entries
 * @param deps.employees     mock employee records
 * @param deps.delay, deps.fail  shared mock helpers
 */
export const mockChat = ({ find, schemas, missingFor, errorCount, employees, delay, fail }) => {
  let memory = {}
  const fullName = (e) => `${e.first_name} ${e.last_name}`

  const nextQuestion = (draft) => {
    const gap = missingFor(draft)[0]
    if (!gap) return null
    return questionFor(schemas[draft.form_type].find(f => f.key === gap.field), gap.reason)
  }

  // A message that isn't an answer: explained from the schema, never from a guess.
  const sideReply = (draft, message, openField) => {
    const text = message.toLowerCase()
    if (/\b(tax rate|how much tax|should i|is it allowed|deductible|exempt|penalt)/.test(text)) return DECLINE
    const item = text.match(/item\s+(\d+[a-z]?)/i)?.[1]?.toUpperCase()
    const schema = schemas[draft.form_type]
    const field = (item && schema.find(f => String(f.item).toUpperCase() === item)) || schema.find(f => f.key === openField)
    if (!field) return 'Which box do you mean? You can say "item 14", for example.'
    if (/where|come from|source|how (was|is) .*(computed|calculated)|saan/.test(text)) {
      const where = { payroll: 'is calculated from the payroll this draft was built from.',
        settings: 'comes from the company settings.', user: 'is entered by a person; the system has no record of it.' }
      return `${boxName(field)} ${where[field.source] ?? 'is signed by hand on the printed form.'}`
    }
    if (/mean|what is|what's|explain|ano/.test(text)) return `${boxName(field)}: ${field.guidance ?? 'No help text yet.'}`
    return OTHER
  }

  const doneText = (draft) => {
    const errors = errorCount(draft)
    return errors === 0
      ? 'That is everything this form needs from you. Check the preview, then use Submit to send it for review.'
      : `That is everything I can ask here, but ${errors} ${errors === 1 ? 'issue still needs' : 'issues still need'} fixing before it can be submitted. They are listed on the draft.`
  }

  const draftTurn = (draftId, field, message) => {
    const draft = find(draftId)
    if (!draft) return fail(404, 'Draft not found')
    const turn = (question, answer, reply) => ({
      understood: false, intent: null, candidates: [], reply, draft_id: draft.id,
      question, answer, done: question === null && answer === null,
    })
    if (['approved', 'finalized'].includes(draft.status)) {const locked = `This form is ${draft.status}, so it can't be changed here.`
      return turn(null, null, message ? `${sideReply(draft, message, null)}` : locked)
    }
    const next = nextQuestion(draft)
    if (message == null) return turn(next, null, next ? next.text : doneText(draft))
    if (next && field === next.field) {
      const parsed = parseAnswer(message, next)
      if (parsed.ok) {
        return turn(null, { field: next.field, value: parsed.value }, `Got it: ${next.label}: ${display(parsed.value, next.type)}.`)
      }
      if (!looksLikeQuestion(message)) return turn(next, null, parsed.problem)
    }
    const side = sideReply(draft, message, next?.field)
    return turn(next, null, next ? `${side} ${next.text}` : side)
  }

  const intentTurn = (message, reset) => {
    if (reset) memory = {}
    const parsed = parseIntent(message)
    if (parsed.employee_query) parsed.employee_id = null
    memory = { ...memory, ...parsed }
    if (memory.form_type === '2316') memory.tax_month = null
    if (memory.form_type === '1601-C') { memory.employee_query = null; memory.employee_id = null }
    const intent = () => ({
      form_type: memory.form_type ?? null, tax_year: memory.tax_year ?? null, tax_month: memory.tax_month ?? null,
      employee_query: memory.employee_query ?? null, employee_id: memory.employee_id ?? null, period: periodOf(memory),
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
    const q = memory.employee_query.toLowerCase()
    const byCode = employees.filter(e => e.employee_id.toLowerCase() === q)
    const hits = byCode.length ? byCode : employees.filter(e => q.split(/\s+/).every(t => fullName(e).toLowerCase().includes(t)))
    if (!hits.length) {
      return reply(false, `I could not find anyone matching "${memory.employee_query}". Could you check the spelling, or give their employee ID?`)
    }
    if (hits.length > 1) {
      const candidates = hits.map(e => ({ id: e.id, employee_id: e.employee_id, name: fullName(e) }))
      return reply(false, `More than one person matches that: ${candidates.map(c => `${c.name} (${c.employee_id})`).join(', ')}. Which one did you mean?`, candidates)
    }
    memory.employee_id = hits[0].id
    const done = reply(true, `Understood: a 2316 for ${fullName(hits[0])} (${hits[0].employee_id}), covering ${periodOf(memory)}.`)
    memory = {}
    return done
  }

  return async (message, { reset = false, draftId, field } = {}) => {
    await delay(1100)
    if (message != null && /\boffline\b|\boutage\b/i.test(message)) {
      // Lets the UI's "assistant unavailable" state be exercised on demand.
      return fail(503, 'The assistant is unavailable right now. You can still pick a form and period manually.')
    }
    if (draftId) return draftTurn(draftId, field, message)
    if (!message?.trim()) return fail(422, 'The message field is required.')
    return intentTurn(message, reset)
  }
}