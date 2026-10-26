// BIR Forms to-do: what is due (deadlines) and what needs the user, worked out from the forms
// list. No backend of its own: every form already carries its type, period, status, preparer,
// return note and signing.
import { addDays, addMonths, differenceInCalendarDays, format, isSaturday, isSunday, startOfMonth } from 'date-fns'

// The 1601-C is due on this day of the following month: the 10th when filed manually, or
// the eFPS group's day (RR 26-2002). Kept per browser; the accountant picks it once.
export const FILING_DAYS = [
  [10, 'Manual / eBIRForms (10th)'],
  [15, 'eFPS Group A (15th)'],
  [14, 'eFPS Group B (14th)'],
  [13, 'eFPS Group C (13th)'],
  [12, 'eFPS Group D (12th)'],
  [11, 'eFPS Group E (11th)'],
]
const SOON_DAYS = 30
// A 2316 is given to the employee by January 31 of the next year.
const CERTIFICATE_DUE = { month: 0, day: 31 }

const live = (forms) => forms.filter(f => f.status !== 'void')
// A deadline on a weekend moves to the Monday (holidays are not known here).
const workday = (d) => (isSaturday(d) ? addDays(d, 2) : isSunday(d) ? addDays(d, 1) : d)
const STAGE = { draft: 1, pending: 2, approved: 3, finalized: 4 }
const furthest = (forms) => forms.reduce((a, b) => ((STAGE[b.status] ?? 0) > (STAGE[a.status] ?? 0) ? b : a), forms[0])
export const STAGE_WORD = { draft: 'Draft', pending: 'Waiting for review', approved: 'Approved, not filed yet' }

// "Due in 3 days", "Due today", "Overdue by 5 days".
export const whenDue = (due, today) => {
  const days = differenceInCalendarDays(due, today)
  if (days < 0) return `Overdue by ${-days} day${days === -1 ? '' : 's'}`
  if (days === 0) return 'Due today'
  return `Due in ${days} day${days === 1 ? '' : 's'}`
}

/**
 * Deadlines not met yet, most urgent first: { key, title, detail, due, overdue, form?, create? }.
 * A 1601-C for each month from the first one in the system (at most a year back), shown once
 * it is due within 30 days or overdue; the year's 2316s from December, or overdue once any
 * exists for that year. Filed forms are done.
 */
export function dueItems(forms, employees, today, filingDay = 10) {
  const items = []
  const monthly = live(forms).filter(f => f.form_type === '1601-C')
  const earliest = monthly.map(f => f.period).sort()[0]
  const floor = startOfMonth(addMonths(today, -12))
  let month = earliest ? startOfMonth(new Date(`${earliest}-01T00:00:00`)) : startOfMonth(addMonths(today, -1))
  if (month < floor) month = floor

  for (; month <= startOfMonth(today); month = addMonths(month, 1)) {
    const period = format(month, 'yyyy-MM')
    const due = workday(new Date(month.getFullYear(), month.getMonth() + 1, filingDay))
    const these = monthly.filter(f => f.period === period)
    const form = these.length ? furthest(these) : null
    if (form?.status === 'finalized' || differenceInCalendarDays(due, today) > SOON_DAYS) continue
    items.push({
      key: `1601C-${period}`, title: `1601-C · ${format(month, 'MMMM yyyy')}`,
      detail: form ? STAGE_WORD[form.status] ?? form.status : 'Not started',
      due, overdue: differenceInCalendarDays(due, today) < 0,
      form, create: form ? null : { form_type: '1601-C', period },
    })
  }

  const year = today.getMonth() === 11 ? today.getFullYear() : today.getFullYear() - 1
  const certificates = live(forms).filter(f => f.form_type === '2316' && f.period === String(year))
  const due = workday(new Date(year + 1, CERTIFICATE_DUE.month, CERTIFICATE_DUE.day))
  const filed = new Set(certificates.filter(f => f.status === 'finalized').map(f => f.employee_id))
  const without = employees.filter(e => !certificates.some(f => f.employee_id === e.id)).length
  const open = new Set(certificates.map(f => f.employee_id).filter(id => !filed.has(id))).size + without
  const upcoming = differenceInCalendarDays(due, today) <= 62 // from December
  if (open > 0 && (upcoming || certificates.length > 0)) {
    items.push({
      key: `2316-${year}`, title: `2316 · Taxable year ${year}`,
      detail: `${filed.size} filed${without ? ` · ${without} employee${without === 1 ? '' : 's'} without one yet` : ''} · give copies to employees`,
      due, overdue: differenceInCalendarDays(due, today) < 0, employees: true,
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
