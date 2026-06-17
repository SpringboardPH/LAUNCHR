import { useState, useEffect, useRef, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader, FormField, ConfirmModal, AlertModal, Spinner } from '../../components/ui/index.jsx'
import { adminSettingsKeys, getAdminSettings, updateAdminSetting, uploadLogo, deleteLogo, uploadPayrollTemplate, getLogos, systemClockKeys, attendanceKeys, leaveKeys, employeeLeaveBalanceKeys, themeColorKeys, systemConfigKeys } from '../../api/queries'
import { Clock, Calendar, Save, RotateCcw, Zap, Palette, Monitor, Upload, Image as ImageIcon, Check, FileSpreadsheet, Trash2 } from 'lucide-react'

const formatDateForInput = (date) => date.toLocaleDateString('en-CA')

const formatTimeForInput = (date) => date.toTimeString().split(' ')[0]

const normalizeTimeValue = (timeValue) => {
  if (!timeValue) return ''
  return timeValue.length === 5 ? `${timeValue}:00` : timeValue
}

const themePresets = [
  { id: 'green', name: 'Emerald Green', colorClass: 'bg-emerald-600' },
  { id: 'blue', name: 'Ocean Blue', colorClass: 'bg-blue-600' },
  { id: 'purple', name: 'Royal Purple', colorClass: 'bg-purple-600' },
  { id: 'sienna', name: 'Sienna', colorClass: 'bg-[#D85A30]' },
  { id: 'rose', name: 'Rose Petal', colorClass: 'bg-rose-600' },
]

