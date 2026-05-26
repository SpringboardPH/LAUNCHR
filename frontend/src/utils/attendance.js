
/**
 * Returns the current cutoff period based on a given date (defaults to now).
 * Cutoffs: 26th of previous month to 10th of current month, 11th to 25th of current month.
 */
export const getCutoffPeriod = (date = new Date()) => {
  const d = new Date(date)
  const day = d.getDate()
  const month = d.getMonth()
  const year = d.getFullYear()

  if (day >= 11 && day <= 25) {
    const start = new Date(year, month, 11)
    const end = new Date(year, month, 25)
    return {
      startDate: `${year}-${String(month + 1).padStart(2, '0')}-11`,
      endDate: `${year}-${String(month + 1).padStart(2, '0')}-25`,
      label: `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
    }
  } else if (day > 25) {
    const start = new Date(year, month, 26)
    const end = new Date(year, month + 1, 10)
    return {
      startDate: `${year}-${String(month + 1).padStart(2, '0')}-26`,
      endDate: `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}-10`,
      label: `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
    }
  } else {
    const start = new Date(year, month - 1, 26)
    const end = new Date(year, month, 10)
    return {
      startDate: `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-26`,
      endDate: `${year}-${String(month + 1).padStart(2, '0')}-10`,
      label: `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
    }
  }
}

export const getNextCutoff = (cutoff) => {
  const end = new Date(cutoff.endDate)
  end.setDate(end.getDate() + 2) // move past the end date
  return getCutoffPeriod(end)
}

export const getPrevCutoff = (cutoff) => {
  const start = new Date(cutoff.startDate)
  start.setDate(start.getDate() - 2) // move before the start date
  return getCutoffPeriod(start)
}

/**
 * sysClock: optional object from /api/system-clock
 *   { day_of_week: number, minutes_since_midnight: number, time: "HH:MM:SS", date: "YYYY-MM-DD" }
 * Falls back to real browser time if not provided.
 */
export const getClockWindow = (schedule, sysClock = null) => {
  const template = schedule?.template
  if (!template) return null

  // Use system clock if available, otherwise real browser time
  const dayOfWeek = sysClock != null ? sysClock.day_of_week : new Date().getDay()
  const currentMinutes = sysClock != null
    ? sysClock.minutes_since_midnight
    : new Date().getHours() * 60 + new Date().getMinutes()

  const parse = (t) => {
    if (!t) return 0
    const [h, m] = t.split(':').map(Number)
    return h * 60 + (m || 0)
  }

  const formatTime = (m) => {
    const h = Math.floor(m / 60)
    const min = m % 60
    return `${h.toString().padStart(2, '0')}:${min.toString().padStart(2, '0')}`
  }

  const dayRule = (template.day_rules || []).find(r => r.day === dayOfWeek)

  if (dayRule && !dayRule.enabled) {
    return {
      isInactiveDay: true,
      currentMinutes,
      workStart: dayRule.clock_in?.substring(0, 5) || template.work_start_time?.substring(0, 5) || '—',
      workEnd: dayRule.clock_out?.substring(0, 5) || template.work_end_time?.substring(0, 5) || '—',
      formatTime: (m) => {
        const h = Math.floor(m / 60)
        const min = m % 60
        return `${h.toString().padStart(2, '0')}:${min.toString().padStart(2, '0')}`
      },
    }
  }

  let inStart, inEnd, outStart, outEnd
  let workStart = template.work_start_time?.substring(0, 5)
  let workEnd = template.work_end_time?.substring(0, 5)

  if (dayRule && dayRule.enabled) {
    const targetIn = parse(dayRule.clock_in)
    const targetOut = parse(dayRule.clock_out)
    const grace = parseInt(dayRule.grace_minutes || 0)
    const type = dayRule.grace_type || '-/+'
    workStart = dayRule.clock_in.substring(0, 5)
    workEnd = dayRule.clock_out.substring(0, 5)

    if (dayRule.grace_enabled) {
      inStart = targetIn - ((type === '-' || type === '-/+') ? grace : 0)
      inEnd = targetIn + ((type === '+' || type === '-/+') ? grace : 0)
      outStart = targetOut - ((type === '-' || type === '-/+') ? grace : 0)
      outEnd = targetOut + ((type === '+' || type === '-/+') ? grace : 0)
    } else {
      inStart = inEnd = targetIn
      outStart = outEnd = targetOut
    }
  } else {
    inStart = parse(template.clock_in_start || template.work_start_time)
    inEnd = parse(template.clock_in_end || template.work_start_time)
    outStart = parse(template.clock_out_start || template.work_end_time)
    outEnd = parse(template.clock_out_end || template.work_end_time)
  }

  return {
    inStart,
    inEnd,
    outStart,
    outEnd,
    workStart,
    workEnd,
    currentMinutes,
    isWithinInWindow: currentMinutes >= inStart && currentMinutes <= inEnd,
    isWithinOutWindow: currentMinutes >= outStart && currentMinutes <= outEnd,
    formatTime
  }
}
export const calculateAttendanceStatus = (clockIn, clockOut, expectedHours, workStart, schedule = null) => {
  if (!clockIn) return 'absent'
  
  const parse = (t) => {
    if (!t) return 0
    const [h, m] = t.split(':').map(Number)
    return h * 60 + (m || 0)
  }
  
  const inMin = parse(clockIn)
  const outMin = clockOut ? parse(clockOut) : inMin
  const startMin = parse(workStart)
  
  let effectiveOut = outMin
  if (effectiveOut < inMin) effectiveOut += 1440
  
  const hoursWorked = Math.max(0, (effectiveOut - inMin) / 60)
  const halfExpected = expectedHours / 2
  const lateMinutes = Math.max(0, inMin - startMin)
  const expectedMinutes = expectedHours * 60
  const undertimeMinutes = Math.max(0, expectedMinutes - (effectiveOut - inMin))
  
  // Check if grace period applies
  let graceCovered = false
  if (schedule?.template?.day_rules) {
    const dayOfWeek = new Date().getDay()
    const dayRule = schedule.template.day_rules.find(r => r.day === dayOfWeek)
    
    if (dayRule && dayRule.grace_enabled) {
      const graceMinutes = parseInt(dayRule.grace_minutes || 0)
      const graceType = dayRule.grace_type || '-/+'
      
      // Check if this deviation is covered by grace
      if (lateMinutes <= graceMinutes && (graceType === '+' || graceType === '-/+')) {
        graceCovered = true
      } else if (undertimeMinutes <= graceMinutes && (graceType === '-' || graceType === '-/+')) {
        graceCovered = true
      }
    }
  }

  // If grace covers the deviation, return completed
  if (graceCovered) return 'completed'

  if (hoursWorked > expectedHours) return 'overtime'
  if (hoursWorked >= halfExpected && hoursWorked < expectedHours) return 'half_day'
  if (hoursWorked < halfExpected) return 'undertime'
  
  return lateMinutes > 0 ? 'late' : 'completed'
}
