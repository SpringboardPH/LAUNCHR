// BIR Form Assistant — mock form history (GET /bir/drafts/{id}/history), for mock mode only;
// removed with birFixtures.js. Entries are newest first; a seeded form starts at "created".

// What each status move means, as BirFormRecordController::MOVES words it.
const MOVES = {
  'draft>pending': ['submitted', 'Submitted for review'],
  'pending>approved': ['approved', 'Approved'],
  'pending>draft': ['returned', 'Returned to the preparer'],
  'approved>finalized': ['filed', 'Marked as filed'],
}

export function mockHistory({ find, fail, delay, ok, by, seededBy }) {
  const logs = {}
  const log = (d, event, text, extra = {}) => (logs[d.id] ??= []).unshift({
    id: `${d.id}-${Date.now()}-${Math.random()}`, at: new Date().toISOString(), by: by(), event, text, reason: null, changes: [], ...extra,
  })
  const getHistory = async (id) => {
    const d = await delay(250).then(() => find(id))
    if (!d) return fail(404, 'Draft not found')
    const list = logs[d.id] ?? []
    const created = { id: `${d.id}-seed`, at: null, by: d.prepared_by ?? seededBy, event: 'created', text: 'Form created from payroll data', reason: null, changes: [] }
    return ok(list.some(e => e.event === 'created') ? list : [...list, created])
  }
  return { log, getHistory, MOVES }
}
