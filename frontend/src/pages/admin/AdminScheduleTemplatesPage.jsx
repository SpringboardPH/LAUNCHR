import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  scheduleTemplateKeys,
  getScheduleTemplates,
  createScheduleTemplate,
  updateScheduleTemplate,
  deleteScheduleTemplate,
} from '../../api/queries'
import { PageHeader } from '../../components/ui/index.jsx'
import { CalendarRange, Plus, Edit2, Trash2 } from 'lucide-react'

const dayLabels = {
  0: 'Sunday',
  1: 'Monday',
  2: 'Tuesday',
  3: 'Wednesday',
  4: 'Thursday',
  5: 'Friday',
  6: 'Saturday',
}

const AdminScheduleTemplatesPage = () => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    work_days: [],
    clock_in_start: '08:45',
    clock_in_end: '18:15',
    clock_out_start: '18:00',
    clock_out_end: '18:15',
    start_time: '09:00',
    end_time: '18:00',
    work_start_time: '09:00',
    work_end_time: '18:00',
    late_threshold_minutes: 0,
    required_hours_per_day: 9,
    overtime_threshold_hours: 9,
    expected_hours_per_day: 8,
  })

  const { data: templates = [], isLoading } = useQuery({
    queryKey: scheduleTemplateKeys.all,
    queryFn: getScheduleTemplates,
  })

  const createMutation = useMutation({
    mutationFn: createScheduleTemplate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleTemplateKeys.all })
      resetForm()
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateScheduleTemplate(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleTemplateKeys.all })
      resetForm()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteScheduleTemplate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleTemplateKeys.all })
    },
  })

  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      work_days: [],
      clock_in_start: '08:45',
      clock_in_end: '18:15',
      clock_out_start: '18:00',
      clock_out_end: '18:15',
      start_time: '09:00',
      end_time: '18:00',
      work_start_time: '09:00',
      work_end_time: '18:00',
      late_threshold_minutes: 0,
      required_hours_per_day: 9,
      overtime_threshold_hours: 9,
      expected_hours_per_day: 9,
    })
    setEditingId(null)
  }

  const handleToggleDay = (day) => {
    setFormData(prev => ({
      ...prev,
      work_days: prev.work_days.includes(day)
        ? prev.work_days.filter(d => d !== day)
        : [...prev.work_days, day].sort(),
    }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!formData.name.trim() || formData.work_days.length === 0) {
      alert('Name and at least one work day are required')
      return
    }

    const payload = {
      ...formData,
      clock_in_start: `${formData.clock_in_start}:00`,
      clock_in_end: `${formData.clock_in_end}:00`,
      clock_out_start: `${formData.clock_out_start}:00`,
      clock_out_end: `${formData.clock_out_end}:00`,
      start_time: `${formData.start_time}:00`,
      end_time: `${formData.end_time}:00`,
      work_start_time: `${formData.work_start_time}:00`,
      work_end_time: `${formData.work_end_time}:00`,
    }

    if (editingId) {
      updateMutation.mutate({ id: editingId, data: payload })
    } else {
      createMutation.mutate(payload)
    }
  }

  const handleEdit = (template) => {
    setEditingId(template.id)
    setFormData({
      name: template.name,
      description: template.description || '',
      work_days: template.work_days || [],
      clock_in_start: template.clock_in_start?.substring(0, 5) || '08:45',
      clock_in_end: template.clock_in_end?.substring(0, 5) || '18:15',
      clock_out_start: template.clock_out_start?.substring(0, 5) || '18:00',
      clock_out_end: template.clock_out_end?.substring(0, 5) || '18:15',
      start_time: template.start_time?.substring(0, 5) || '09:00',
      end_time: template.end_time?.substring(0, 5) || '18:00',
      work_start_time: template.work_start_time?.substring(0, 5) || '09:00',
      work_end_time: template.work_end_time?.substring(0, 5) || '18:00',
      late_threshold_minutes: template.late_threshold_minutes ?? 0,
      required_hours_per_day: template.required_hours_per_day ?? 9,
      overtime_threshold_hours: template.overtime_threshold_hours ?? 9,
      expected_hours_per_day: template.expected_hours_per_day || 9,
    })
  }

  const handleDelete = (id) => {
    if (confirm('Delete this schedule template?')) {
      deleteMutation.mutate(id)
    }
  }

  if (isLoading) {
    return <div className="p-6">Loading...</div>
  }

  return (
    <div>
      <PageHeader
        title="Schedule Templates"
        description="Create and manage reusable work schedule templates for employees"
      />

      {/* Form */}
      <div className="card p-5 mb-5">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <Plus size={14} className="text-brand-600" />
          {editingId ? 'Edit Schedule Template' : 'Create New Schedule Template'}
        </h2>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="e.g., Morning Shift"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <input
              type="text"
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="e.g., Early morning shift with flexible end time"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-3">Work Days</label>
            <div className="flex gap-2 flex-wrap">
              {Object.entries(dayLabels).map(([day, label]) => (
                <label key={day} className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={formData.work_days.includes(parseInt(day))}
                    onChange={() => handleToggleDay(parseInt(day))}
                    className="w-4 h-4"
                  />
                  <span className="text-sm">{label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Clock In Start</label>
              <input
                type="time"
                value={formData.clock_in_start}
                onChange={(e) => setFormData({ ...formData, clock_in_start: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Clock In End</label>
              <input
                type="time"
                value={formData.clock_in_end}
                onChange={(e) => setFormData({ ...formData, clock_in_end: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Clock Out Start</label>
              <input
                type="time"
                value={formData.clock_out_start}
                onChange={(e) => setFormData({ ...formData, clock_out_start: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Clock Out End</label>
              <input
                type="time"
                value={formData.clock_out_end}
                onChange={(e) => setFormData({ ...formData, clock_out_end: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
              <input
                type="time"
                value={formData.start_time}
                onChange={(e) => setFormData({ ...formData, start_time: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">End Time</label>
              <input
                type="time"
                value={formData.end_time}
                onChange={(e) => setFormData({ ...formData, end_time: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Hours/Day</label>
              <input
                type="number"
                value={formData.expected_hours_per_day}
                onChange={(e) => setFormData({ ...formData, expected_hours_per_day: parseInt(e.target.value) })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                min="1"
                max="24"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Work Start Time</label>
              <input
                type="time"
                value={formData.work_start_time}
                onChange={(e) => setFormData({ ...formData, work_start_time: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Work End Time</label>
              <input
                type="time"
                value={formData.work_end_time}
                onChange={(e) => setFormData({ ...formData, work_end_time: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Late Threshold (minutes)</label>
              <input
                type="number"
                min="0"
                max="180"
                value={formData.late_threshold_minutes}
                onChange={(e) => setFormData({ ...formData, late_threshold_minutes: parseInt(e.target.value || '0') })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Required Hours/Day</label>
              <input
                type="number"
                min="1"
                max="24"
                value={formData.required_hours_per_day}
                onChange={(e) => setFormData({ ...formData, required_hours_per_day: parseInt(e.target.value || '0') })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Overtime Threshold (hours)</label>
              <input
                type="number"
                min="1"
                max="24"
                value={formData.overtime_threshold_hours}
                onChange={(e) => setFormData({ ...formData, overtime_threshold_hours: parseInt(e.target.value || '0') })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="flex gap-2 pt-4">
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {editingId ? 'Update Template' : 'Create Template'}
            </button>
            {editingId && (
              <button
                type="button"
                onClick={resetForm}
                className="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400"
              >
                Cancel
              </button>
            )}
          </div>
        </form>
      </div>

      {/* Templates Table */}
      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <CalendarRange size={14} className="text-brand-600" /> Templates
        </h2>
        {templates.length === 0 ? (
          <p className="py-6 text-center text-gray-400 text-sm">No schedule templates yet</p>
        ) : (
          <table className="w-full">
            <thead className="border-b border-gray-100">
              <tr>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Name</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Description</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Days</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Windows</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Rules</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {templates.map((template) => (
                <tr key={template.id} className="hover:bg-gray-50">
                  <td className="py-2.5 pr-4 text-xs text-gray-900 font-medium">{template.name}</td>
                  <td className="py-2.5 pr-4 text-xs text-gray-600">{template.description}</td>
                  <td className="py-2.5 pr-4">
                    <div className="flex gap-1 flex-wrap">
                      {template.work_days?.map(day => (
                        <span key={day} className="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded">
                          {dayLabels[day]?.substring(0, 3)}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="py-2.5 pr-4">
                    <div className="space-y-1">
                      <div className="text-xs text-gray-500">Clock in {template.clock_in_start?.substring(0, 5)} - {template.clock_in_end?.substring(0, 5)}</div>
                      <div className="text-xs text-gray-500">Clock out {template.clock_out_start?.substring(0, 5)} - {template.clock_out_end?.substring(0, 5)}</div>
                      <div className="text-xs text-gray-500">Work {template.work_start_time?.substring(0, 5)} - {template.work_end_time?.substring(0, 5)}</div>
                    </div>
                  </td>
                  <td className="py-2.5 pr-4">
                    <div className="space-y-1">
                      <div className="text-xs text-gray-500">Required {template.required_hours_per_day}h/day</div>
                      <div className="text-xs text-gray-500">Overtime {template.overtime_threshold_hours}h</div>
                      <div className="text-xs text-gray-500">Late threshold {template.late_threshold_minutes}m</div>
                    </div>
                  </td>
                  <td className="py-2.5 pr-4">
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleEdit(template)}
                        className="p-2 text-blue-600 hover:bg-blue-50 rounded transition"
                        title="Edit"
                      >
                        <Edit2 size={18} />
                      </button>
                      <button
                        onClick={() => handleDelete(template.id)}
                        disabled={deleteMutation.isPending}
                        className="p-2 text-red-600 hover:bg-red-50 rounded transition disabled:opacity-50"
                        title="Delete"
                      >
                        <Trash2 size={18} />
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

export default AdminScheduleTemplatesPage