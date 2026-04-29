
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
