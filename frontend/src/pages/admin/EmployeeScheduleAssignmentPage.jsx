import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { format, startOfWeek, endOfWeek } from 'date-fns'
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
} from '../../api/queries'
import { PageHeader, ConfirmModal } from '../../components/ui/index.jsx'
import { CalendarDays, Plus, Edit2, Trash2 } from 'lucide-react'

const getWeekRange = (weekOffset = 0) => {
  const today = new Date()
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
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({
    employee_id: '',
    schedule_template_id: '',
    ...getWeekRange(0),
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

  const createMutation = useMutation({
    mutationFn: createEmployeeSchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
      resetForm()
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateEmployeeSchedule(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
      resetForm()
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
      ...getWeekRange(0),
    })
    setEditingId(null)
  }

  const setThisWeek = () => {
    const thisWeek = getWeekRange(0)
    setFormData(prev => ({
      ...prev,
      ...thisWeek,
    }))
  }

  const setNextWeek = () => {
    const nextWeek = getWeekRange(1)
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
      createMutation.mutate({
        employee_id: parseInt(formData.employee_id),
        schedule_template_id: parseInt(formData.schedule_template_id),
        start_date: formData.start_date,
        end_date: formData.end_date,
      })
    }
  }

  const handleEdit = (schedule) => {
    setEditingId(schedule.id)
    setFormData({
      employee_id: schedule.employee_id,
      schedule_template_id: schedule.schedule_template_id,
      start_date: schedule.start_date,
      end_date: schedule.end_date,
    })
  }

  const handleDelete = (schedule) => {
    const employeeName = getEmployeeName(schedule.employee_id)
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

  const getEmployeeName = (employeeId) => {
    const emp = employeeList.find(e => e.id === Number(employeeId))
    return emp ? `${emp.first_name} ${emp.last_name}` : 'Unknown'
  }

  const employeeList = employeeResponse?.data ?? []
  const activeSchedules = schedulesResponse?.data?.filter(s => s.status === 'active') ?? []

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
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <CalendarDays size={14} className="text-brand-600" /> Active Schedules
        </h2>
        {activeSchedules.length === 0 ? (
          <p className="py-6 text-center text-gray-400 text-sm">No active schedules assigned</p>
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
              {activeSchedules.map((schedule) => (
                <tr key={schedule.id} className="hover:bg-gray-50">
                  <td className="py-2.5 pr-4 font-medium text-gray-900 text-sm">
                    {getEmployeeName(schedule.employee_id)}
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
                    >
                        <Edit2 size={14} />
                    </button>
                    <button
                      onClick={() => handleDelete(schedule)}
                      disabled={deleteMutation.isPending}
                        className="btn-ghost p-1.5 text-red-500 hover:bg-red-50 disabled:opacity-50"
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
