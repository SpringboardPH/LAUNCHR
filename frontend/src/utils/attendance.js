import { parseISO, format } from 'date-fns'

export const getClockWindow = (schedule) => {
  const template = schedule?.template
  if (!template) return null

  const now = new Date()
  const today = now.getDay()
  const dayRule = (template.day_rules || []).find(r => r.day === today)

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

  const currentMinutes = now.getHours() * 60 + now.getMinutes()

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
