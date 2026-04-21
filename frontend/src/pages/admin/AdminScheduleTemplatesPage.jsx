import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  scheduleTemplateKeys,
  getScheduleTemplates,
  createScheduleTemplate,
  updateScheduleTemplate,
  deleteScheduleTemplate,
} from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import { CalendarRange, Plus, Edit2, Trash2 } from 'lucide-react'

const WEEK_DAYS = [
  { day: 1, label: 'Monday', short: 'Mon' },
  { day: 2, label: 'Tuesday', short: 'Tue' },
  { day: 3, label: 'Wednesday', short: 'Wed' },
  { day: 4, label: 'Thursday', short: 'Thu' },
  { day: 5, label: 'Friday', short: 'Fri' },
  { day: 6, label: 'Saturday', short: 'Sat' },
  { day: 0, label: 'Sunday', short: 'Sun' },
]

const createDefaultDayRules = () =>
  WEEK_DAYS.map(({ day }) => ({
    day,
    enabled: false,
    clock_in: '09:00',
    clock_out: '18:00',
    grace_enabled: false,
    grace_type: '-/+',
    grace_minutes: 15,
  }))

const toTimeInput = (value) => (value ? value.substring(0, 5) : '')
const toApiTime = (value) => (value ? `${value}:00` : null)

const parseTimeToMinutes = (time) => {
  if (!time) return 0
  const [hour, minute] = time.split(':').map(Number)
  return hour * 60 + minute
}

