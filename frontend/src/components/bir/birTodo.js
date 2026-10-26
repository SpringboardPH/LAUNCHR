// BIR Forms to-do: what is due (deadlines) and what needs the user, worked out from the forms
// list. No backend of its own: every form already carries its type, period, status, preparer,
// return note and signing.
import { addMonths, differenceInCalendarDays, format, startOfMonth } from 'date-fns'

// Filing windows (the accountant's rules): a month's 1601-C is filed from the 1st to the 10th
// of the next month; a year's 2316s from January 1 to January 31 of the next year.
const MONTHLY = { opens: 1, due: 10 }
const SOON_DAYS = 30

const live = (forms) => forms.filter(f => f.status !== 'void')
const STAGE = { draft: 1, pending: 2, approved: 3, finalized: 4 }
const furthest = (forms) => forms.reduce((a, b) => ((STAGE[b.status] ?? 0) > (STAGE[a.status] ?? 0) ? b : a), forms[0])
export const STAGE_WORD = { draft: 'Draft', pending: 'Waiting for review', approved: 'Approved, not filed yet' }

// Where an item stands today: before its window ('upcoming'), in it ('open'), or past it ('overdue').
export const stateOf = (item, today) => (differenceInCalendarDays(item.due, today) < 0 ? 'overdue'
  : differenceInCalendarDays(item.opens, today) > 0 ? 'upcoming' : 'open')

// "Opens in 6 days", "Due in 3 days", "Due today", "Overdue by 5 days".
export const whenDue = (item, today) => {
  const toOpen = differenceInCalendarDays(item.opens, today)
  const days = differenceInCalendarDays(item.due, today)
  if (days < 0) return `Overdue by ${-days} day${days === -1 ? '' : 's'}`
  if (toOpen > 0) return `Opens in ${toOpen} day${toOpen === 1 ? '' : 's'}`
  if (days === 0) return 'Due today'
  return `Due in ${days} day${days === 1 ? '' : 's'}`
}
// "Nov 1–10, 2026"
const windowText = (opens, due) => `${format(opens, 'MMM d')}–${format(due, opens.getMonth() === due.getMonth() ? 'd, yyyy' : 'MMM d, yyyy')}`

// One form's filing window: a 1601-C's month from the 1st to the 10th of the next month, a 2316's
// year January 1-31 of the next. Null for a period it can't read.
export function windowOf(form) {
  const m = /^(\d{4})(?:-(\d{2}))?$/.exec(form.period ?? '')
  if (!m) return null
  const year = Number(m[1])
  if (form.form_type === '1601-C' && m[2]) {
    const month = Number(m[2]) - 1
    return { opens: new Date(year, month + 1, MONTHLY.opens), due: new Date(year, month + 1, MONTHLY.due) }
  }
  return form.form_type === '2316' ? { opens: new Date(year + 1, 0, 1), due: new Date(year + 1, 0, 31) } : null
}

/**
 * Filing not done yet, most urgent first: { key, title, detail, opens, due, form?, create? }.
 * A 1601-C for each month from the first one in the system (at most a year back), shown from
 * 30 days before its deadline, and while overdue; the year's 2316s from December, or overdue
 * once any exists for that year. Filed forms are done.
 */
export function dueItems(forms, employees, today) {
  const items = []
  const monthly = live(forms).filter(f => f.form_type === '1601-C')
  const earliest = monthly.map(f => f.period).sort()[0]
  const floor = startOfMonth(addMonths(today, -12))
  let month = earliest ? startOfMonth(new Date(`${earliest}-01T00:00:00`)) : startOfMonth(addMonths(today, -1))
  if (month < floor) month = floor

  for (; month <= startOfMonth(today); month = addMonths(month, 1)) {
    const period = format(month, 'yyyy-MM')
    const opens = new Date(month.getFullYear(), month.getMonth() + 1, MONTHLY.opens)
    const due = new Date(month.getFullYear(), month.getMonth() + 1, MONTHLY.due)
    const these = monthly.filter(f => f.period === period)
    const form = these.length ? furthest(these) : null
    if (form?.status === 'finalized' || differenceInCalendarDays(due, today) > SOON_DAYS) continue
    items.push({
      key: `1601C-${period}`, title: `1601-C · ${format(month, 'MMMM yyyy')}`,
      detail: `${form ? STAGE_WORD[form.status] ?? form.status : 'Not started'} · file ${windowText(opens, due)}`,
      opens, due, form, create: form ? null : { form_type: '1601-C', period },
    })
  }

  const year = today.getMonth() === 11 ? today.getFullYear() : today.getFullYear() - 1
  const certificates = live(forms).filter(f => f.form_type === '2316' && f.period === String(year))
  const opens = new Date(year + 1, 0, 1)
  const due = new Date(year + 1, 0, 31)
  const filed = new Set(certificates.filter(f => f.status === 'finalized').map(f => f.employee_id))
  // Hired after the year had no pay in it, so needs no 2316 for it.
  const without = employees.filter(e => !(e.hire_date && Number(e.hire_date.slice(0, 4)) > year)
    && !certificates.some(f => f.employee_id === e.id)).length
  const open = new Set(certificates.map(f => f.employee_id).filter(id => !filed.has(id))).size + without
  const upcoming = differenceInCalendarDays(due, today) <= 62 // from December
  if (open > 0 && (upcoming || certificates.length > 0)) {
    items.push({
      key: `2316-${year}`, title: `2316 · Taxable year ${year}`,
      detail: `${filed.size} filed${without ? ` · ${without} without one yet` : ''} · file ${windowText(opens, due)}`,
      opens, due, employees: true,
    })
  }

  return items.sort((a, b) => a.due - b.due)
}

/**
 * What needs this user, in groups: forms waiting for their review, returned to them, approved
 * but not marked as filed, and filed but not signed (optional). Review, filing and signing are
 * accounting's, so only accounting sees those groups.
 */
export function attentionGroups(forms, user) {
  const mine = (f) => user?.id != null && f.prepared_by?.id === user.id
  const accounting = user?.role === 'accounting'
  const latest = (f) => !forms.some(o => o !== f && o.status !== 'void' && o.form_type === f.form_type
    && o.period === f.period && o.employee_id === f.employee_id && o.version > f.version)
  const groups = [
    ['review', 'Waiting for your review', accounting && forms.filter(f => f.status === 'pending')],
    ['returned', 'Returned to you', forms.filter(f => f.status === 'draft' && f.rejection_reason && mine(f))],
    ['approved', 'Approved, not marked as filed yet', accounting && forms.filter(f => f.status === 'approved')],
    ['unsigned', 'Filed, not signed yet (optional)', accounting && forms.filter(f => f.status === 'finalized' && !f.signing?.method && latest(f))],
  ]
  return groups.filter(([, , list]) => list && list.length).map(([key, title, list]) => ({ key, title, forms: list }))
}

// The count on the sidebar: what can't wait. Optional signing is left out.
export const attentionCount = (forms, user) => attentionGroups(forms, user)
  .filter(g => g.key !== 'unsigned').reduce((n, g) => n + g.forms.length, 0)
