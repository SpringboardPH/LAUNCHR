export function formatTime(timeString) {
  if (!timeString) return ''
  // Assuming timeString is in HH:MM:SS format
  if (timeString.length === 8) {
    return timeString.substring(0, 5) // Return HH:MM
  }
  return timeString
}

export function parseTimeValue(timeString) {
  // Parse HH:MM to HH:MM:SS for storage
  if (!timeString) return ''
  if (timeString.length === 5) {
    return `${timeString}:00` // HH:MM -> HH:MM:00
  }
  return timeString
}

export function timeToMinutes(timeString) {
  if (!timeString) return 0
  const parts = timeString.split(':')
  const hours = parseInt(parts[0], 10) || 0
  const minutes = parseInt(parts[1], 10) || 0
  return hours * 60 + minutes
}

export function minutesToTime(minutes) {
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60
  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:00`
}
