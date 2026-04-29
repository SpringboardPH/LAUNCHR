function timeToMinutes(timeString) {
  if (!timeString) return 0
  const parts = timeString.split(':')
  const hours = parseInt(parts[0], 10) || 0
  const minutes = parseInt(parts[1], 10) || 0
  return hours * 60 + minutes
}

export const calculateHoursWorked = (clockInTime, clockOutTime) => {
  if (!clockInTime || !clockOutTime) return '—'
  
  try {
    const inMinutes = timeToMinutes(clockInTime)
    const outMinutes = timeToMinutes(clockOutTime)
    const diffMinutes = outMinutes - inMinutes
    
    if (diffMinutes < 0) return '—'
    
    const hours = Math.floor(diffMinutes / 60)
    const minutes = Math.round(diffMinutes % 60)
    
    return `${hours}h ${minutes}m`
  } catch {
    return '—'
  }
}
