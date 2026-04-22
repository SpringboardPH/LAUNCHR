import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader, FormField, ConfirmModal, Spinner } from '../../components/ui/index.jsx'
import { adminSettingsKeys, getAdminSettings, updateAdminSetting, systemClockKeys, attendanceKeys } from '../../api/queries'
import { Clock, Calendar, Save, RotateCcw, Zap } from 'lucide-react'

export default function SystemSettingsPage() {
  const qc = useQueryClient()
  const [dateTime, setDateTime] = useState({ date: '', time: '' })
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '', type: 'info' })

  const { data: settings = [], isLoading } = useQuery({
    queryKey: adminSettingsKeys.all,
    queryFn: getAdminSettings,
  })

  useEffect(() => {
    if (settings.length > 0) {
      const now = new Date()
      const defaultDate = now.toLocaleDateString('en-CA')
      const defaultTime = now.toTimeString().split(' ')[0].slice(0, 5)

      const sysDate = settings.find(s => s.key === 'system_date')?.value || defaultDate
      const sysTime = settings.find(s => s.key === 'system_time')?.value || defaultTime
      setDateTime({ date: sysDate, time: sysTime })
    }
  }, [settings])

  const updateSettingMutation = useMutation({
    mutationFn: async ({ date, time }) => {
      await updateAdminSetting('system_date', date, 'Virtual system date for simulation', 'string')
      await updateAdminSetting('system_time', time, 'Virtual system time for simulation', 'string')
    },
    onSuccess: () => {
      // Invalidate settings, system clock, AND all attendance queries so
      // the attendance clock page immediately reflects the new virtual time.
      qc.invalidateQueries({ queryKey: adminSettingsKeys.all })
      qc.invalidateQueries({ queryKey: systemClockKeys.all })
      qc.invalidateQueries({ queryKey: attendanceKeys.all })
    }
  })

  const handleSave = () => {
    setConfirmConfig({
      open: true,
      title: 'Save System Time',
      message: 'Are you sure you want to update the system date and time? This may affect attendance records and payroll calculations.',
      type: 'brand',
      onConfirm: () => updateSettingMutation.mutate(dateTime)
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
        const defaultDate = now.toLocaleDateString('en-CA')
        const defaultTime = now.toTimeString().split(' ')[0].slice(0, 5)

        const sysDate = settings.find(s => s.key === 'system_date')?.value || defaultDate
        const sysTime = settings.find(s => s.key === 'system_time')?.value || defaultTime
        setDateTime({ date: sysDate, time: sysTime })
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
        const date = now.toLocaleDateString('en-CA')
        const time = now.toTimeString().split(' ')[0].slice(0, 5)
        // Update form state so inputs reflect it
        setDateTime({ date, time })
        // Immediately persist — no need to click Save separately
        updateSettingMutation.mutate({ date, time })
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

          <div className="p-6 space-y-8">
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
                    value={dateTime.time}
                    onChange={(e) => setDateTime(prev => ({ ...prev, time: e.target.value }))}
                    className="input pl-11 h-11"
                  />
                </div>
              </FormField>
            </div>

            <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-6 border-t border-gray-100">
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
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
