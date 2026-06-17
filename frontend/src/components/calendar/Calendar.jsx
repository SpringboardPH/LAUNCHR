import React, { useState } from 'react'
import { 
  format, 
  startOfMonth, 
  endOfMonth, 
  startOfWeek, 
  endOfWeek, 
  eachDayOfInterval, 
  isSameMonth,
  addMonths,
  subMonths,
  isToday
} from 'date-fns'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react'
import clsx from 'clsx'

export function Calendar({ events = [], onDateClick, onEventClick, onAddEvent, canAdd = false }) {
  const [currentMonth, setCurrentMonth] = useState(new Date())

  const monthStart = startOfMonth(currentMonth)
  const monthEnd = endOfMonth(monthStart)
  const startDate = startOfWeek(monthStart)
  const endDate = endOfWeek(monthEnd)

  const calendarDays = eachDayOfInterval({
    start: startDate,
    end: endDate,
  })

  const nextMonth = () => setCurrentMonth(addMonths(currentMonth, 1))
  const prevMonth = () => setCurrentMonth(subMonths(currentMonth, 1))

  const getEventsForDay = (day) => {
    return events.filter(event => {
      const start = new Date(event.event_date)
      const end = new Date(event.end_date || event.event_date)
      // Normalize to start of day for comparison
      start.setHours(0, 0, 0, 0)
      end.setHours(0, 0, 0, 0)
      const current = new Date(day)
      current.setHours(0, 0, 0, 0)
      
      return current >= start && current <= end
    })
  }

  return (
    <div className="card overflow-hidden">
      {/* Calendar Header */}
      <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
        <div>
          <h2 className="text-lg font-bold text-gray-900">
            {format(currentMonth, 'MMMM yyyy')}
          </h2>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex items-center bg-gray-100 rounded-lg p-1">
            <button
              onClick={prevMonth}
              className="p-1.5 hover:bg-white hover:shadow-sm rounded-md transition-all text-gray-600"
            >
              <ChevronLeft size={18} />
            </button>
            <button
              onClick={nextMonth}
              className="p-1.5 hover:bg-white hover:shadow-sm rounded-md transition-all text-gray-600"
            >
              <ChevronRight size={18} />
            </button>
          </div>
          {canAdd && (
            <button 
              onClick={() => onAddEvent && onAddEvent(new Date())}
              className="btn-primary py-2 px-3 flex items-center gap-1.5"
            >
              <Plus size={16} />
              <span className="hidden sm:inline">Add Event</span>
            </button>
          )}
        </div>
      </div>

      {/* Weekdays Header */}
      <div className="grid grid-cols-7 border-b border-gray-100 bg-gray-50/50">
        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
          <div key={day} className="py-3 text-center text-xs font-bold text-gray-400 uppercase tracking-widest">
            {day}
          </div>
        ))}
      </div>

      {/* Calendar Grid */}
      <div className="grid grid-cols-7 auto-rows-[120px]">
        {calendarDays.map((day, idx) => {
          const dayEvents = getEventsForDay(day)
          const isCurrentMonth = isSameMonth(day, monthStart)
          const isClickable = dayEvents.length > 0 || canAdd
          
          return (
            <div
              key={day.toString()}
              onClick={() => isClickable && onDateClick && onDateClick(day)}
              className={clsx(
                'relative border-r border-b border-gray-100 p-2 transition-colors group',
                isClickable ? 'cursor-pointer hover:bg-brand-50/30' : 'cursor-default',
                !isCurrentMonth && 'bg-gray-50/30',
                idx % 7 === 6 && 'border-r-0'
              )}
            >
              <div className="flex items-center justify-between mb-1">
                <span className={clsx(
                  'text-sm font-semibold w-7 h-7 flex items-center justify-center rounded-full',
                  isToday(day) 
                    ? 'bg-brand-600 text-white' 
                    : isCurrentMonth ? 'text-gray-700' : 'text-gray-300'
                )}>
                  {format(day, 'd')}
                </span>
              </div>

              <div className="space-y-1 overflow-y-auto overflow-x-hidden max-h-[80px] scrollbar-hide">
                {dayEvents.map((event) => (
                  <div
                    key={event.id}
                    onClick={(e) => {
                      e.stopPropagation()
                      onEventClick && onEventClick(event)
                    }}
                    className="px-1.5 py-0.5 rounded text-[10px] font-medium truncate border shadow-sm transition-transform hover:scale-[1.02]"
                    style={{ 
                      backgroundColor: `${event.color ?? event.type?.color}20`, 
                      borderColor: event.color ?? event.type?.color,
                      color: event.color ?? event.type?.color
                    }}
                    title={event.title}
                  >
                    {event.title}
                  </div>
                ))}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
