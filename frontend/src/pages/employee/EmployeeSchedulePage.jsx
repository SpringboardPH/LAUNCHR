import { useEffect, useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format, startOfWeek, endOfWeek, parseISO, isBefore, startOfDay, isAfter } from 'date-fns'
import {
  getMySchedules,
  setMySchedule,
  getAvailableTemplates,
  getSystemClock,
  systemClockKeys,
} from '../../api/queries'
import { PageHeader, ConfirmModal, AlertModal, StatusBadge } from '../../components/ui/index.jsx'
import { CalendarDays, Plus, Edit2, Clock3 } from 'lucide-react'
import { useAuth } from '../../store/AuthContext'
import clsx from 'clsx'

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

export default function EmployeeSchedulePage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState(null)
  
  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
  })
  
  const baseDate = useMemo(
    () => (sysClock?.date ? parseISO(sysClock.date) : new Date()),
    [sysClock?.date]
  )

  const [formData, setFormData] = useState({
    schedule_template_id: '',
    start_date: '',
    end_date: '',
  })

  // Set default form data when baseDate is available
  useEffect(() => {
    if (baseDate && !formData.start_date) {
      setFormData(prev => ({
        ...prev,
        ...getWeekRange(baseDate, 1),
      }))
    }
  }, [baseDate])

  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const [alertConfig, setAlertConfig] = useState({ open: false, title: '', message: '', type: 'error' })

  const { data: schedules = [] } = useQuery({
    queryKey: ['my-schedules'],
    queryFn: () => getMySchedules(),
  })

  const { data: templates = [] } = useQuery({
    queryKey: ['schedule-templates'],
    queryFn: getAvailableTemplates,
  })

  const mutation = useMutation({
    mutationFn: setMySchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-schedules'] })
      resetForm()
    },
    onError: (error) => {
      setAlertConfig({ open: true, title: 'Error', message: error?.response?.data?.message || 'Failed to set schedule', type: 'error' })
    }
  })

  const resetForm = () => {
    setFormData({
      schedule_template_id: '',
      ...getWeekRange(baseDate, 1),
    })
    setEditingId(null)
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!formData.schedule_template_id) {
      setAlertConfig({ open: true, title: 'Validation Error', message: 'Please select a schedule template', type: 'warning' })
      return
    }

    setConfirmConfig({
      open: true,
      title: 'Confirm Schedule',
      message: `Set your schedule for ${format(parseISO(formData.start_date), 'MMM dd')} - ${format(parseISO(formData.end_date), 'MMM dd, yyyy')}?`,
      onConfirm: () => mutation.mutate(formData),
      type: 'info'
    })
  }

  const handleEdit = (s) => {
    setEditingId(s.id)
    setFormData({
      schedule_template_id: s.schedule_template_id,
      start_date: s.start_date,
      end_date: s.end_date,
    })
  }

  const today = startOfDay(baseDate)
  const thisWeekRange = getWeekRange(baseDate, 0)
  const nextWeekRange = getWeekRange(baseDate, 1)

  // 1. Get explicit assignments for this and next week
  const explicitCurrent = Array.isArray(schedules) ? schedules.find(s => 
    s.status === 'active' && s.start_date === thisWeekRange.start_date
  ) : null

  const explicitNext = Array.isArray(schedules) ? schedules.find(s => 
    s.status === 'active' && s.start_date === nextWeekRange.start_date
  ) : null

  // 2. Get latest prior schedule for fallback (carry-forward)
  const latestPrior = Array.isArray(schedules) ? schedules
    .filter(s => s.status === 'active' && isBefore(parseISO(s.end_date), today))
    .sort((a, b) => parseISO(b.end_date) - parseISO(a.end_date))[0] : null

  // 3. Determine the two cards to show
  const currentCard = explicitCurrent || latestPrior ? {
    ...(explicitCurrent || latestPrior),
    label: explicitCurrent ? 'This Week' : 'This Week (Carried Forward)',
    isCarriedForward: !explicitCurrent,
    start_date: thisWeekRange.start_date,
    end_date: thisWeekRange.end_date,
  } : null

  const nextCardBase = explicitNext || currentCard;
  const nextCard = (nextCardBase && (explicitNext || (nextCardBase.template?.id !== currentCard?.template?.id))) ? {
    ...nextCardBase,
    label: explicitNext ? 'Next Week' : 'Next Week (Inherited)',
    isCarriedForward: !explicitNext,
    start_date: nextWeekRange.start_date,
    end_date: nextWeekRange.end_date,
  } : null;

  // Special case: If explicitNext exists but is the same template, we might still want to show it 
  // if the user wants to see their future assignments. 
  // However, the user said "if the future week schedule is the same as the last week, just use that".
  // So we will hide it if templates match.
  const finalNextCard = (nextCard && nextCard.template?.id !== currentCard?.template?.id) ? nextCard : null;

  // Filter out the cards we've already handled for the 'Past' list
  const pastSchedules = Array.isArray(schedules) ? schedules
    .filter(s => 
      (s.status === 'archived' || isBefore(parseISO(s.end_date), today)) && 
      s.id !== explicitCurrent?.id && 
      s.id !== latestPrior?.id
    )
    .sort((a, b) => parseISO(b.end_date) - parseISO(a.end_date)) : []

  return (
    <div className="max-w-5xl">
      <PageHeader 
        title="My Work Schedule" 
        description="View your current schedule or set a new one for upcoming weeks"
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig({ ...confirmConfig, open: false })}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />
      <AlertModal
        open={alertConfig.open}
        onClose={() => setAlertConfig(a => ({ ...a, open: false }))}
        title={alertConfig.title}
        message={alertConfig.message}
        type={alertConfig.type}
      />

      <div className="grid lg:grid-cols-2 gap-6 items-start">
        {/* Assignment Form */}
        <div>
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
              <Plus size={14} className="text-brand-600" />
              {editingId ? 'Update Schedule' : 'Set New Schedule'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Schedule Template</label>
                <select
                  value={formData.schedule_template_id}
                  onChange={(e) => setFormData({ ...formData, schedule_template_id: e.target.value })}
                  className="input text-sm"
                  required
                >
                  <option value="">Select a template...</option>
                  {templates.map(t => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
                {formData.schedule_template_id && (
                  <div className="mt-2 p-3 bg-gray-50 rounded-lg text-xs text-gray-500 border border-gray-100">
                    <p className="font-medium text-gray-700 mb-1">Template Details:</p>
                    {templates.find(t => t.id == formData.schedule_template_id)?.description || 'No description available'}
                  </div>
                )}
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-600 mb-2">Select Week</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setFormData({ ...formData, ...getWeekRange(baseDate, 0) })}
                    className={clsx(
                      "btn-secondary text-xs py-2.5 justify-center",
                      formData.start_date === getWeekRange(baseDate, 0).start_date && "bg-brand-50 border-brand-200 text-brand-700 ring-1 ring-brand-100"
                    )}
                  >
                    This Week
                  </button>
                  <button
                    type="button"
                    onClick={() => setFormData({ ...formData, ...getWeekRange(baseDate, 1) })}
                    className={clsx(
                      "btn-secondary text-xs py-2.5 justify-center",
                      formData.start_date === getWeekRange(baseDate, 1).start_date && "bg-brand-50 border-brand-200 text-brand-700 ring-1 ring-brand-100"
                    )}
                  >
                    Next Week
                  </button>
                </div>
                
                {formData.start_date && (
                  <div className="mt-3 p-3 bg-brand-50/30 rounded-lg border border-brand-100 flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-600 shrink-0">
                      <CalendarDays size={16} />
                    </div>
                    <div>
                      <p className="text-[10px] text-brand-600 font-bold uppercase tracking-wider">Schedule Range</p>
                      <p className="text-sm font-semibold text-brand-900">
                        {format(parseISO(formData.start_date), 'MMM dd')} – {format(parseISO(formData.end_date), 'MMM dd, yyyy')}
                      </p>
                    </div>
                  </div>
                )}
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={mutation.isPending}
                  className="btn-primary w-full h-11 text-sm shadow-sm"
                >
                  {mutation.isPending ? 'Saving...' : editingId ? 'Update Assignment' : 'Assign Schedule'}
                </button>
                {editingId && (
                  <button
                    type="button"
                    onClick={resetForm}
                    className="btn-ghost w-full mt-2 text-xs"
                  >
                    Cancel Edit
                  </button>
                )}
              </div>
            </form>
          </div>
        </div>

        {/* Schedule List */}
        <div className="space-y-5">
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
              <CalendarDays size={14} className="text-brand-600" /> Active & Upcoming
            </h2>
            
            <div className="space-y-3">
              {[currentCard, finalNextCard].filter(Boolean).map((s, idx) => (
                <div key={idx} className={clsx(
                  "p-4 rounded-xl border transition-colors group",
                  s.isCarriedForward ? "bg-gray-50/50 border-gray-100" : "bg-white border-brand-100 ring-1 ring-brand-50"
                )}>
                  <div className="flex items-start justify-between">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <h3 className="font-semibold text-gray-900">{s.template?.name}</h3>
                        <span className={clsx(
                          "px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider",
                          s.isCarriedForward ? "bg-gray-200 text-gray-600" : "bg-brand-100 text-brand-700"
                        )}>
                          {s.label}
                        </span>
                      </div>
                      <p className="text-xs text-gray-500">
                        {format(parseISO(s.start_date), 'MMM dd')} - {format(parseISO(s.end_date), 'MMM dd, yyyy')}
                      </p>
                    </div>
                    {!s.isCarriedForward && (
                      <button 
                        onClick={() => handleEdit(s)}
                        className="p-2 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"
                      >
                        <Edit2 size={14} />
                      </button>
                    )}
                  </div>
                  
                  <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
                    <div className="text-[10px]">
                      <p className="text-gray-400 uppercase font-bold tracking-wider">Hours</p>
                      <p className="text-gray-700 font-medium text-sm">
                        {s.template?.work_start_time?.substring(0, 5)} - {s.template?.work_end_time?.substring(0, 5)}
                      </p>
                    </div>
                    <div className="text-[10px]">
                      <p className="text-gray-400 uppercase font-bold tracking-wider">Work Days</p>
                      <p className="text-gray-700 font-medium text-sm">
                        {(s.template?.work_days || []).map(d => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d]).join(', ')}
                      </p>
                    </div>
                  </div>
                </div>
              ))}
              
              {(!currentCard && !nextCard) && (
                <div className="text-center py-10 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                  <Clock3 size={24} className="mx-auto text-gray-300 mb-2" />
                  <p className="text-sm text-gray-500">No schedules assigned.</p>
                </div>
              )}
            </div>
          </div>

          {pastSchedules.length > 0 && (
            <div className="card p-5">
              <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <Clock3 size={14} className="text-gray-400" /> Past Schedules
              </h2>
              <div className="space-y-2">
                {pastSchedules.slice(0, 5).map(s => (
                  <div key={s.id} className="flex items-center justify-between p-3 rounded-lg bg-gray-50 text-xs">
                    <div>
                      <p className="font-medium text-gray-700">{s.template?.name}</p>
                      <p className="text-gray-400">
                        {format(parseISO(s.start_date), 'MMM dd')} - {format(parseISO(s.end_date), 'MMM dd, yyyy')}
                      </p>
                    </div>
                    <StatusBadge status="archived" />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
