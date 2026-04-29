import React, { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { 
  calendarEventKeys, 
  getCalendarEvents, 
  getCalendarEventTypes,
  createCalendarEvent,
  updateCalendarEvent,
  deleteCalendarEvent,
  importCalendarEvents,
  exportCalendarEvents,
} from '../../api/queries'
import { PageHeader, PageSpinner, Modal, FormField } from '../../components/ui'
import { Calendar } from '../../components/calendar/Calendar'
import { useAuth } from '../../store/AuthContext'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { format } from 'date-fns'
import { Calendar as CalendarIcon, Trash2, Plus, Download, Upload } from 'lucide-react'

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
  const fileInputRef = useRef(null)
  const [selectedEvent, setSelectedEvent] = useState(null)
  const [selectedDate, setSelectedDate] = useState(null)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [isDayModalOpen, setIsDayModalOpen] = useState(false)
  const [isEditMode, setIsEditMode] = useState(false)
  const [isImportModalOpen, setIsImportModalOpen] = useState(false)
  const [importFile, setImportFile] = useState(null)
  const [importResult, setImportResult] = useState(null)
  const [isUpdateScopeModalOpen, setIsUpdateScopeModalOpen] = useState(false)
  const [isDeleteScopeModalOpen, setIsDeleteScopeModalOpen] = useState(false)
  const [pendingUpdateData, setPendingUpdateData] = useState(null)
  const [selectedUpdateScope, setSelectedUpdateScope] = useState('single')
  const [selectedDeleteScope, setSelectedDeleteScope] = useState('single')

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
    mutationFn: ({ id, data, updateScope }) => updateCalendarEvent(id, data, updateScope),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
      setIsModalOpen(false)
      setSelectedEvent(null)
      reset()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: ({ id, deleteScope }) => deleteCalendarEvent(id, deleteScope),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
      setIsModalOpen(false)
      setSelectedEvent(null)
      setIsDeleteScopeModalOpen(false)
    },
  })

  const importMutation = useMutation({
    mutationFn: importCalendarEvents,
    onSuccess: (data) => {
      setImportResult(data.data)
      queryClient.invalidateQueries({ queryKey: calendarEventKeys.all })
    },
  })

  const handleExport = async () => {
    try {
      const response = await exportCalendarEvents()
      // Create a blob URL and trigger download
      const url = window.URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', 'holidays.csv')
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Export failed:', error)
      alert('Failed to export holidays')
    }
  }

  const handleImportFile = (event) => {
    const file = event.target.files?.[0]
    if (file) {
      setImportFile(file)
    }
  }

  const handleImportSubmit = () => {
    if (!importFile) {
      alert('Please select a file')
      return
    }
    importMutation.mutate(importFile)
  }

  const closeImportModal = () => {
    setIsImportModalOpen(false)
    setImportFile(null)
    setImportResult(null)
  }

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
      // If type exists and is_recurring_annual is true, it's recurring
      const isRecurring = selectedEvent.type?.is_recurring_annual
      
      if (isRecurring) {
        // Show update scope modal
        console.log('Recurring event detected, opening update modal')
        setPendingUpdateData(data)
        setSelectedUpdateScope('future')
        setIsUpdateScopeModalOpen(true)
      } else {
        // Single instance, just update normally
        console.log('Single instance event, updating directly')
        updateMutation.mutate({ id: selectedEvent.id, data, updateScope: 'single' })
      }
    } else {
      createMutation.mutate(data)
    }
  }

  const handleConfirmUpdate = () => {
    if (pendingUpdateData && selectedEvent) {
      updateMutation.mutate({
        id: selectedEvent.id,
        data: pendingUpdateData,
        updateScope: selectedUpdateScope,
      })
      setIsUpdateScopeModalOpen(false)
      setPendingUpdateData(null)
    }
  }

  const handleDelete = () => {
    // If type exists and is_recurring_annual is true, it's recurring
    const isRecurring = selectedEvent.type?.is_recurring_annual

    if (isRecurring) {
      setSelectedDeleteScope('single')
      setIsDeleteScopeModalOpen(true)
    } else {
      if (window.confirm('Are you sure you want to delete this event?')) {
        deleteMutation.mutate({ id: selectedEvent.id, deleteScope: 'single' })
      }
    }
  }

  const handleConfirmDelete = () => {
    if (selectedEvent) {
      deleteMutation.mutate({
        id: selectedEvent.id,
        deleteScope: selectedDeleteScope,
      })
    }
  }

  if (eventsLoading) return <PageSpinner />

  return (
    <div className="space-y-6">
      <PageHeader 
        title={readOnly ? "Company Calendar" : "Manage Company Calendar"} 
        description={readOnly ? "View upcoming company events and holidays." : "Add and manage company-wide events and holidays."}
        action={canManage && (
          <div className="flex gap-2">
            <button
              onClick={handleExport}
              className="btn-secondary flex items-center gap-2 text-sm"
            >
              <Download size={16} />
              Export
            </button>
            <button
              onClick={() => setIsImportModalOpen(true)}
              className="btn-primary flex items-center gap-2 text-sm"
            >
              <Upload size={16} />
              Import
            </button>
          </div>
        )}
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

      {/* Update Scope Modal */}
      <Modal
        open={isUpdateScopeModalOpen}
        onClose={() => {
          setIsUpdateScopeModalOpen(false)
          setPendingUpdateData(null)
        }}
        title="Update Recurring Event"
        size="sm"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            This event is part of a recurring annual series. How would you like to apply these changes?
          </p>
          
          <div className="space-y-2">
            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-blue-50">
              <input
                type="radio"
                name="updateScope"
                value="single"
                checked={selectedUpdateScope === 'single'}
                onChange={(e) => setSelectedUpdateScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">Just this year</p>
                <p className="text-xs text-gray-500">Only update {selectedEvent?.event_date}</p>
              </div>
            </label>

            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-blue-50">
              <input
                type="radio"
                name="updateScope"
                value="future"
                checked={selectedUpdateScope === 'future'}
                onChange={(e) => setSelectedUpdateScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">This and future years</p>
                <p className="text-xs text-gray-500">Update from {selectedEvent?.event_date} onwards</p>
              </div>
            </label>

            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-blue-50">
              <input
                type="radio"
                name="updateScope"
                value="all"
                checked={selectedUpdateScope === 'all'}
                onChange={(e) => setSelectedUpdateScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">All occurrences</p>
                <p className="text-xs text-gray-500">Update all years of this event</p>
              </div>
            </label>
          </div>

          <div className="flex gap-3 pt-4">
            <button
              onClick={() => {
                setIsUpdateScopeModalOpen(false)
                setPendingUpdateData(null)
              }}
              className="btn-secondary flex-1"
            >
              Cancel
            </button>
            <button
              onClick={handleConfirmUpdate}
              className="btn-primary flex-1"
              disabled={updateMutation.isPending}
            >
              {updateMutation.isPending ? 'Updating...' : 'Confirm Update'}
            </button>
          </div>
        </div>
      </Modal>

      {/* Delete Scope Modal */}
      <Modal
        open={isDeleteScopeModalOpen}
        onClose={() => {
          setIsDeleteScopeModalOpen(false)
        }}
        title="Delete Recurring Event"
        size="sm"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            This event is part of a recurring annual series. How would you like to delete?
          </p>
          
          <div className="space-y-2">
            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-red-50">
              <input
                type="radio"
                name="deleteScope"
                value="single"
                checked={selectedDeleteScope === 'single'}
                onChange={(e) => setSelectedDeleteScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">Just this year</p>
                <p className="text-xs text-gray-500">Only delete {selectedEvent?.event_date}</p>
              </div>
            </label>

            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-red-50">
              <input
                type="radio"
                name="deleteScope"
                value="future"
                checked={selectedDeleteScope === 'future'}
                onChange={(e) => setSelectedDeleteScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">This and future years</p>
                <p className="text-xs text-gray-500">Delete from {selectedEvent?.event_date} onwards</p>
              </div>
            </label>

            <label className="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-red-50">
              <input
                type="radio"
                name="deleteScope"
                value="all"
                checked={selectedDeleteScope === 'all'}
                onChange={(e) => setSelectedDeleteScope(e.target.value)}
                className="h-4 w-4"
              />
              <div>
                <p className="text-sm font-medium text-gray-900">All occurrences</p>
                <p className="text-xs text-gray-500">Delete all years of this event</p>
              </div>
            </label>
          </div>

          <div className="flex gap-3 pt-4">
            <button
              onClick={() => {
                setIsDeleteScopeModalOpen(false)
              }}
              className="btn-secondary flex-1"
            >
              Cancel
            </button>
            <button
              onClick={handleConfirmDelete}
              className="btn-ghost text-red-600 hover:bg-red-50 border border-red-200 flex-1"
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Confirm Delete'}
            </button>
          </div>
        </div>
      </Modal>

      {/* Import Modal */}
      <Modal
        open={isImportModalOpen}
        onClose={closeImportModal}
        title="Import Holidays"
        size="md"
      >
        <div className="space-y-4">
          {!importResult ? (
            <>
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                <p className="text-sm text-blue-900">
                  Upload a CSV or JSON file with your company holidays.{' '}
                  <a 
                    href="data:text/plain,date,title,type_name,description,is_recurring%0A2025-01-01,New Year's Day,Holiday,,yes%0A2025-12-25,Christmas Day,Holiday,,yes%0A2025-02-25,EDSA Revolution Day,Holiday,,yes" 
                    download="holidays-template.csv"
                    className="text-blue-600 hover:underline font-semibold"
                  >
                    Download CSV template
                  </a>
                </p>
                <p className="text-xs text-blue-800 mt-2">
                  Tip: Mark event types as recurring (yes) once, and all future imports with that type will automatically span 10 years.
                </p>
              </div>

              <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-900">
                <p className="font-semibold mb-2">Column Guide:</p>
                <ul className="space-y-1">
                  <li><strong>date:</strong> YYYY-MM-DD format (e.g., 2025-12-25)</li>
                  <li><strong>title:</strong> Holiday name (required, e.g., "Christmas Day")</li>
                  <li><strong>type_name:</strong> Event type to use or create (optional, defaults to "Holiday")</li>
                  <li><strong>description:</strong> Additional notes (optional)</li>
                  <li><strong>is_recurring:</strong> "yes" to mark this event TYPE as recurring annually for 10 years, "no" for one-time events</li>
                </ul>
              </div>

              <div>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".csv,.json,.txt"
                  onChange={handleImportFile}
                  className="hidden"
                />
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="w-full flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-gray-300 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition-colors"
                >
                  <Upload size={18} />
                  <span className="text-sm font-medium">
                    {importFile ? importFile.name : 'Select file to upload'}
                  </span>
                </button>
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  onClick={closeImportModal}
                  className="btn-secondary"
                >
                  Cancel
                </button>
                <button
                  onClick={handleImportSubmit}
                  disabled={!importFile || importMutation.isPending}
                  className="btn-primary"
                >
                  {importMutation.isPending ? 'Importing...' : 'Import'}
                </button>
              </div>
            </>
          ) : (
            <>
              <div className={importResult.errors.length === 0 ? 'bg-green-50 border border-green-200 rounded-lg p-3' : 'bg-yellow-50 border border-yellow-200 rounded-lg p-3'}>
                <div className="space-y-2">
                  <p className={importResult.errors.length === 0 ? 'font-semibold text-green-900' : 'font-semibold text-yellow-900'}>
                    ✓ Imported {importResult.created} holiday(ies)
                  </p>
                  {importResult.skipped > 0 && (
                    <p className={importResult.errors.length === 0 ? 'text-sm text-green-800' : 'text-sm text-yellow-800'}>
                      ⚠ Skipped {importResult.skipped} entries
                    </p>
                  )}
                </div>
              </div>

              {importResult.errors.length > 0 && (
                <div className="bg-red-50 border border-red-200 rounded-lg p-3 max-h-48 overflow-y-auto">
                  <p className="text-xs font-semibold text-red-800 mb-2">Errors:</p>
                  <ul className="space-y-1 text-xs text-red-700">
                    {importResult.errors.map((error, idx) => (
                      <li key={idx}>• {error}</li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="pt-4">
                <button
                  onClick={closeImportModal}
                  className="w-full btn-primary"
                >
                  Done
                </button>
              </div>
            </>
          )}
        </div>
      </Modal>
    </div>
  )
}