const minutesToTime = (minutes) => {
  const normalized = ((minutes % 1440) + 1440) % 1440
  const hours = Math.floor(normalized / 60)
  const mins = normalized % 60
  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`
}

const applyGraceWindow = (targetTime, graceType, graceMinutes) => {
  const targetMinutes = parseTimeToMinutes(targetTime)
  let startMinutes = targetMinutes
  let endMinutes = targetMinutes

  const minutes = Number.isFinite(Number(graceMinutes)) ? Number(graceMinutes) : 15

  if (graceType === '-' || graceType === '-/+') startMinutes -= minutes
  if (graceType === '+' || graceType === '-/+') endMinutes += minutes

  return {
    start: minutesToTime(startMinutes),
    end: minutesToTime(endMinutes),
  }
}

const isGraceEnabled = (rule) => {
  if (typeof rule?.grace_enabled === 'boolean') return rule.grace_enabled
  return false
}

const calculateHours = (clockIn, clockOut) => {
  const inMinutes = parseTimeToMinutes(clockIn)
  let outMinutes = parseTimeToMinutes(clockOut)
  if (outMinutes < inMinutes) outMinutes += 1440
  return Math.max(1, Math.round((outMinutes - inMinutes) / 60))
}

const normalizeTemplateRules = (template) => {
  const defaults = createDefaultDayRules()

  if (Array.isArray(template.day_rules) && template.day_rules.length) {
    const mapped = new Map(template.day_rules.map(rule => [Number(rule.day), rule]))
    return defaults.map(base => {
      const existing = mapped.get(base.day)
      return {
        day: base.day,
        enabled: Boolean(existing?.enabled),
        clock_in: toTimeInput(existing?.clock_in) || base.clock_in,
        clock_out: toTimeInput(existing?.clock_out) || base.clock_out,
        grace_enabled: isGraceEnabled(existing),
        grace_type: existing?.grace_type || base.grace_type,
        grace_minutes: Number.isFinite(Number(existing?.grace_minutes))
          ? Number(existing?.grace_minutes)
          : base.grace_minutes,
      }
    })
  }

  const enabledDays = Array.isArray(template.work_days) ? template.work_days.map(Number) : []
  return defaults.map(base => ({
    day: base.day,
    enabled: enabledDays.includes(base.day),
    clock_in: toTimeInput(template.start_time) || base.clock_in,
    clock_out: toTimeInput(template.end_time) || base.clock_out,
    grace_enabled: base.grace_enabled,
    grace_type: base.grace_type,
    grace_minutes: base.grace_minutes,
  }))
}

const toRuleMap = (template) => {
  const rules = normalizeTemplateRules(template)
  return new Map(rules.map(rule => [rule.day, rule]))
}

const AdminScheduleTemplatesPage = () => {
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    day_rules: createDefaultDayRules(),
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
      day_rules: createDefaultDayRules(),
    })
    setEditingId(null)
  }

  const updateDayRule = (day, updates) => {
    setFormData(prev => ({
      ...prev,
      day_rules: prev.day_rules.map(rule =>
        rule.day === day ? { ...rule, ...updates } : rule
      ),
    }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    const enabledRules = formData.day_rules.filter(rule => rule.enabled)
    if (!formData.name.trim() || enabledRules.length === 0) {
      alert('Name and at least one active day are required')
      return
    }

    const hasIncompleteRules = enabledRules.some(
      rule => !rule.clock_in || !rule.clock_out
    )
    if (hasIncompleteRules) {
      alert('Every active day must include both clock in and clock out time')
      return
    }

    const firstRule = enabledRules[0]
    const graceMinutes = Number.isFinite(Number(firstRule.grace_minutes))
      ? Number(firstRule.grace_minutes)
      : 15
    const clockInWindow = firstRule.grace_enabled
      ? applyGraceWindow(firstRule.clock_in, firstRule.grace_type, graceMinutes)
      : { start: firstRule.clock_in, end: firstRule.clock_in }
    const clockOutWindow = firstRule.grace_enabled
      ? applyGraceWindow(firstRule.clock_out, firstRule.grace_type, graceMinutes)
      : { start: firstRule.clock_out, end: firstRule.clock_out }
    const hoursPerDay = calculateHours(firstRule.clock_in, firstRule.clock_out)

    const payload = {
      name: formData.name,
      description: formData.description,
      day_rules: formData.day_rules.map(rule => ({
        day: rule.day,
        enabled: rule.enabled,
        clock_in: rule.enabled ? toApiTime(rule.clock_in) : null,
        clock_out: rule.enabled ? toApiTime(rule.clock_out) : null,
        grace_enabled: rule.enabled ? Boolean(rule.grace_enabled) : false,
        grace_type: rule.grace_type,
        grace_minutes: Number.isFinite(Number(rule.grace_minutes)) ? Number(rule.grace_minutes) : 15,
      })),
      work_days: enabledRules.map(rule => rule.day).sort((a, b) => a - b),
      clock_in_start: toApiTime(clockInWindow.start),
      clock_in_end: toApiTime(clockInWindow.end),
      clock_out_start: toApiTime(clockOutWindow.start),
      clock_out_end: toApiTime(clockOutWindow.end),
      start_time: toApiTime(firstRule.clock_in),
      end_time: toApiTime(firstRule.clock_out),
      work_start_time: toApiTime(firstRule.clock_in),
      work_end_time: toApiTime(firstRule.clock_out),
      late_threshold_minutes: 0,
      required_hours_per_day: hoursPerDay,
      overtime_threshold_hours: hoursPerDay,
      expected_hours_per_day: hoursPerDay,
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
      day_rules: normalizeTemplateRules(template),
    })
  }

  const handleDelete = (id) => {
    if (confirm('Delete this schedule template?')) {
      deleteMutation.mutate(id)
    }
  }

  if (isLoading) {
    return (
      <div>
        <PageHeader
          title="Schedule Templates"
          description="Create and manage reusable work schedule templates for employees"
        />
        <PageSpinner />
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Schedule Templates"
        description="Create and manage reusable work schedule templates for employees"
      />

      {/* Form */}
      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <Plus size={14} className="text-brand-600" />
          {editingId ? 'Edit Weekly Template' : 'Create Weekly Template'}
        </h2>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Template Name</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="input"
                  placeholder="e.g., Morning Shift"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Description</label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="input min-h-24 resize-none"
                  placeholder="e.g., Early morning shift with flexible end time"
                  rows="4"
                />
              </div>

              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                <p className="font-medium text-gray-900 text-sm mb-1">Weekly template rules</p>
                <p className="text-xs leading-5">
                  Enable only the days that should be working days. For each active day, set the clock-in/out time and optionally add a grace rule.
                </p>
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-600 mb-2">
                Weekly Rules (Monday to Sunday)
              </label>
              <div className="space-y-2">
              {formData.day_rules.map(rule => {
                const dayMeta = WEEK_DAYS.find(d => d.day === rule.day)
                return (
                  <div key={rule.day} className="rounded-lg border border-gray-200 p-2.5">
                    <div className="flex items-center justify-between gap-3">
                      <label className="flex items-center gap-2 text-sm font-medium text-gray-800">
                        <input
                          type="checkbox"
                          checked={rule.enabled}
                          onChange={(e) => updateDayRule(rule.day, { enabled: e.target.checked })}
                          className="w-4 h-4"
                        />
                        {dayMeta?.label}
                      </label>
                      {!rule.enabled && <span className="text-xs text-gray-400">Off</span>}
                    </div>

                    {rule.enabled && (
                      <div className="grid md:grid-cols-4 gap-3 mt-2.5">
                        <div>
                          <label className="block text-xs font-medium text-gray-500 mb-1">Clock In</label>
                          <input
                            type="time"
                            value={rule.clock_in}
                            onChange={(e) => updateDayRule(rule.day, { clock_in: e.target.value })}
                            className="input"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-500 mb-1">Clock Out</label>
                          <input
                            type="time"
                            value={rule.clock_out}
                            onChange={(e) => updateDayRule(rule.day, { clock_out: e.target.value })}
                            className="input"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-500 mb-1">Grace Rule</label>
                          <label className="inline-flex items-center gap-2 text-sm text-gray-700 py-2">
                            <input
                              type="checkbox"
                              checked={Boolean(rule.grace_enabled)}
                              onChange={(e) => updateDayRule(rule.day, { grace_enabled: e.target.checked })}
                              className="w-4 h-4"
                            />
                            Enable
                          </label>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-500 mb-1">Grace Type</label>
                          <select
                            value={rule.grace_type}
                            onChange={(e) => updateDayRule(rule.day, { grace_type: e.target.value })}
                            disabled={!rule.grace_enabled}
                            className="input disabled:bg-gray-100"
                          >
                            <option value="-">- (Before only)</option>
                            <option value="+">+ (After only)</option>
                            <option value="-/+">-/+ (Before and after)</option>
                          </select>
                        </div>
                        <div className="md:col-start-4">
                          <label className="block text-xs font-medium text-gray-500 mb-1">Grace Minutes</label>
                          <input
                            type="number"
                            min="0"
                            max="180"
                            value={rule.grace_minutes}
                            onChange={(e) => updateDayRule(rule.day, { grace_minutes: Number(e.target.value || 0) })}
                            disabled={!rule.grace_enabled}
                            className="input disabled:bg-gray-100"
                          />
                        </div>
                      </div>
                    )}
                  </div>
                )
              })}
              </div>
            </div>
          </div>

          <div className="flex flex-wrap gap-2 pt-2">
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="btn-primary disabled:opacity-50"
            >
              {editingId ? 'Update Template' : 'Create Template'}
            </button>
            {editingId && (
              <button
                type="button"
                onClick={resetForm}
                className="btn-secondary"
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
          <table className="w-full text-sm">
            <thead className="border-b border-gray-100">
              <tr>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Name</th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Description</th>
                {WEEK_DAYS.map(day => (
                  <th key={day.day} className="pb-2 text-left text-xs text-gray-400 font-medium pr-3">{day.short}</th>
                ))}
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {templates.map((template) => {
                const ruleMap = toRuleMap(template)
                return (
                <tr key={template.id} className="hover:bg-gray-50 align-top">
                  <td className="py-2.5 pr-4 text-sm text-gray-900 font-medium">{template.name}</td>
                  <td className="py-2.5 pr-4 text-sm text-gray-600">{template.description}</td>
                  {WEEK_DAYS.map(dayMeta => {
                    const rule = ruleMap.get(dayMeta.day)
                    return (
                      <td key={dayMeta.day} className="py-2.5 pr-3">
                        {rule?.enabled ? (
                          <div className="text-xs text-gray-600 leading-4">
                            <div>In {rule.clock_in}</div>
                            <div>Out {rule.clock_out}</div>
                            <div>
                              G {rule.grace_enabled ? `${rule.grace_type} ${rule.grace_minutes ?? 15}m` : 'Off'}
                            </div>
                          </div>
                        ) : (
                          <span className="text-xs text-gray-300">Off</span>
                        )}
                      </td>
                    )
                  })}
                  <td className="py-2.5 pr-4">
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleEdit(template)}
                        className="btn-ghost p-1.5 text-brand-600 hover:bg-brand-50"
                        title="Edit"
                      >
                        <Edit2 size={14} />
                      </button>
                      <button
                        onClick={() => handleDelete(template.id)}
                        disabled={deleteMutation.isPending}
                        className="btn-ghost p-1.5 text-red-500 hover:bg-red-50 disabled:opacity-50"
                        title="Delete"
                      >
                        <Trash2 size={14} />
                      </button>
                    </div>
                  </td>
                </tr>
              )})}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

export default AdminScheduleTemplatesPage