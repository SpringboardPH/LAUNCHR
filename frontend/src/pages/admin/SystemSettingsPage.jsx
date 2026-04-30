import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader, FormField, ConfirmModal, Spinner } from '../../components/ui/index.jsx'
import { adminSettingsKeys, getAdminSettings, updateAdminSetting, systemClockKeys, attendanceKeys, leaveKeys, employeeLeaveBalanceKeys } from '../../api/queries'
import { Clock, Calendar, Save, RotateCcw, Zap } from 'lucide-react'

const formatDateForInput = (date) => date.toLocaleDateString('en-CA')

const formatTimeForInput = (date) => date.toTimeString().split(' ')[0]

const normalizeTimeValue = (timeValue) => {
  if (!timeValue) return ''
  return timeValue.length === 5 ? `${timeValue}:00` : timeValue
}

export default function SystemSettingsPage() {
  const qc = useQueryClient()
  const [dateTime, setDateTime] = useState({ date: '', time: '' })
  const [absentMarkingTime, setAbsentMarkingTime] = useState('23:59')
  const [autoClockOut, setAutoClockOut] = useState(false)
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '', type: 'info' })

  const { data: settings = [], isLoading } = useQuery({
    queryKey: adminSettingsKeys.all,
    queryFn: getAdminSettings,
  })

  useEffect(() => {
    if (settings.length > 0) {
      const now = new Date()
      const defaultDate = formatDateForInput(now)
      const defaultTime = formatTimeForInput(now)

      const sysDate = settings.find(s => s.key === 'system_date')?.value || defaultDate
      const sysTime = normalizeTimeValue(settings.find(s => s.key === 'system_time')?.value || defaultTime)
      setDateTime({ date: sysDate, time: sysTime })
      
      const markingTime = settings.find(s => s.key === 'absent_marking_time')?.value || '23:59'
      setAbsentMarkingTime(markingTime.substring(0, 5))

      const autoClockOutSetting = settings.find(s => s.key === 'auto_clock_out_enabled')
      if (autoClockOutSetting) {
        setAutoClockOut(autoClockOutSetting.value === 'true' || autoClockOutSetting.value === true || autoClockOutSetting.value === '1')
      } else {
        setAutoClockOut(false) // Default if not found
      }
    }
  }, [settings])

  const updateSettingMutation = useMutation({
    mutationFn: async ({ date, time, autoClockOut, absentMarkingTime }) => {
      const normalizedTime = normalizeTimeValue(time)
      await updateAdminSetting('system_date', date, 'Virtual system date for simulation', 'string')
      await updateAdminSetting('system_time', normalizedTime, 'Virtual system time for simulation', 'string')
      await updateAdminSetting('auto_clock_out_enabled', autoClockOut, 'Whether automatic clock-out is enabled', 'boolean')
      await updateAdminSetting('absent_marking_time', absentMarkingTime, 'Time when the system automatically marks employees as absent', 'string')
    },
    onSuccess: async () => {
      // Invalidate settings, system clock, AND all attendance queries so
      // the attendance clock page immediately reflects the new virtual time.
      await Promise.all([
        qc.invalidateQueries({ queryKey: adminSettingsKeys.all }),
        qc.invalidateQueries({ queryKey: systemClockKeys.all }),
        qc.invalidateQueries({ queryKey: attendanceKeys.all }),
        qc.invalidateQueries({ queryKey: leaveKeys.all }),
        qc.invalidateQueries({ queryKey: employeeLeaveBalanceKeys.all }),
      ])
      await Promise.all([
        qc.refetchQueries({ queryKey: systemClockKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: attendanceKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: leaveKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: employeeLeaveBalanceKeys.all, type: 'active' }),
      ])
    }
  })

  const handleSave = () => {
    setConfirmConfig({
      open: true,
      title: 'Save System Settings',
      message: 'Are you sure you want to update the settings? This may affect attendance records and payroll calculations.',
      type: 'brand',
      onConfirm: () => updateSettingMutation.mutate({ ...dateTime, autoClockOut, absentMarkingTime })
    })
  }

  const handleCancel = () => {
    setConfirmConfig({
      open: true,
      title: 'Discard Changes',
      message: 'Are you sure you want to discard your changes and reset to the last saved settings?',
      type: 'warning',
      onConfirm: () => {
        const now = new Date()
        const defaultDate = formatDateForInput(now)
        const defaultTime = formatTimeForInput(now)

        const sysDate = settings.find(s => s.key === 'system_date')?.value || defaultDate
        const sysTime = normalizeTimeValue(settings.find(s => s.key === 'system_time')?.value || defaultTime)
        setDateTime({ date: sysDate, time: sysTime })
        
        const markingTime = settings.find(s => s.key === 'absent_marking_time')?.value || '23:59'
        setAbsentMarkingTime(markingTime.substring(0, 5))

        const autoClockOutSetting = settings.find(s => s.key === 'auto_clock_out_enabled')?.value
        setAutoClockOut(autoClockOutSetting === 'true' || autoClockOutSetting === '1')
      }
    })
  }

  const handleSetCurrent = () => {
    setConfirmConfig({
      open: true,
      title: 'Set to Current Time',
      message: 'Are you sure you want to set the system date and time to your current local time? This will save immediately.',
      type: 'info',
      onConfirm: () => {
        // Capture the time at the exact moment the user confirms
        const now = new Date()
        const date = formatDateForInput(now)
        const time = formatTimeForInput(now)
        // Update form state so inputs reflect it
        setDateTime({ date, time })
        // Immediately persist — no need to click Save separately
        updateSettingMutation.mutate({ date, time, autoClockOut, absentMarkingTime })
      }
    })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    )
  }

  return (
    <div className="max-w-4xl mx-auto">
      <PageHeader
        title="System Settings"
        description="Manage global system configurations and environment overrides."
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig({ ...confirmConfig, open: false })}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />

      <div className="space-y-6">
        <div className="card p-6">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Attendance Automation</h2>
          <div className="space-y-4">
            <div className="flex items-center justify-between p-4 border rounded-lg">
              <div>
                <p className="text-sm font-medium text-gray-900">Enable Auto Clock-Out</p>
                <p className="text-xs text-gray-500">Automatically clock out employees who miss their shift end.</p>
              </div>
              <button
                type="button"
                onClick={() => setAutoClockOut(!autoClockOut)}
                className={`w-12 h-6 rounded-full flex items-center p-1 transition-colors ${autoClockOut ? 'bg-brand-600' : 'bg-gray-300'}`}
              >
                <div className={`w-4 h-4 rounded-full bg-white transition-transform ${autoClockOut ? 'translate-x-6' : ''}`} />
              </button>
            </div>

            <div className="p-4 border rounded-lg">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                  <p className="text-sm font-medium text-gray-900">Absent Marking Time</p>
                  <p className="text-xs text-gray-500">Time when the system automatically marks unscheduled employees as absent.</p>
                </div>
                <div className="w-full sm:w-40">
                  <input
                    type="time"
                    value={absentMarkingTime}
                    onChange={(e) => setAbsentMarkingTime(e.target.value)}
                    className="input h-10"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="card overflow-hidden">
          <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600">
                <Clock size={20} />
              </div>
              <div>
                <h2 className="text-sm font-semibold text-gray-900">Date and Time Configuration</h2>
                <p className="text-xs text-gray-500 mt-0.5">Adjust the virtual system clock used for testing and simulations.</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <FormField label="System Date" required>
                <div className="relative group">
                  <Calendar size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-brand-500 transition-colors" />
                  <input
                    type="date"
                    value={dateTime.date}
                    onChange={(e) => setDateTime(prev => ({ ...prev, date: e.target.value }))}
                    className="input pl-11 h-11"
                  />
                </div>
              </FormField>

              <FormField label="System Time" required>
                <div className="relative group">
                  <Clock size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-brand-500 transition-colors" />
                  <input
                    type="time"
                    step="1"
                    value={dateTime.time}
                    onChange={(e) => setDateTime(prev => ({ ...prev, time: e.target.value }))}
                    className="input pl-11 h-11"
                  />
                </div>
              </FormField>
            </div>
          </div>
        </div>

        <div className="card p-4">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
            <button
              type="button"
              onClick={handleSetCurrent}
              className="btn-ghost text-brand-600 hover:bg-brand-50 flex items-center gap-2 px-4 py-2.5"
            >
              <Zap size={18} />
              <span className="font-semibold">Set Current Date/Time</span>
            </button>

            <div className="flex items-center gap-3 w-full sm:w-auto">
              <button
                type="button"
                onClick={handleCancel}
                className="btn-secondary flex-1 sm:flex-none justify-center items-center gap-2 px-5 py-2.5"
              >
                <RotateCcw size={18} />
                Cancel
              </button>
              <button
                type="button"
                onClick={handleSave}
                disabled={updateSettingMutation.isPending}
                className="btn-primary flex-1 sm:flex-none justify-center items-center gap-2 px-6 py-2.5"
              >
                {updateSettingMutation.isPending ? <Spinner size="sm" /> : <Save size={18} />}
                <span>{updateSettingMutation.isPending ? 'Saving...' : 'Save Settings'}</span>
              </button>
            </div>
          </div>
        </div>

        <div className="bg-amber-50 border border-amber-200 rounded-xl p-5 flex gap-4">
          <div className="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0 text-amber-600">
            <Zap size={20} />
          </div>
          <div className="text-sm">
            <p className="font-semibold text-amber-900">Developer Note</p>
            <p className="text-amber-800 mt-1 leading-relaxed">
              These settings override the global system time for all attendance and payroll calculations. 
              Changing these values may result in data inconsistency if not handled carefully.
              ALWAYS use the "Set Current Date/Time" button to sync with real time AFTER making changes, to ensure all dependent data is recalculated correctly.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
