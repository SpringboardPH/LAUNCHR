import React, { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { 
  calendarEventKeys, 
  getCalendarEvents, 
  getCalendarEventTypes,
  createCalendarEvent,
  updateCalendarEvent,
  deleteCalendarEvent
} from '../../api/queries'
import { PageHeader, PageSpinner, Modal, FormField } from '../../components/ui'
import { Calendar } from '../../components/calendar/Calendar'
import { useAuth } from '../../store/AuthContext'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { format } from 'date-fns'
import { Calendar as CalendarIcon, Info, Trash2, Plus } from 'lucide-react'

const eventSchema = z.object({
  title: z.string().min(1, 'Title is required').max(200),
  calendar_event_type_id: z.string().min(1, 'Type is required'),
  event_date: z.string().min(1, 'Start date is required'),
  end_date: z.string().min(1, 'End date is required'),
  description: z.string().max(1000).optional().nullable(),
})

function CalendarLegend({ types = [] }) {
  if (!types.length) return null
  return (
    <div className="card p-4">
      <h3 className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Legend</h3>
      <div className="flex flex-wrap gap-x-6 gap-y-3">
        {types.map(type => (
          <div key={type.id} className="flex items-center gap-2">
            <div className="w-3 h-3 rounded-full" style={{ backgroundColor: type.color }} />
            <div className="flex flex-col">
              <span className="text-xs font-semibold text-gray-700">{type.name}</span>
              <span className="text-[10px] text-gray-500 uppercase tracking-tight">
                {type.counts_as_absence ? 'Counts as absence' : 'Non-working / Holiday'}
              </span>
            </div>
          </div>
        ))}
        {/* Manual Leave Entry */}
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-black" />
          <div className="flex flex-col">
            <span className="text-xs font-semibold text-gray-700">Approved Leave</span>
            <span className="text-[10px] text-gray-500 uppercase tracking-tight">Personal time off</span>
          </div>
        </div>
      </div>
    </div>
  )
}

export default function CalendarPage({ readOnly = false }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [selectedEvent, setSelectedEvent] = useState(null)
  const [selectedDate, setSelectedDate] = useState(null)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [isDayModalOpen, setIsDayModalOpen] = useState(false)
  const [isEditMode, setIsEditMode] = useState(false)

  // Capability check: User must be HR/Admin AND page must NOT be in readOnly mode
  const canManage = !readOnly && ['admin', 'hr'].includes(user?.role)

  const { data: events, isLoading: eventsLoading } = useQuery({
    queryKey: calendarEventKeys.all,
    queryFn: () => getCalendarEvents(),
  })

  const { data: eventTypes } = useQuery({
    queryKey: ['calendar-event-types'],
    queryFn: () => getCalendarEventTypes(),
  })

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(eventSchema),
  })

  const createMutation = useMutation({
    mutationFn: createCalendarEvent,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
      setIsModalOpen(false)
      reset()
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateCalendarEvent(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
      setIsModalOpen(false)
      setSelectedEvent(null)
      reset()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCalendarEvent,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
      setIsModalOpen(false)
      setSelectedEvent(null)
    },
  })

  const getEventsForDate = (date) => {
    if (!events || !date) return []
    return events.filter(event => {
      const start = new Date(event.event_date)
      const end = new Date(event.end_date || event.event_date)
      start.setHours(0,0,0,0)
      end.setHours(0,0,0,0)
      const current = new Date(date)
      current.setHours(0,0,0,0)
      return current >= start && current <= end
    })
  }

  const handleAddEvent = (date) => {
    if (!canManage) return
    setIsEditMode(false)
    setSelectedEvent(null)
    setIsDayModalOpen(false)
    const formattedDate = format(date, 'yyyy-MM-dd')
    reset({
      event_date: formattedDate,
      end_date: formattedDate,
      title: '',
      calendar_event_type_id: eventTypes?.[0]?.id?.toString() || '',
      description: '',
    })
    setIsModalOpen(true)
  }

  const handleDateClick = (date) => {
    const dayEvents = getEventsForDate(date)
    setSelectedDate(date)
    
    // Only open day modal if there are events or if user can add
    if (dayEvents.length > 0 || canManage) {
      setIsDayModalOpen(true)
    }
  }

  const handleEventClick = (event) => {
    setSelectedEvent(event)
    setIsDayModalOpen(false)
    
    // Management is only possible if user has permission AND it's not a leave
    if (canManage && !event.is_leave) {
      setIsEditMode(true)
      reset({
        title: event.title,
        calendar_event_type_id: event.calendar_event_type_id?.toString(),
        event_date: event.event_date,
        end_date: event.end_date || event.event_date,
        description: event.description || '',
      })
      setIsModalOpen(true)
    } else {
      setIsEditMode(false)
      setIsModalOpen(true)
    }
  }

  const onSubmit = (data) => {
    if (isEditMode && selectedEvent) {
      updateMutation.mutate({ id: selectedEvent.id, data })
    } else {
      createMutation.mutate(data)
    }
  }

  const handleDelete = () => {
    if (window.confirm('Are you sure you want to delete this event?')) {
      deleteMutation.mutate(selectedEvent.id)
    }
  }

  if (eventsLoading) return <PageSpinner />

  return (
    <div className="space-y-6">
      <PageHeader 
        title={readOnly ? "Company Calendar" : "Manage Company Calendar"} 
        description={readOnly ? "View upcoming company events and holidays." : "Add and manage company-wide events and holidays."}
      />

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div className="lg:col-span-3">
          <Calendar 
            events={events || []} 
            onDateClick={handleDateClick}
            onEventClick={handleEventClick}
            onAddEvent={handleAddEvent}
            canAdd={canManage}
          />
        </div>
        <div className="lg:col-span-1">
          <CalendarLegend types={eventTypes || []} />
        </div>
      </div>

      {/* Day View Modal */}
      <Modal
        open={isDayModalOpen}
        onClose={() => setIsDayModalOpen(false)}
        title={selectedDate ? format(selectedDate, 'MMMM d, yyyy') : 'Day View'}
        size="sm"
      >
        <div className="space-y-4">
          <div className="space-y-2">
            {getEventsForDate(selectedDate).map(event => (
              <button
                key={event.id}
                onClick={() => handleEventClick(event)}
                className="w-full flex items-center gap-3 p-3 rounded-xl border border-gray-100 hover:bg-gray-50 transition-colors text-left"
              >
                <div 
                  className="w-3 h-3 rounded-full shrink-0" 
                  style={{ backgroundColor: event.type?.color }}
                />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-bold text-gray-900 truncate">{event.title}</p>
                  <p className="text-[10px] text-gray-500 uppercase tracking-tight">{event.type?.name}</p>
                </div>
              </button>
            ))}
            
            {getEventsForDate(selectedDate).length === 0 && (
              <p className="text-sm text-gray-400 italic py-4 text-center">No events scheduled for this day.</p>
            )}
          </div>

          {canManage && (
            <button
              onClick={() => handleAddEvent(selectedDate)}
              className="btn-secondary w-full flex items-center justify-center gap-2"
            >
              <Plus size={16} />
              Add Event
            </button>
          )}

          <div className="flex justify-end pt-2">
            <button onClick={() => setIsDayModalOpen(false)} className="btn-ghost text-xs">Close</button>
          </div>
        </div>
      </Modal>

      {/* Event Details / Form Modal */}
      <Modal
        open={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title={
          selectedEvent?.is_leave 
            ? 'Leave Details' 
            : (canManage ? (isEditMode ? 'Edit Event' : 'Add New Event') : 'Event Details')
        }
        size="md"
      >
        {canManage && !selectedEvent?.is_leave ? (
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <FormField label="Event Title" error={errors.title?.message} required>
              <input 
                {...register('title')} 
                className="input" 
                placeholder="e.g. Christmas Day, Team Building"
              />
            </FormField>

            <FormField label="Event Type" error={errors.calendar_event_type_id?.message} required>
              <select {...register('calendar_event_type_id')} className="input">
                <option value="">Select a type</option>
                {eventTypes?.map(type => (
                  <option key={type.id} value={type.id}>{type.name}</option>
                ))}
              </select>
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label="Start Date" error={errors.event_date?.message} required>
                <input {...register('event_date')} type="date" className="input" />
              </FormField>
              <FormField label="End Date" error={errors.end_date?.message} required>
                <input {...register('end_date')} type="date" className="input" />
              </FormField>
            </div>

            <FormField label="Description" error={errors.description?.message}>
              <textarea 
                {...register('description')} 
                className="input min-h-[100px]" 
                placeholder="Details about the event..."
              />
            </FormField>

            <div className="flex justify-between pt-4">
              {isEditMode && (
                <button
                  type="button"
                  onClick={handleDelete}
                  className="btn-ghost text-red-600 hover:bg-red-50 flex items-center gap-1.5"
                >
                  <Trash2 size={16} />
                  Delete
                </button>
              )}
              <div className="flex gap-3 ml-auto">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="btn-secondary"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="btn-primary"
                >
                  {isSubmitting ? 'Saving...' : (isEditMode ? 'Update Event' : 'Create Event')}
                </button>
              </div>
            </div>
          </form>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <div 
                className="w-10 h-10 rounded-lg flex items-center justify-center text-white"
                style={{ backgroundColor: selectedEvent?.type?.color }}
              >
                <CalendarIcon size={20} />
              </div>
              <div>
                <h3 className="text-base font-bold text-gray-900">{selectedEvent?.title}</h3>
                <p className="text-xs text-gray-500">
                  {selectedEvent?.type?.name} • {
                    selectedEvent?.event_date === selectedEvent?.end_date 
                    ? selectedEvent?.event_date 
                    : `${selectedEvent?.event_date} to ${selectedEvent?.end_date}`
                  }
                </p>
              </div>
            </div>
            
            {selectedEvent?.description && (
              <div className="bg-gray-50 rounded-lg p-4">
                <p className="text-sm text-gray-600 whitespace-pre-wrap">{selectedEvent?.description}</p>
              </div>
            )}

            {!selectedEvent?.description && (
              <p className="text-sm text-gray-400 italic">No description provided for this event.</p>
            )}

            <div className="flex justify-end pt-4">
              <button
                onClick={() => setIsModalOpen(false)}
                className="btn-primary"
              >
                Close
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
