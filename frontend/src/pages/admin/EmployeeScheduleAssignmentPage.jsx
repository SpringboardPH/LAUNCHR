import { useEffect, useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { format, startOfWeek, endOfWeek, parseISO, isBefore, startOfDay, isAfter } from 'date-fns'
import {
  employeeKeys,
  getEmployees,
  employeeScheduleKeys,
  getEmployeeSchedules,
  scheduleTemplateKeys,
  getScheduleTemplates,
  createEmployeeSchedule,
  updateEmployeeSchedule,
  deleteEmployeeSchedule,
  getSystemClock,
  systemClockKeys,
} from '../../api/queries'
import { PageHeader, ConfirmModal } from '../../components/ui/index.jsx'
import { CalendarDays, Plus, Edit2, Trash2 } from 'lucide-react'

const getApiErrorMessage = (error, fallbackMessage) => {
  return (
    error?.response?.data?.message ||
    error?.response?.data?.error ||
    fallbackMessage
  )
}

const getWeekRange = (baseDate, weekOffset = 0) => {
  const today = baseDate ?? new Date()
  const weekStart = startOfWeek(today, { weekStartsOn: 1 })
  const weekEnd = endOfWeek(today, { weekStartsOn: 1 })

  if (weekOffset === 0) {
    return {
      start_date: format(weekStart, 'yyyy-MM-dd'),
      end_date: format(weekEnd, 'yyyy-MM-dd'),
    }
  }

  const offsetDays = weekOffset * 7
  return {
    start_date: format(new Date(weekStart.getTime() + offsetDays * 24 * 60 * 60 * 1000), 'yyyy-MM-dd'),
    end_date: format(new Date(weekEnd.getTime() + offsetDays * 24 * 60 * 60 * 1000), 'yyyy-MM-dd'),
  }
}

const EmployeeScheduleAssignmentPage = () => {
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const [activeTab, setActiveTab] = useState('current')
  const [dismissedSuggestedSchedules, setDismissedSuggestedSchedules] = useState([])
  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
  })
  const baseDate = useMemo(
    () => (sysClock?.date ? parseISO(sysClock.date) : new Date()),
    [sysClock?.date]
  )
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({
    employee_id: '',
    schedule_template_id: '',
    ...getWeekRange(baseDate, 0),
  })
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })

  const { data: employeeResponse } = useQuery({
    queryKey: employeeKeys.all,
    queryFn: () => getEmployees({}),
  })

  const { data: templates = [] } = useQuery({
    queryKey: scheduleTemplateKeys.all,
    queryFn: getScheduleTemplates,
  })

  const { data: schedulesResponse } = useQuery({
    queryKey: employeeScheduleKeys.all,
    queryFn: () => getEmployeeSchedules({}),
  })

  useEffect(() => {
    const employeeId = searchParams.get('employee_id')
    if (employeeId) {
      setFormData(prev => ({ ...prev, employee_id: employeeId }))
    }
  }, [searchParams])

  useEffect(() => {
    if (editingId) return
    setFormData(prev => ({
      ...prev,
      ...getWeekRange(baseDate, 0),
    }))
  }, [baseDate])

  const createMutation = useMutation({
    mutationFn: createEmployeeSchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
      resetForm()
    },
    onError: (error) => {
      alert(getApiErrorMessage(error, 'Failed to assign schedule.'))
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateEmployeeSchedule(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
      resetForm()
    },
    onError: (error) => {
      alert(getApiErrorMessage(error, 'Failed to update schedule.'))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteEmployeeSchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
    },
  })

  const resetForm = () => {
    setFormData({
      employee_id: '',
      schedule_template_id: '',
      ...getWeekRange(baseDate, 0),
    })
    setEditingId(null)
  }

  const setThisWeek = () => {
    const thisWeek = getWeekRange(baseDate, 0)
    setFormData(prev => ({
      ...prev,
      ...thisWeek,
    }))
  }

  const setNextWeek = () => {
    const nextWeek = getWeekRange(baseDate, 1)
    setFormData(prev => ({
      ...prev,
      ...nextWeek,
    }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!formData.employee_id || !formData.schedule_template_id || !formData.start_date || !formData.end_date) {
      alert('All fields are required')
      return
    }

    if (editingId) {
      updateMutation.mutate({
        id: editingId,
        data: {
          schedule_template_id: parseInt(formData.schedule_template_id),
          start_date: formData.start_date,
          end_date: formData.end_date,
          status: 'active',
        },
      })
    } else {
      // If an active schedule already exists for this employee/week, update it instead
      // of creating a duplicate. This keeps edits working even when rows are shown as defaults.
      const overlappingSavedSchedule = allActiveSchedules.find((schedule) => {
        const sameEmployee = Number(schedule.employee_id) === Number(formData.employee_id)
        if (!sameEmployee) return false

        const existingStart = parseISO(schedule.start_date)
        const existingEnd = parseISO(schedule.end_date)
        const targetStart = parseISO(formData.start_date)
        const targetEnd = parseISO(formData.end_date)

        return !isBefore(existingEnd, targetStart) && !isAfter(existingStart, targetEnd)
      })

      if (overlappingSavedSchedule?.id) {
        updateMutation.mutate({
          id: overlappingSavedSchedule.id,
          data: {
            schedule_template_id: parseInt(formData.schedule_template_id),
            start_date: formData.start_date,
            end_date: formData.end_date,
            status: 'active',
          },
        })
        return
      }

      createMutation.mutate({
        employee_id: parseInt(formData.employee_id),
        schedule_template_id: parseInt(formData.schedule_template_id),
        start_date: formData.start_date,
        end_date: formData.end_date,
      })
    }
  }

  const handleEdit = (schedule) => {
    setEditingId(schedule.isSuggested ? null : schedule.id)
    setFormData({
      employee_id: schedule.employee_id,
      schedule_template_id: schedule.schedule_template_id,
      start_date: schedule.start_date,
      end_date: schedule.end_date,
    })
  }

  const handleDelete = (schedule) => {
    if (schedule.isSuggested) {
      setDismissedSuggestedSchedules(prev => [...new Set([...prev, schedule.id])])
      return
    }

    const employeeName = getEmployeeName(schedule.employee_id, schedule.employee)
    const templateName = getTemplateName(schedule.schedule_template_id)
    setConfirmConfig({
      open: true,
      title: 'Remove Schedule',
      message: `Remove schedule assignment (${templateName}) for ${employeeName}?`,
      onConfirm: () => deleteMutation.mutate(schedule.id),
      type: 'danger'
    })
  }

  const getTemplateName = (templateId) => {
    return templates.find(t => t.id === Number(templateId))?.name || 'Unknown'
  }

  const getEmployeeName = (employeeId, scheduleEmployee = null) => {
    if (scheduleEmployee?.first_name && scheduleEmployee?.last_name) {
      return `${scheduleEmployee.first_name} ${scheduleEmployee.last_name}`
    }

    const emp = employeeList.find(e => e.id === Number(employeeId))
    return emp ? `${emp.first_name} ${emp.last_name}` : 'Unknown'
  }

  const employeeList = employeeResponse?.data ?? []
  const today = startOfDay(baseDate)
  const thisWeekRange = getWeekRange(baseDate, 0)
  const nextWeekRange = getWeekRange(baseDate, 1)
  const thisWeekStart = parseISO(thisWeekRange.start_date)
  const thisWeekEnd = parseISO(thisWeekRange.end_date)
  const nextWeekStart = parseISO(nextWeekRange.start_date)
  const nextWeekEnd = parseISO(nextWeekRange.end_date)
  const allActiveSchedules = schedulesResponse?.data?.filter((s) => {
    if (s.status !== 'active') return false
    if (!s.start_date || !s.end_date) return false
    return true
  }) ?? []

  const thisWeekSchedules = allActiveSchedules.filter((s) => {
    const start = parseISO(s.start_date)
    const end = parseISO(s.end_date)
    return !isBefore(end, thisWeekStart) && !isAfter(start, thisWeekEnd)
  })
  const previousSchedules = allActiveSchedules
    .filter((s) => isBefore(parseISO(s.end_date), thisWeekStart))
    .sort((a, b) => parseISO(b.end_date) - parseISO(a.end_date))
  const fallbackSchedulesByEmployee = new Map()
  previousSchedules.forEach((schedule) => {
    if (!fallbackSchedulesByEmployee.has(schedule.employee_id)) {
      fallbackSchedulesByEmployee.set(schedule.employee_id, schedule)
    }
  })
  const sourceSchedulesForDefaults = thisWeekSchedules.length > 0
    ? thisWeekSchedules
    : Array.from(fallbackSchedulesByEmployee.values())

  const currentWeekEmployeeIdsWithSavedSchedule = new Set(thisWeekSchedules.map(s => s.employee_id))
  const suggestedCurrentWeekSchedules = Array.from(fallbackSchedulesByEmployee.values())
    .filter((s) => !currentWeekEmployeeIdsWithSavedSchedule.has(s.employee_id))
    .map((s) => ({
      ...s,
      id: `suggested-current-${s.employee_id}-${thisWeekRange.start_date}`,
      start_date: thisWeekRange.start_date,
      end_date: thisWeekRange.end_date,
      isSuggested: true,
    }))
  const currentSchedulesWithDefaults = [
    ...thisWeekSchedules,
    ...suggestedCurrentWeekSchedules,
  ]
  const savedNextWeekSchedules = allActiveSchedules.filter((s) => {
    const start = parseISO(s.start_date)
    const end = parseISO(s.end_date)
    return !isBefore(end, nextWeekStart) && !isAfter(start, nextWeekEnd)
  })
  const futureSchedules = allActiveSchedules.filter((s) => isBefore(today, parseISO(s.start_date)))
  const nextWeekEmployeeIdsWithSavedSchedule = new Set(savedNextWeekSchedules.map(s => s.employee_id))
  const suggestedNextWeekSchedules = sourceSchedulesForDefaults
    .filter((s) => !nextWeekEmployeeIdsWithSavedSchedule.has(s.employee_id))
    .map((s) => ({
      ...s,
      id: `suggested-${s.employee_id}-${nextWeekRange.start_date}`,
      start_date: nextWeekRange.start_date,
      end_date: nextWeekRange.end_date,
      isSuggested: true,
    }))
  const futureSchedulesWithDefaults = [...savedNextWeekSchedules, ...suggestedNextWeekSchedules]
  const uniqueFutureSchedules = [
    ...futureSchedulesWithDefaults,
    ...futureSchedules.filter((s) => {
      const start = parseISO(s.start_date)
      return isAfter(start, nextWeekEnd)
    }),
  ]
  const shownSchedules = activeTab === 'future'
    ? uniqueFutureSchedules.filter((schedule) => !dismissedSuggestedSchedules.includes(schedule.id))
    : currentSchedulesWithDefaults.filter((schedule) => !dismissedSuggestedSchedules.includes(schedule.id))

  return (
    <div>
      <PageHeader
        title="Weekly Employee Schedules"
        description="Assign a pre-defined schedule to an employee for a specific week"
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig({ ...confirmConfig, open: false })}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />

      {/* Form */}
      <div className="card p-5 mb-5">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <Plus size={14} className="text-brand-600" />
          {editingId ? 'Edit Schedule Assignment' : 'Assign Schedule to Employee'}
        </h2>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Employee</label>
              <select
                value={formData.employee_id}
                onChange={(e) => setFormData({ ...formData, employee_id: e.target.value })}
                disabled={editingId}
                className="input disabled:bg-gray-100"
              >
                <option value="">Select an employee</option>
                {employeeList.map(emp => (
                  <option key={emp.id} value={emp.id}>
                    {emp.first_name} {emp.last_name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Schedule Template</label>
              <select
                value={formData.schedule_template_id}
                onChange={(e) => setFormData({ ...formData, schedule_template_id: e.target.value })}
                className="input"
              >
                <option value="">Select a template</option>
                {templates.map(template => (
                  <option key={template.id} value={template.id}>
                    {template.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Start Date (Monday)</label>
              <input
                type="date"
                value={formData.start_date}
                onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                className="input"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">End Date (Sunday)</label>
              <input
                type="date"
                value={formData.end_date}
                onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                className="input"
              />
            </div>
          </div>

          <div className="flex items-center justify-between gap-3 pt-2">
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={setThisWeek}
                className="btn-secondary"
              >
                This Week
              </button>
              <button
                type="button"
                onClick={setNextWeek}
                className="btn-secondary"
              >
                Next Week
              </button>
            </div>

            <div className="flex flex-wrap gap-2 justify-end">
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="btn-primary disabled:opacity-50"
            >
              {editingId ? 'Update Assignment' : 'Assign Schedule'}
            </button>
            <button
              type="button"
              onClick={resetForm}
              className="btn-secondary"
            >
              Cancel
            </button>
            </div>
          </div>
        </form>
      </div>

      {/* Active Schedules Table */}
      <div className="card p-5">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
          <CalendarDays size={14} className="text-brand-600" /> Active Schedules
          </h2>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setActiveTab('current')}
              className={activeTab === 'current' ? 'btn-primary text-xs px-3 py-1.5' : 'btn-secondary text-xs px-3 py-1.5'}
            >
              Current
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('future')}
              className={activeTab === 'future' ? 'btn-primary text-xs px-3 py-1.5' : 'btn-secondary text-xs px-3 py-1.5'}
            >
              Future Schedules
            </button>
          </div>
        </div>
        {shownSchedules.length === 0 ? (
          <p className="py-6 text-center text-gray-400 text-sm">
            {activeTab === 'future' ? 'No future schedules assigned' : 'No current schedules assigned'}
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead className="border-b border-gray-100">
              <tr>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Employee</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Template</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Week</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {shownSchedules.map((schedule) => (
                <tr key={schedule.id} className="hover:bg-gray-50">
                  <td className="py-2.5 pr-4 font-medium text-gray-900 text-sm">
                    {getEmployeeName(schedule.employee_id, schedule.employee)}
                  </td>
                  <td className="py-2.5 pr-4 text-gray-600 text-sm">
                    {getTemplateName(schedule.schedule_template_id)}
                  </td>
                  <td className="py-2.5 pr-4 text-gray-600 text-sm">
                    {format(new Date(schedule.start_date), 'MMM dd')} - {format(new Date(schedule.end_date), 'MMM dd, yyyy')}
                  </td>
                  <td className="py-2.5 pr-4">
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleEdit(schedule)}
                        className="btn-ghost p-1.5 text-brand-600 hover:bg-brand-50"
                        title="Edit"
                      >
                        <Edit2 size={14} />
                      </button>
                      <button
                        onClick={() => handleDelete(schedule)}
                        disabled={deleteMutation.isPending && !schedule.isSuggested}
                        className="btn-ghost p-1.5 text-red-500 hover:bg-red-50 disabled:opacity-50"
                        title="Delete"
                      >
                        <Trash2 size={14} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

export default EmployeeScheduleAssignmentPage