export default function SystemSettingsPage() {
  const qc = useQueryClient()
  const fileInputRef = useRef(null)
  const templateFileInputRef = useRef(null)
  const [dateTime, setDateTime] = useState({ date: '', time: '' })
  const [absentMarkingTime, setAbsentMarkingTime] = useState('23:59')
  const [systemName, setSystemName] = useState('LAUNCHR')
  const [systemLogo, setSystemLogo] = useState('launchr_black.svg')
  const [payrollTemplate, setPayrollTemplate] = useState('payrolltemplate.xlsx')
  const [logoPreview, setLogoPreview] = useState(null)
  const [selectedFile, setSelectedFile] = useState(null)
  const [selectedTemplateFile, setSelectedTemplateFile] = useState(null)
  const [autoClockOut, setAutoClockOut] = useState(false)
  const [requireLoginOtp, setRequireLoginOtp] = useState(false)
  const [sssTable, setSssTable] = useState('')
  const [themeColor, setThemeColor] = useState('sienna')
  const [payrollFrequency, setPayrollFrequency] = useState('semi_monthly')
  const [p1Start, setP1Start] = useState(1)
  const [p1End, setP1End] = useState(15)
  const [p2Start, setP2Start] = useState(16)
  const [p2End, setP2End] = useState(31)
  const [pMonthlyStart, setPMonthlyStart] = useState(1)
  const [pMonthlyEnd, setPMonthlyEnd] = useState(31)
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '', type: 'info' })
  const [alertConfig, setAlertConfig] = useState({ open: false, title: '', message: '', type: 'error' })

  const { data: settings = [], isLoading } = useQuery({
    queryKey: adminSettingsKeys.all,
    queryFn: getAdminSettings,
  })

  const { data: availableLogos = [] } = useQuery({
    queryKey: ['admin', 'logos'],
    queryFn: getLogos,
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

      const nameSetting = settings.find(s => s.key === 'system_name')?.value || 'LAUNCHR'
      setSystemName(nameSetting)

      const logoSetting = settings.find(s => s.key === 'system_logo')?.value || 'launchr_black.svg'
      setSystemLogo(logoSetting)

      const templateSetting = settings.find(s => s.key === 'payroll_template')?.value || 'payrolltemplate.xlsx'
      setPayrollTemplate(templateSetting)

      const autoClockOutSetting = settings.find(s => s.key === 'auto_clock_out_enabled')
      if (autoClockOutSetting) {
        setAutoClockOut(autoClockOutSetting.value === 'true' || autoClockOutSetting.value === true || autoClockOutSetting.value === '1')
      } else {
        setAutoClockOut(false)
      }

      const loginOtpSetting = settings.find(s => s.key === 'login_otp_required')
      if (loginOtpSetting) {
        setRequireLoginOtp(loginOtpSetting.value === 'true' || loginOtpSetting.value === true || loginOtpSetting.value === '1')
      } else {
        setRequireLoginOtp(false)
      }

      const sssSetting = settings.find(s => s.key === 'sss_contribution_table')
      if (sssSetting) {
        setSssTable(typeof sssSetting.value === 'string' ? sssSetting.value : JSON.stringify(sssSetting.value, null, 2))
      }

      const themeSetting = settings.find(s => s.key === 'theme_color')?.value || 'sienna'
      setThemeColor(themeSetting)

      setPayrollFrequency(settings.find(s => s.key === 'payroll_frequency')?.value ?? 'semi_monthly')
      setP1Start(parseInt(settings.find(s => s.key === 'payroll_period1_start_day')?.value ?? '1'))
      setP1End(parseInt(settings.find(s => s.key === 'payroll_period1_end_day')?.value ?? '15'))
      setP2Start(parseInt(settings.find(s => s.key === 'payroll_period2_start_day')?.value ?? '16'))
      setP2End(parseInt(settings.find(s => s.key === 'payroll_period2_end_day')?.value ?? '31'))
      setPMonthlyStart(parseInt(settings.find(s => s.key === 'payroll_monthly_start_day')?.value ?? '1'))
      setPMonthlyEnd(parseInt(settings.find(s => s.key === 'payroll_monthly_end_day')?.value ?? '31'))
    }
  }, [settings])

  const uploadLogoMutation = useMutation({
    mutationFn: uploadLogo,
    onSuccess: (data) => {
      setSystemLogo(data.data)
      setLogoPreview(null)
      setSelectedFile(null)
      qc.invalidateQueries({ queryKey: systemConfigKeys.all })
      qc.invalidateQueries({ queryKey: ['admin', 'logos'] })
      qc.invalidateQueries({ queryKey: adminSettingsKeys.all })
    }
  })

  const deleteLogoMutation = useMutation({
    mutationFn: deleteLogo,
    onSuccess: (_, filename) => {
      if (systemLogo === filename) setSystemLogo('launchr_black.svg')
      qc.invalidateQueries({ queryKey: ['admin', 'logos'] })
      qc.invalidateQueries({ queryKey: systemConfigKeys.all })
    },
    onError: (error) => {
      setAlertConfig({ open: true, title: 'Error', message: error?.response?.data?.message || 'Failed to delete logo', type: 'error' })
    },
  })

  const uploadTemplateMutation = useMutation({
    mutationFn: uploadPayrollTemplate,
    onSuccess: (data) => {
      setPayrollTemplate(data.data)
      setSelectedTemplateFile(null)
      qc.invalidateQueries({ queryKey: adminSettingsKeys.all })
    }
  })

  const updateSettingMutation = useMutation({
    mutationFn: async ({ date, time, autoClockOut, requireLoginOtp, absentMarkingTime, sssTable, themeColor, systemName, systemLogo, payrollTemplate }) => {
      const normalizedTime = normalizeTimeValue(time)
      await updateAdminSetting('system_date', date, 'Virtual system date for simulation', 'string')
      await updateAdminSetting('system_time', normalizedTime, 'Virtual system time for simulation', 'string')
      await updateAdminSetting('auto_clock_out_enabled', autoClockOut, 'Whether automatic clock-out is enabled', 'boolean')
      await updateAdminSetting('login_otp_required', requireLoginOtp, 'Whether an email OTP is required to log in', 'boolean')
      await updateAdminSetting('absent_marking_time', absentMarkingTime, 'Time when the system automatically marks employees as absent', 'string')
      await updateAdminSetting('theme_color', themeColor, 'System theme color preset', 'string')
      await updateAdminSetting('system_name', systemName, 'The name of the system displayed in the sidebar', 'string')
      
      if (selectedFile) {
        await uploadLogoMutation.mutateAsync(selectedFile)
      } else {
        await updateAdminSetting('system_logo', systemLogo, 'The logo used by the system', 'string')
      }

      if (selectedTemplateFile) {
        await uploadTemplateMutation.mutateAsync(selectedTemplateFile)
      } else {
        await updateAdminSetting('payroll_template', payrollTemplate, 'The Excel template used for payroll generation', 'string')
      }

      if (sssTable) {
        try {
          // Validate JSON before sending
          const parsed = JSON.parse(sssTable)
          await updateAdminSetting('sss_contribution_table', parsed, 'SSS Employee Contribution Table', 'json')
        } catch (e) {
          console.error("Invalid SSS JSON", e)
        }
      }

      await updateAdminSetting('payroll_frequency',         payrollFrequency, 'Payroll cycle: semi_monthly or monthly',                     'string')
      await updateAdminSetting('payroll_period1_start_day', p1Start,          'Semi-monthly: start day of first period',                    'integer')
      await updateAdminSetting('payroll_period1_end_day',   p1End,            'Semi-monthly: end day of first period',                      'integer')
      await updateAdminSetting('payroll_period2_start_day', p2Start,          'Semi-monthly: start day of second period',                   'integer')
      await updateAdminSetting('payroll_period2_end_day',   p2End,            'Semi-monthly: end day of second period (31 = end of month)', 'integer')
      await updateAdminSetting('payroll_monthly_start_day', pMonthlyStart,    'Monthly: start day of the payroll period',                   'integer')
      await updateAdminSetting('payroll_monthly_end_day',   pMonthlyEnd,      'Monthly: end day of the payroll period (31 = end of month)', 'integer')
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
        qc.invalidateQueries({ queryKey: themeColorKeys.all }),
        qc.invalidateQueries({ queryKey: systemConfigKeys.all }),
      ])
      await Promise.all([
        qc.refetchQueries({ queryKey: systemClockKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: attendanceKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: leaveKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: employeeLeaveBalanceKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: themeColorKeys.all, type: 'active' }),
        qc.refetchQueries({ queryKey: systemConfigKeys.all, type: 'active' }),
      ])
    }
  })

  const handleFileChange = (e) => {
    const file = e.target.files[0]
    if (file) {
      setSelectedFile(file)
      const reader = new FileReader()
      reader.onloadend = () => {
        setLogoPreview(reader.result)
      }
      reader.readAsDataURL(file)
    }
  }

  const handleTemplateFileChange = (e) => {
    const file = e.target.files[0]
    if (file) {
      setSelectedTemplateFile(file)
    }
  }

  const handleSave = () => {
    setConfirmConfig({
      open: true,
      title: 'Save System Settings',
      message: 'Are you sure you want to update the settings? This may affect attendance records and payroll calculations.',
      type: 'brand',
      onConfirm: () => updateSettingMutation.mutate({ ...dateTime, autoClockOut, requireLoginOtp, absentMarkingTime, sssTable, themeColor, systemName, systemLogo, payrollTemplate })
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

        const nameSetting = settings.find(s => s.key === 'system_name')?.value || 'LAUNCHR'
        setSystemName(nameSetting)

        const logoSetting = settings.find(s => s.key === 'system_logo')?.value || 'launchr_black.svg'
        setSystemLogo(logoSetting)
        setLogoPreview(null)
        setSelectedFile(null)

        const templateSetting = settings.find(s => s.key === 'payroll_template')?.value || 'payrolltemplate.xlsx'
        setPayrollTemplate(templateSetting)
        setSelectedTemplateFile(null)

        const autoClockOutSetting = settings.find(s => s.key === 'auto_clock_out_enabled')?.value
        setAutoClockOut(autoClockOutSetting === 'true' || autoClockOutSetting === '1')

        const loginOtpSetting = settings.find(s => s.key === 'login_otp_required')?.value
        setRequireLoginOtp(loginOtpSetting === 'true' || loginOtpSetting === '1')

        const sssSetting = settings.find(s => s.key === 'sss_contribution_table')
        if (sssSetting) {
          setSssTable(typeof sssSetting.value === 'string' ? sssSetting.value : JSON.stringify(sssSetting.value, null, 2))
        }

        const themeSetting = settings.find(s => s.key === 'theme_color')?.value || 'sienna'
        setThemeColor(themeSetting)

        setPayrollFrequency(settings.find(s => s.key === 'payroll_frequency')?.value ?? 'semi_monthly')
        setP1Start(parseInt(settings.find(s => s.key === 'payroll_period1_start_day')?.value ?? '1'))
        setP1End(parseInt(settings.find(s => s.key === 'payroll_period1_end_day')?.value ?? '15'))
        setP2Start(parseInt(settings.find(s => s.key === 'payroll_period2_start_day')?.value ?? '16'))
        setP2End(parseInt(settings.find(s => s.key === 'payroll_period2_end_day')?.value ?? '31'))
        setPMonthlyStart(parseInt(settings.find(s => s.key === 'payroll_monthly_start_day')?.value ?? '1'))
        setPMonthlyEnd(parseInt(settings.find(s => s.key === 'payroll_monthly_end_day')?.value ?? '31'))
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
        updateSettingMutation.mutate({ date, time, autoClockOut, requireLoginOtp, absentMarkingTime, sssTable, themeColor, systemName, systemLogo, payrollTemplate })
      }
    })
  }

  const previewPeriods = useMemo(() => {
    const lastDay = (y, m) => new Date(y, m, 0).getDate()
    const resolve = (day, y, m) => (day === 31 ? lastDay(y, m) : day)
    const fmt = (y, m, d) => new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
    const buildPeriod = (sDay, eDay, y, m) => {
      const rs = resolve(sDay, y, m)
      if (eDay < sDay) {
        const ny = m === 12 ? y + 1 : y, nm = m === 12 ? 1 : m + 1
        return { start: fmt(y, m, rs), end: fmt(ny, nm, resolve(eDay, ny, nm)) }
      }
      return { start: fmt(y, m, rs), end: fmt(y, m, resolve(eDay, y, m)) }
    }
    const now = new Date()
    const months = [0, 1, 2].map(i => {
      const d = new Date(now.getFullYear(), now.getMonth() + i, 1)
      return { y: d.getFullYear(), m: d.getMonth() + 1, label: d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) }
    })
    return months.flatMap(({ y, m, label }) =>
      payrollFrequency === 'monthly'
        ? [{ period: label, ...buildPeriod(pMonthlyStart, pMonthlyEnd, y, m) }]
        : [
            { period: `${label} — Period 1`, ...buildPeriod(p1Start, p1End, y, m) },
            { period: `${label} — Period 2`, ...buildPeriod(p2Start, p2End, y, m) },
          ]
    )
  }, [payrollFrequency, p1Start, p1End, p2Start, p2End, pMonthlyStart, pMonthlyEnd])

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    )
  }

  const logoBaseUrl = '/api/logo'

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
      <AlertModal
        open={alertConfig.open}
        onClose={() => setAlertConfig(a => ({ ...a, open: false }))}
        title={alertConfig.title}
        message={alertConfig.message}
        type={alertConfig.type}
      />

      <div className="space-y-6">
        <div className="card overflow-hidden">
          <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600">
                <Monitor size={20} />
              </div>
              <div>
                <h2 className="text-sm font-semibold text-gray-900">General Configuration</h2>
                <p className="text-xs text-gray-500 mt-0.5">Customize the basic identity and appearance of your system.</p>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-8">
            <FormField label="System Display Name" required description="This name appears in the sidebar and top navigation bar for all users.">
              <input
                type="text"
                value={systemName}
                onChange={(e) => setSystemName(e.target.value)}
                className="input h-11"
                placeholder="Enter system name (e.g., LAUNCHR)"
              />
            </FormField>

            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium text-gray-700">System Logo</label>
                <div className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Selected: {selectedFile ? 'New Upload' : systemLogo}</div>
              </div>
              
              <div className="flex flex-col md:flex-row items-start gap-8">
                {/* Active Preview */}
                <div className="flex flex-col items-center gap-3">
                  <div className="w-40 h-40 rounded-2xl border-2 border-brand-100 bg-brand-50/20 flex items-center justify-center overflow-hidden shadow-inner relative group">
                    {logoPreview ? (
                      <img src={logoPreview} alt="Preview" className="w-full h-full object-contain p-4 transition-transform group-hover:scale-110" />
                    ) : systemLogo ? (
                      <img src={systemLogo.startsWith('data:') ? systemLogo : `${logoBaseUrl}/${systemLogo}`} alt="Current Logo" className="w-full h-full object-contain p-4 transition-transform group-hover:scale-110" />
                    ) : (
                      <ImageIcon size={48} className="text-gray-300" />
                    )}
                    <div className="absolute inset-0 bg-brand-600/0 group-hover:bg-brand-600/5 transition-colors pointer-events-none" />
                  </div>
                  <span className="text-[10px] font-bold text-brand-600 uppercase tracking-widest">Active Preview</span>
                </div>
                
                <div className="flex-1 w-full space-y-6">
                  {/* Upload Controls */}
                  <div className="p-4 rounded-xl border border-gray-100 bg-gray-50/50">
                    <div className="flex items-start gap-4">
                      <div className="w-10 h-10 rounded-lg bg-white shadow-sm border border-gray-100 flex items-center justify-center shrink-0 text-brand-600">
                        <Upload size={18} />
                      </div>
                      <div className="flex-1">
                        <p className="text-xs font-semibold text-gray-900 mb-1">Upload New Logo</p>
                        <p className="text-[11px] text-gray-500 mb-3 leading-relaxed">PNG, JPG, SVG supported. Max 2MB. This will be used in emails and login screens.</p>
                        
                        <input
                          type="file"
                          ref={fileInputRef}
                          onChange={handleFileChange}
                          accept="image/*"
                          className="hidden"
                        />
                        
                        <button
                          type="button"
                          onClick={() => fileInputRef.current?.click()}
                          className="btn-primary-soft text-[11px] px-3 py-1.5 flex items-center gap-2"
                        >
                          {selectedFile ? 'Change File' : 'Choose File'}
                        </button>
                      </div>
                    </div>
                  </div>

                  {/* Existing Library */}
                  <div className="space-y-3">
                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Existing Logo Library</p>
                    <div className="grid grid-cols-4 sm:grid-cols-6 lg:grid-cols-8 gap-3">
                      {availableLogos.map((logo) => {
                        const isSelected = systemLogo === logo && !selectedFile
                        const isDefault = ['launchr_black.svg', 'launchr_logo.svg'].includes(logo)
                        return (
                          <div key={logo} className="relative group">
                            <button
                              type="button"
                              onClick={() => {
                                setSystemLogo(logo)
                                setLogoPreview(null)
                                setSelectedFile(null)
                              }}
                              className={`aspect-square w-full rounded-lg border-2 transition-all p-1.5 bg-white relative ${
                                isSelected
                                  ? 'border-brand-500 ring-2 ring-brand-500/20'
                                  : 'border-gray-100 hover:border-gray-300 shadow-sm'
                              }`}
                            >
                              <img
                                src={`${logoBaseUrl}/${logo}`}
                                alt={logo}
                                className="w-full h-full object-contain"
                              />
                              {isSelected && (
                                <div className="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-brand-500 text-white flex items-center justify-center shadow-sm">
                                  <Check size={10} strokeWidth={4} />
                                </div>
                              )}
                            </button>
                            {!isDefault && (
                              <button
                                type="button"
                                onClick={() => setConfirmConfig({
                                  open: true,
                                  title: 'Delete Logo',
                                  message: `Delete "${logo}"? This cannot be undone.`,
                                  type: 'danger',
                                  onConfirm: () => deleteLogoMutation.mutate(logo),
                                })}
                                className="absolute -top-1.5 -left-1.5 w-5 h-5 rounded-full bg-red-500 text-white items-center justify-center shadow-sm hidden group-hover:flex z-10"
                              >
                                <Trash2 size={10} strokeWidth={3} />
                              </button>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium text-gray-700">Payroll Excel Template</label>
                <div className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Active: {selectedTemplateFile ? 'New Upload' : payrollTemplate}</div>
              </div>
              
              <div className="p-4 rounded-xl border border-gray-100 bg-gray-50/50">
                <div className="flex items-start gap-4">
                  <div className="w-10 h-10 rounded-lg bg-white shadow-sm border border-gray-100 flex items-center justify-center shrink-0 text-brand-600">
                    <FileSpreadsheet size={18} />
                  </div>
                  <div className="flex-1">
                    <p className="text-xs font-semibold text-gray-900 mb-1">Upload Payroll Template</p>
                    <p className="text-[11px] text-gray-500 mb-3 leading-relaxed">XLSX, XLS supported. Max 5MB. This template will be used for all payroll exports.</p>
                    
                    <input
                      type="file"
                      ref={templateFileInputRef}
                      onChange={handleTemplateFileChange}
                      accept=".xlsx,.xls"
                      className="hidden"
                    />
                    
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={() => templateFileInputRef.current?.click()}
                        className="btn-primary-soft text-[11px] px-3 py-1.5 flex items-center gap-2"
                      >
                        {selectedTemplateFile ? 'Change File' : 'Choose File'}
                      </button>
                      {selectedTemplateFile && (
                        <span className="text-[10px] text-brand-600 font-medium truncate max-w-[200px] italic">
                          Selected: {selectedTemplateFile.name}
                        </span>
                      )}
                      {!selectedTemplateFile && (
                        <button 
                          type="button"
                          onClick={async () => {
                            try {
                              const response = await fetch(`${import.meta.env.VITE_API_BASE_URL}/payroll-template`, {
                                headers: { 'Authorization': `Bearer ${localStorage.getItem('hr_token')}` }
                              });
                              if (!response.ok) throw new Error('Download failed');
                              const blob = await response.blob();
                              const url = window.URL.createObjectURL(blob);
                              const a = document.createElement('a');
                              a.href = url;
                              a.download = payrollTemplate; // Use the actual filename
                              document.body.appendChild(a);
                              a.click();
                              a.remove();
                              window.URL.revokeObjectURL(url);
                            } catch (err) {
                              setAlertConfig({ open: true, title: 'Download Failed', message: 'Failed to download template. Please check if you are still logged in.', type: 'error' })
                            }
                          }}
                          className="text-[10px] text-gray-400 hover:text-brand-600 font-medium underline flex items-center gap-1 ml-auto"
                        >
                          Download Current Template
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="card p-6">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Authentication</h2>
          <div className="flex items-center justify-between p-4 border rounded-lg">
            <div>
              <p className="text-sm font-medium text-gray-900">Require OTP at login</p>
              <p className="text-xs text-gray-500">When disabled, users can sign in with email and password only.</p>
            </div>
            <button
              type="button"
              onClick={() => setRequireLoginOtp(!requireLoginOtp)}
              className={`w-12 h-6 rounded-full flex items-center p-1 transition-colors ${requireLoginOtp ? 'bg-brand-600' : 'bg-gray-300'}`}
            >
              <div className={`w-4 h-4 rounded-full bg-white transition-transform ${requireLoginOtp ? 'translate-x-6' : ''}`} />
            </button>
          </div>
        </div>

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

        <div className="card">
          <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600">
                <Palette size={20} />
              </div>
              <div>
                <h2 className="text-sm font-semibold text-gray-900">Visual Theme Configuration</h2>
                <p className="text-xs text-gray-500 mt-0.5">Customize the main system color theme across the application.</p>
              </div>
            </div>
          </div>
          <div className="p-6">
            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4">
              {themePresets.map((preset) => {
                const isSelected = themeColor === preset.id
                return (
                  <button
                    key={preset.id}
                    type="button"
                    onClick={() => setThemeColor(preset.id)}
                    className={`flex flex-col items-center gap-3 p-4 rounded-xl border transition-all ${
                      isSelected
                        ? 'border-brand-500 bg-brand-50/20 ring-2 ring-brand-500/20'
                        : 'border-gray-200 hover:border-gray-300 bg-white'
                    }`}
                  >
                    <div className={`w-8 h-8 rounded-full ${preset.colorClass} shadow-inner flex items-center justify-center`}>
                      {isSelected && (
                        <div className="w-2.5 h-2.5 rounded-full bg-white" />
                      )}
                    </div>
                    <span className="text-xs font-medium text-gray-700">{preset.name}</span>
                  </button>
                )
              })}
            </div>
          </div>
        </div>

        <div className="card">
          <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                <Calendar size={20} />
              </div>
              <div>
                <h2 className="text-sm font-semibold text-gray-900">Statutory Contribution Tables</h2>
                <p className="text-xs text-gray-500 mt-0.5">Update the SSS table annually to comply with new regulations.</p>
              </div>
            </div>
          </div>
          <div className="p-6">
            <div className="space-y-4">
              <div className="flex flex-col gap-2">
                <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">SSS Employee Contribution Table (JSON)</label>
                <textarea
                  value={sssTable}
                  onChange={(e) => setSssTable(e.target.value)}
                  className="input font-mono text-xs h-64 p-4 leading-relaxed"
                  placeholder='[{"min": 0, "max": 5000, "ee": 250}, ...]'
                />
                <p className="text-[10px] text-gray-400 italic">
                  Note: The JSON structure must include "min", "max", "msc", and "ee" fields.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="border-b border-gray-100 bg-gray-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center text-brand-600">
                <FileSpreadsheet size={20} />
              </div>
              <div>
                <h2 className="text-sm font-semibold text-gray-900">Payroll Configuration</h2>
                <p className="text-xs text-gray-500 mt-0.5">Set payroll frequency and pay period cutoff days.</p>
              </div>
            </div>
          </div>
          <div className="p-6 space-y-6">
            {payrollFrequency !== (settings.find(s => s.key === 'payroll_frequency')?.value ?? 'semi_monthly') && (
              <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
                <strong>Note:</strong> Changing payroll frequency will only affect future payroll runs. Existing draft payrolls must be deleted and regenerated.
              </div>
            )}
            <div className="space-y-2">
              <label className="text-sm font-medium text-gray-700">Payroll Frequency</label>
              <select value={payrollFrequency} onChange={(e) => setPayrollFrequency(e.target.value)} className="input h-11">
                <option value="semi_monthly">Semi-Monthly (twice a month)</option>
                <option value="monthly">Monthly (once a month)</option>
              </select>
            </div>
            {payrollFrequency === 'semi_monthly' ? (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period 1 Start Day</label>
                    <input type="number" min="1" max="31" value={p1Start} onChange={(e) => setP1Start(parseInt(e.target.value))} className="input h-10" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period 1 End Day</label>
                    <input type="number" min="1" max="31" value={p1End} onChange={(e) => setP1End(parseInt(e.target.value))} className="input h-10" />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period 2 Start Day</label>
                    <input type="number" min="1" max="31" value={p2Start} onChange={(e) => setP2Start(parseInt(e.target.value))} className="input h-10" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period 2 End Day</label>
                    <input type="number" min="1" max="31" value={p2End} onChange={(e) => setP2End(parseInt(e.target.value))} className="input h-10" />
                  </div>
                </div>
                <p className="text-[10px] text-gray-400 italic">Use 31 to always mean the last day of the month.</p>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period Start Day</label>
                    <input type="number" min="1" max="31" value={pMonthlyStart} onChange={(e) => setPMonthlyStart(parseInt(e.target.value))} className="input h-10" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-xs font-bold text-gray-400 uppercase tracking-wider">Period End Day</label>
                    <input type="number" min="1" max="31" value={pMonthlyEnd} onChange={(e) => setPMonthlyEnd(parseInt(e.target.value))} className="input h-10" />
                  </div>
                </div>
                <p className="text-[10px] text-gray-400 italic">Use 31 to always mean the last day of the month.</p>
              </div>
            )}
            <div className="rounded-lg border border-gray-100 overflow-hidden">
              <div className="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                <span className="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Preview — Next 3 Months</span>
              </div>
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-gray-100 bg-gray-50/50">
                    <th className="text-left px-4 py-2 font-medium text-gray-500">Period</th>
                    <th className="text-left px-4 py-2 font-medium text-gray-500">Start</th>
                    <th className="text-left px-4 py-2 font-medium text-gray-500">End</th>
                  </tr>
                </thead>
                <tbody>
                  {previewPeriods.map((row, i) => (
                    <tr key={i} className={`border-b border-gray-50 ${i % 2 === 0 ? '' : 'bg-gray-50/40'}`}>
                      <td className="px-4 py-2 text-gray-600">{row.period}</td>
                      <td className="px-4 py-2 text-gray-800 font-medium">{row.start}</td>
                      <td className="px-4 py-2 text-gray-800 font-medium">{row.end}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
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
