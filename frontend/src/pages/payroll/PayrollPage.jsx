import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { 
  getPayrolls, generatePayroll, updatePayroll, payrollKeys, 
  getSystemClock, systemClockKeys, sendPaystubs, revertPayrollToDraft, togglePayrollUndertimeCalculation
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, Spinner, AlertModal } from '../../components/ui/index.jsx'
import { Plus, Banknote, Calendar, ChevronLeft, ChevronRight, FileDown, CheckCircle, Download, Mail } from 'lucide-react'
import { getCutoffPeriod, getNextCutoff, getPrevCutoff } from '../../utils/attendance'
import ExcelJS from 'exceljs'

export default function PayrollPage() {
  const [activeCutoff, setActiveCutoff] = useState(null)
  const [selectedPayroll, setSelectedPayroll] = useState(null)
  const [isEditing, setIsEditing] = useState(false)
  const [showBreakdown, setShowBreakdown] = useState(false)
  const [editForm, setEditForm] = useState(null)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [selectedPaystubs, setSelectedPaystubs] = useState(new Set())
  const [ccEmails, setCcEmails] = useState([])
  const [bccEmails, setBccEmails] = useState([])
  const [ccInput, setCcInput] = useState('')
  const [bccInput, setBccInput] = useState('')
  const [ccHistory, setCcHistory] = useState(JSON.parse(localStorage.getItem('payroll_cc_history') || '[]'))
  const [bccHistory, setBccHistory] = useState(JSON.parse(localStorage.getItem('payroll_bcc_history') || '[]'))
  const [showToggleConfirm, setShowToggleConfirm] = useState(false)
  const [toggleSuccess, setToggleSuccess] = useState(null)
  const [alert, setAlert] = useState(null)
  const qc = useQueryClient()

  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
  })

  useEffect(() => {
    if (sysClock && activeCutoff === null) {
      setActiveCutoff(getCutoffPeriod(sysClock.date))
    }
  }, [sysClock, activeCutoff])

  const currentCutoff = activeCutoff || getCutoffPeriod(sysClock?.date || new Date())

  const { data: payrolls = [], isLoading } = useQuery({
    queryKey: payrollKeys.list({ 
      cutoff_start: currentCutoff.startDate, 
      cutoff_end: currentCutoff.endDate 
    }),
    queryFn: () => getPayrolls({ 
      cutoff_start: currentCutoff.startDate, 
      cutoff_end: currentCutoff.endDate 
    }),
    enabled: !!currentCutoff,
  })

  const generateMutation = useMutation({
    mutationFn: generatePayroll,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
    },
  })

  const updateStatusMutation = useMutation({
    mutationFn: ({ id, ...data }) => updatePayroll(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
      setSelectedPayroll(null)
      setIsEditing(false)
    },
    onError: (error) => {
      const message = error?.response?.data?.message || 'Failed to update payroll. Please try again.'
      setAlert({ type: 'error', message })
    }
  })

  const revertToDraftMutation = useMutation({
    mutationFn: (payrollId) => revertPayrollToDraft(payrollId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
      setSelectedPayroll(null)
      setIsEditing(false)
    },
    onError: (error) => {
      const message = error?.response?.data?.message || 'Failed to revert payroll. Please try again.'
      setAlert({ type: 'error', message })
    }
  })

  const toggleUndertimeMutation = useMutation({
    mutationFn: (payrollId) => togglePayrollUndertimeCalculation(payrollId),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
      setSelectedPayroll(data.data)
      setShowToggleConfirm(false)
      setToggleSuccess(data)
    },
    onError: (error) => {
      const message = error?.response?.data?.message || 'Failed to toggle undertime calculation. Please try again.'
      setAlert({ type: 'error', message })
    }
  })

  const sendPaystubsMutation = useMutation({
    mutationFn: (payrollIds) => sendPaystubs(payrollIds),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
      setShowEmailModal(false)
      setSelectedPaystubs(new Set())
      setCcEmails([])
      setBccEmails([])
      setCcInput('')
      setBccInput('')
      
      const message = data.message || 'Paystubs sent successfully!'
      setAlert({ type: 'success', message })
      
      if (data.data?.failed?.length > 0) {
        console.error('Failed to send paystubs:', data.data.failed)
      }
    },
    onError: (error) => {
      const message = error?.response?.data?.message || 'Failed to send paystubs. Please try again.'
      setAlert({ type: 'error', message })
    }
  })

  const handleEditInit = () => {
    setEditForm({
      ...selectedPayroll,
      allowances: Array.isArray(selectedPayroll.allowances) 
        ? selectedPayroll.allowances 
        : Object.entries(selectedPayroll.allowances || {}).map(([k, v]) => ({ label: k, amount: v })),
      deductions: Array.isArray(selectedPayroll.deductions)
        ? selectedPayroll.deductions
        : Object.entries(selectedPayroll.deductions || {}).map(([k, v]) => ({ label: k, amount: v })),
    })
    setIsEditing(true)
  }

  const handleAddField = (type, label = '') => {
    setEditForm(prev => ({
      ...prev,
      [type]: [...prev[type], { label, amount: 0 }]
    }))
  }

  const handleRemoveField = (type, index) => {
    setEditForm(prev => ({
      ...prev,
      [type]: prev[type].filter((_, i) => i !== index)
    }))
  }

  const handleFieldChange = (type, index, field, value) => {
    setEditForm(prev => {
      const next = { ...prev }
      if (type && index !== undefined) {
        next[type][index][field] = value
      } else if (field) {
        next[field] = value
      }
      
      const dRate = Number(next.daily_rate || 0)
      const hRate = dRate / 8

      // Auto-sync line items if metrics changed
      if (field === 'overtime_hours') {
        const item = next.allowances.find(a => a.label === 'Overtime Pay')
        if (item) item.amount = Math.round(Number(value) * hRate * 1.25 * 100) / 100
      }
      if (field === 'late_minutes') {
        const item = next.deductions.find(d => d.label === 'Late')
        if (item) item.amount = Math.round((Number(value) / 60) * hRate * 100) / 100
      }
      if (field === 'undertime_minutes') {
        const item = next.deductions.find(d => d.label === 'Undertime')
        if (item) item.amount = Math.round((Number(value) / 60) * hRate * 100) / 100
      }

      // Recalculate totals
      const totalAllowances = next.allowances.reduce((s, a) => s + Number(a.amount || 0), 0)
      const totalDeductions = next.deductions.reduce((s, d) => s + Number(d.amount || 0), 0)
      
      const isDaily = next.employee?.rate_type === 'daily'
      const baseGross = isDaily 
        ? (Number(next.base_salary) * Number(next.days_worked || 0))
        : (Number(next.base_salary) / 2)
      
      next.gross_pay = baseGross + totalAllowances
      next.net_pay = next.gross_pay - totalDeductions
      
      return next
    })
  }

  const handleSaveEdit = () => {
    updateStatusMutation.mutate({ 
      id: editForm.id, 
      status: editForm.status,
      base_salary: editForm.base_salary,
      days_worked: editForm.days_worked,
      gross_pay: editForm.gross_pay,
      net_pay: editForm.net_pay,
      allowances: editForm.allowances,
      deductions: editForm.deductions,
      total_hours: editForm.total_hours,
      overtime_hours: editForm.overtime_hours,
      late_minutes: editForm.late_minutes,
      undertime_minutes: editForm.undertime_minutes,
    })
  }

  const moveCutoff = (delta) => {
    if (delta > 0) setActiveCutoff(getNextCutoff(currentCutoff))
    else setActiveCutoff(getPrevCutoff(currentCutoff))
  }

  const togglePaystubSelection = (payrollId) => {
    const newSelected = new Set(selectedPaystubs)
    if (newSelected.has(payrollId)) {
      newSelected.delete(payrollId)
    } else {
      newSelected.add(payrollId)
    }
    setSelectedPaystubs(newSelected)
  }

  const toggleSelectAllPaystubs = (allPaystubIds) => {
    if (selectedPaystubs.size === allPaystubIds.length) {
      setSelectedPaystubs(new Set())
    } else {
      setSelectedPaystubs(new Set(allPaystubIds))
    }
  }

  const addCcEmail = (email) => {
    if (email && !ccEmails.includes(email)) {
      setCcEmails([...ccEmails, email])
      setCcInput('')
    }
  }

  const removeCcEmail = (email) => {
    setCcEmails(ccEmails.filter(e => e !== email))
  }

  const addBccEmail = (email) => {
    if (email && !bccEmails.includes(email)) {
      setBccEmails([...bccEmails, email])
      setBccInput('')
    }
  }

  const removeBccEmail = (email) => {
    setBccEmails(bccEmails.filter(e => e !== email))
  }

  const saveCcBccHistory = () => {
    const allCc = [...new Set([...ccEmails, ...ccHistory])].slice(0, 10)
    const allBcc = [...new Set([...bccEmails, ...bccHistory])].slice(0, 10)
    localStorage.setItem('payroll_cc_history', JSON.stringify(allCc))
    localStorage.setItem('payroll_bcc_history', JSON.stringify(allBcc))
  }

  const handleSendPaystubs = async () => {
    if (selectedPaystubs.size === 0) {
      setAlert({ type: 'warning', message: 'Please select at least one paystub to send.' })
      return
    }

    // Save CC/BCC history before sending
    if (ccEmails.length > 0 || bccEmails.length > 0) {
      saveCcBccHistory()
    }

    const payrollIds = Array.from(selectedPaystubs)
    const selectedPayrollObjs = payrolls.filter(p => payrollIds.includes(p.id))

    try {
      // Generate Excel files for each selected payroll
      const formData = new FormData()
      
      // Add payroll IDs
      payrollIds.forEach((id, index) => {
        formData.append(`payroll_ids[${index}]`, id)
      })

      // Add CC and BCC emails
      ccEmails.forEach((email, index) => {
        formData.append(`cc_emails[${index}]`, email)
      })
      bccEmails.forEach((email, index) => {
        formData.append(`bcc_emails[${index}]`, email)
      })

      // Generate and add Excel files
      for (let i = 0; i < selectedPayrollObjs.length; i++) {
        const payroll = selectedPayrollObjs[i]
        const response = await fetch('/synctalents_payrolltemplate.xlsx')
        const arrayBuffer = await response.arrayBuffer()
        
        const workbook = new ExcelJS.Workbook()
        await workbook.xlsx.load(arrayBuffer)
        const worksheet = workbook.worksheets[0]

        // Build employee details (same as export)
        const employeeName = `${payroll.employee?.first_name || ''} ${payroll.employee?.last_name || ''}`.trim()
        const payPeriod = `${format(parseISO(payroll.cutoff_start), 'MMM dd, yyyy')} - ${format(parseISO(payroll.cutoff_end), 'MMM dd, yyyy')}`
        const totalDeductions = (payroll.gross_pay || 0) - (payroll.net_pay || 0)
        
        const getScheduleDisplay = () => {
          if (!payroll.employee?.schedule) return ''
          const sched = payroll.employee.schedule
          if (typeof sched === 'object') {
            const { days, start_time, end_time } = sched
            if (days && start_time && end_time) {
              return `${days} (${start_time} - ${end_time})`
            }
            return sched.name || ''
          }
          return sched
        }
        
        const scheduleDisplay = getScheduleDisplay()

        const getEarningsAmount = (label) => {
          if (!payroll.allowances) return 0
          const earning = Array.isArray(payroll.allowances) 
            ? payroll.allowances.find(a => a?.label === label)
            : null
          return earning?.amount ? Number(earning.amount) : 0
        }

        const getDeductionAmount = (label) => {
          if (!payroll.deductions) return 0
          if (Array.isArray(payroll.deductions)) {
            const deduction = payroll.deductions.find(d => d?.label === label)
            return deduction?.amount ? Number(deduction.amount) : 0
          }
          return payroll.deductions[label] ? Number(payroll.deductions[label]) : 0
        }

        const totalAllowancesAmount = payroll.allowances?.reduce((sum, a) => sum + Number(a.amount || 0), 0) || 0
        const basicIncomeAmount = payroll.gross_pay - totalAllowancesAmount
        const overtimeAmount = getEarningsAmount('Overtime Pay')
        const restDayPayAmount = getEarningsAmount('Rest Day Pay')
        const restDayOTPayAmount = getEarningsAmount('Rest Day OT Pay')

        const dRate = Number(payroll.daily_rate) || 0
        const hRate = dRate / 8

        const restDayPayHours = restDayPayAmount > 0 && hRate > 0 ? restDayPayAmount / (hRate * 1.30) : 0
        const restDayOTPayHours = restDayOTPayAmount > 0 && hRate > 0 ? restDayOTPayAmount / (hRate * 1.69) : 0

        const nightDiffAmount = getEarningsAmount('Night Differential')
        const nightDiffHours = nightDiffAmount > 0 && hRate > 0 ? nightDiffAmount / (hRate * 0.10) : 0

        const specialHolidayAmount = getEarningsAmount('Special Holiday')
        const specialHolidayHours = specialHolidayAmount > 0 && hRate > 0 ? specialHolidayAmount / (hRate * 0.30) : 0

        const legalHolidayAmount = getEarningsAmount('Legal Holiday')
        const legalHolidayHours = legalHolidayAmount > 0 && hRate > 0 ? legalHolidayAmount / (hRate * 1.00) : 0

        const absentAmount = getDeductionAmount('Absent')
        const absentDays = absentAmount > 0 && dRate > 0 ? absentAmount / dRate : 0

        const halfDayAmount = getDeductionAmount('Half Day')
        const halfDayDays = halfDayAmount > 0 && dRate > 0 ? halfDayAmount / (dRate / 2) : 0

        const otherAllowances = payroll.allowances?.filter(a => !['Overtime Pay', 'Rest Day Pay', 'Rest Day OT Pay', 'Night Differential', 'Special Holiday', 'Legal Holiday'].includes(a.label)) || []
        const customAllowancesAmount = otherAllowances.reduce((sum, a) => sum + Number(a.amount || 0), 0)

        const fieldsMap = {
          'E13': employeeName,
          'E15': payroll.employee?.phone || payroll.employee?.contact_info || '',
          'E17': scheduleDisplay,
          'E19': payPeriod,
          'L10': Number(payroll.net_pay) || 0,
          'L13': payroll.employee?.sss_number || '',
          'L15': payroll.employee?.philhealth_number || '',
          'L17': payroll.employee?.pagibig_number || '',
          'L19': payroll.employee?.bank_account_number || payroll.employee?.tin_number || '',
          'F27': payroll.days_worked ? `${payroll.days_worked}d` : '0d',
          'F28': payroll.overtime_hours ? `${payroll.overtime_hours}h` : '0h',
          'F29': restDayPayHours ? `${Number(restDayPayHours).toFixed(2)}h` : '0h',
          'F30': restDayOTPayHours ? `${Number(restDayOTPayHours).toFixed(2)}h` : '0h',
          'F31': nightDiffHours ? `${Number(nightDiffHours).toFixed(2)}h` : '0h',
          'G27': Number(basicIncomeAmount) || 0,
          'G28': Number(overtimeAmount) || 0,
          'G29': Number(restDayPayAmount) || 0,
          'G30': Number(restDayOTPayAmount) || 0,
          'G31': Number(nightDiffAmount) || 0,
          'G32': Number(specialHolidayAmount) || 0,
          'G33': Number(legalHolidayAmount) || 0,
          'G34': 0,
          'G35': Number(customAllowancesAmount) || 0,
          'G36': 0,
          'G39': Number(payroll.gross_pay) || 0,
          'N27': ((payroll.late_minutes || 0) + (payroll.undertime_minutes || 0)) ? `${(payroll.late_minutes || 0) + (payroll.undertime_minutes || 0)}m` : '0m',
          'N28': absentDays ? `${Number(absentDays).toFixed(2)}d` : '0d',
          'N29': halfDayDays ? `${Number(halfDayDays).toFixed(2)}d` : '0d',
          'O27': Number(getDeductionAmount('Late') + getDeductionAmount('Undertime')) || 0,
          'O28': Number(getDeductionAmount('Absent')) || 0,
          'O29': Number(getDeductionAmount('Half Day')) || 0,
          'O30': Number(getDeductionAmount('SSS EE Contribution')) || 0,
          'O31': Number(getDeductionAmount('PhilHealth EE Contribution')) || 0,
          'O32': Number(getDeductionAmount('Pag-IBIG EE Contribution')) || 0,
          'O33': 0,
          'O34': 0,
          'O35': 0,
          'O36': 0,
          'O39': Number(totalDeductions) || 0,
          'O40': Number(payroll.net_pay) || 0,
        }

        const currencyCells = [
          'L10', 'G27', 'G28', 'G29', 'G30', 'G31', 'G32', 'G33', 'G34', 'G35', 'G36', 'G39',
          'O27', 'O28', 'O29', 'O30', 'O31', 'O32', 'O33', 'O34', 'O35', 'O36', 'O39', 'O40'
        ]

        Object.entries(fieldsMap).forEach(([cell, value]) => {
          const cellRef = worksheet.getCell(cell)
          if (cellRef) {
            cellRef.value = value
            if (currencyCells.includes(cell)) {
              cellRef.numFmt = '"₱"#,##0.00;-"₱"#,##0.00'
            }
          }
        })

        // Generate blob and add to FormData
        const buffer = await workbook.xlsx.writeBuffer()
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
        const filename = `payroll-${payroll.employee?.first_name}-${payroll.employee?.last_name}-${format(parseISO(payroll.cutoff_end), 'MMM-dd-yyyy')}.xlsx`
        formData.append(`files[${i}]`, blob, filename)
      }

      // Send to backend
      sendPaystubsMutation.mutate(formData)
    } catch (error) {
      console.error('Error generating paystubs:', error)
      setAlert({ type: 'error', message: 'Failed to generate paystubs. Please try again.' })
    }
  }

  const finalizedPaystubs = payrolls.filter(p => p.status === 'finalized')

  const exportPayrollToExcel = async (payroll) => {
    try {
      // Fetch the template file
      const response = await fetch('/synctalents_payrolltemplate.xlsx')
      const arrayBuffer = await response.arrayBuffer()
      
      // Load template into workbook
      const workbook = new ExcelJS.Workbook()
      await workbook.xlsx.load(arrayBuffer)
      
      // Get the first worksheet
      const worksheet = workbook.worksheets[0]

      // Build employee details
      const employeeName = `${payroll.employee?.first_name || ''} ${payroll.employee?.last_name || ''}`.trim()
      const payPeriod = `${format(parseISO(payroll.cutoff_start), 'MMM dd, yyyy')} - ${format(parseISO(payroll.cutoff_end), 'MMM dd, yyyy')}`
      const totalDeductions = (payroll.gross_pay || 0) - (payroll.net_pay || 0)
      
      // Get schedule from schedule template (e.g., "Monday to Friday (9 AM - 6 PM)")
      const getScheduleDisplay = () => {
        if (!payroll.employee?.schedule) return ''
        const sched = payroll.employee.schedule
        
        // If it's an object with properties
        if (typeof sched === 'object') {
          const { days, start_time, end_time } = sched
          if (days && start_time && end_time) {
            return `${days} (${start_time} - ${end_time})`
          }
          return sched.name || ''
        }
        // If it's a string, return as-is
        return sched
      }
      
      const scheduleDisplay = getScheduleDisplay()

      // Extract earnings from allowances array
      const getEarningsAmount = (label) => {
        if (!payroll.allowances) return 0
        const earning = Array.isArray(payroll.allowances) 
          ? payroll.allowances.find(a => a?.label === label)
          : null
        return earning?.amount ? Number(earning.amount) : 0
      }

      // Extract deductions from deductions object/array
      const getDeductionAmount = (label) => {
        if (!payroll.deductions) return 0
        if (Array.isArray(payroll.deductions)) {
          const deduction = payroll.deductions.find(d => d?.label === label)
          return deduction?.amount ? Number(deduction.amount) : 0
        }
        // If it's an object
        return payroll.deductions[label] ? Number(payroll.deductions[label]) : 0
      }

      const totalAllowancesAmount = payroll.allowances?.reduce((sum, a) => sum + Number(a.amount || 0), 0) || 0
      const basicIncomeAmount = payroll.gross_pay - totalAllowancesAmount
      const overtimeAmount = getEarningsAmount('Overtime Pay')
      const restDayPayAmount = getEarningsAmount('Rest Day Pay')
      const restDayOTPayAmount = getEarningsAmount('Rest Day OT Pay')

      const dRate = Number(payroll.daily_rate) || 0
      const hRate = dRate / 8

      const restDayPayHours = restDayPayAmount > 0 && hRate > 0 ? restDayPayAmount / (hRate * 1.30) : 0
      const restDayOTPayHours = restDayOTPayAmount > 0 && hRate > 0 ? restDayOTPayAmount / (hRate * 1.69) : 0

      const nightDiffAmount = getEarningsAmount('Night Differential')
      const nightDiffHours = nightDiffAmount > 0 && hRate > 0 ? nightDiffAmount / (hRate * 0.10) : 0

      const specialHolidayAmount = getEarningsAmount('Special Holiday')
      const specialHolidayHours = specialHolidayAmount > 0 && hRate > 0 ? specialHolidayAmount / (hRate * 0.30) : 0

      const legalHolidayAmount = getEarningsAmount('Legal Holiday')
      const legalHolidayHours = legalHolidayAmount > 0 && hRate > 0 ? legalHolidayAmount / (hRate * 1.00) : 0

      const absentAmount = getDeductionAmount('Absent')
      const absentDays = absentAmount > 0 && dRate > 0 ? absentAmount / dRate : 0

      const halfDayAmount = getDeductionAmount('Half Day')
      const halfDayDays = halfDayAmount > 0 && dRate > 0 ? halfDayAmount / (dRate / 2) : 0

      const otherAllowances = payroll.allowances?.filter(a => !['Overtime Pay', 'Rest Day Pay', 'Rest Day OT Pay', 'Night Differential', 'Special Holiday', 'Legal Holiday'].includes(a.label)) || []
      const customAllowancesAmount = otherAllowances.reduce((sum, a) => sum + Number(a.amount || 0), 0)

      // Map payroll data to template cells
      const fieldsMap = {
        // Employee Details
        'E13': employeeName,
        'E15': payroll.employee?.phone || payroll.employee?.contact_info || '', // Phone number
        'E17': scheduleDisplay, // Schedule from template (e.g., Monday to Friday (9 AM - 6 PM))
        'E19': payPeriod,
        'L10': Number(payroll.net_pay) || 0,
        'L13': payroll.employee?.sss_number || '',
        'L15': payroll.employee?.philhealth_number || '',
        'L17': payroll.employee?.pagibig_number || '',
        'L19': payroll.employee?.bank_account_number || payroll.employee?.tin_number || '', // Bank account number
        
        // Earnings - Days/Hrs (F column)
        'F27': payroll.days_worked ? `${payroll.days_worked}d` : '0d',
        'F28': payroll.overtime_hours ? `${payroll.overtime_hours}h` : '0h',
        'F29': restDayPayHours ? `${Number(restDayPayHours).toFixed(2)}h` : '0h',
        'F30': restDayOTPayHours ? `${Number(restDayOTPayHours).toFixed(2)}h` : '0h',
        'F31': nightDiffHours ? `${Number(nightDiffHours).toFixed(2)}h` : '0h',
        
        // Earnings - Amount (G column)
        'G27': Number(basicIncomeAmount) || 0,
        'G28': Number(overtimeAmount) || 0,
        'G29': Number(restDayPayAmount) || 0,
        'G30': Number(restDayOTPayAmount) || 0,
        'G31': Number(nightDiffAmount) || 0,
        'G32': Number(specialHolidayAmount) || 0,
        'G33': Number(legalHolidayAmount) || 0,
        'G34': 0, // 13th Month Pay
        'G35': Number(customAllowancesAmount) || 0, // Allowances Amount
        'G36': 0, // Incentives/Others Amount
        'G39': Number(payroll.gross_pay) || 0,
        
        // Deductions - Days/Hrs (N column)
        'N27': ((payroll.late_minutes || 0) + (payroll.undertime_minutes || 0)) ? `${(payroll.late_minutes || 0) + (payroll.undertime_minutes || 0)}m` : '0m',
        'N28': absentDays ? `${Number(absentDays).toFixed(2)}d` : '0d',
        'N29': halfDayDays ? `${Number(halfDayDays).toFixed(2)}d` : '0d',
        
        // Deductions - Amount (O column)
        'O27': Number(getDeductionAmount('Late') + getDeductionAmount('Undertime')) || 0,
        'O28': Number(getDeductionAmount('Absent')) || 0,
        'O29': Number(getDeductionAmount('Half Day')) || 0,
        'O30': Number(getDeductionAmount('SSS EE Contribution')) || 0,
        'O31': Number(getDeductionAmount('PhilHealth EE Contribution')) || 0,
        'O32': Number(getDeductionAmount('Pag-IBIG EE Contribution')) || 0,
        'O33': 0, // Withholding Tax
        'O34': 0, // SSS Loan
        'O35': 0, // Pag-IBIG Loan
        'O36': 0, // Cash Advance/Others
        'O39': Number(totalDeductions) || 0,
        'O40': Number(payroll.net_pay) || 0,
      }

      // Currency cells that need Philippine peso formatting
      const currencyCells = [
        'L10', // Net Pay
        'G27', 'G28', 'G29', 'G30', 'G31', 'G32', 'G33', 'G34', 'G35', 'G36', 'G39', // Earnings amounts
        'O27', 'O28', 'O29', 'O30', 'O31', 'O32', 'O33', 'O34', 'O35', 'O36', 'O39', 'O40' // Deductions amounts
      ]

      // Apply data to template cells
      Object.entries(fieldsMap).forEach(([cell, value]) => {
        const cellRef = worksheet.getCell(cell)
        if (cellRef) {
          cellRef.value = value
          
          // Format currency cells as Philippine peso
          if (currencyCells.includes(cell)) {
            cellRef.numFmt = '"₱"#,##0.00;-"₱"#,##0.00'
          }
        }
      })

      // Generate file
      const buffer = await workbook.xlsx.writeBuffer()
      const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `payroll-${payroll.employee?.first_name}-${payroll.employee?.last_name}-${format(parseISO(payroll.cutoff_end), 'MMM-dd-yyyy')}.xlsx`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Export error details:', error)
      setAlert({ type: 'error', message: 'Failed to export payroll. Please try again.' })
    }
  }

  const getEEContrib = (label) => {
    if (!selectedPayroll) return 0
    const dList = isEditing && editForm ? editForm.deductions : (Array.isArray(selectedPayroll.deductions) ? selectedPayroll.deductions : Object.entries(selectedPayroll.deductions || {}).map(([k, v]) => ({ label: k, amount: v })))
    if (!dList) return 0
    const item = dList.find(d => d.label === label)
    return item ? Number(item.amount) : 0
  }
  
  const sssER = getEEContrib('SSS EE Contribution')
  const phicER = getEEContrib('PhilHealth EE Contribution')
  const hdmfER = getEEContrib('Pag-IBIG EE Contribution')
  const totalER = sssER + phicER + hdmfER

  const totals = useMemo(() => {
    return payrolls.reduce((acc, p) => ({
      gross: acc.gross + Number(p.gross_pay),
      net: acc.net + Number(p.net_pay),
      count: acc.count + 1
    }), { gross: 0, net: 0, count: 0 })
  }, [payrolls])

  return (
    <div className="space-y-5">
      <PageHeader
        title="Payroll Processing"
        description="Calculate salaries and manage disbursements"
        action={
          <div className="flex items-center gap-2">
            <button 
              onClick={() => moveCutoff(-1)}
              className="btn-secondary p-2"
            >
              <ChevronLeft size={16} />
            </button>
            <div className="flex items-center gap-2 bg-white border border-gray-200 px-4 py-2 rounded-lg shadow-sm">
              <Calendar size={14} className="text-brand-600" />
              <span className="text-sm font-semibold text-gray-700 min-w-[160px] text-center">
                {currentCutoff.label}
              </span>
            </div>
            <button 
              onClick={() => moveCutoff(1)}
              className="btn-secondary p-2"
            >
              <ChevronRight size={16} />
            </button>
          </div>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="card p-4 flex flex-col justify-between">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Employees</p>
          <p className="text-2xl font-bold text-gray-900 mt-1">{totals.count}</p>
        </div>
        <div className="card p-4 flex flex-col justify-between border-l-4 border-brand-500">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Gross Pay</p>
          <p className="text-2xl font-bold text-brand-600 mt-1">₱{totals.gross.toLocaleString()}</p>
        </div>
        <div className="card p-4 flex flex-col justify-between border-l-4 border-green-500">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Net Pay</p>
          <p className="text-2xl font-bold text-green-600 mt-1">₱{totals.net.toLocaleString()}</p>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2 bg-gray-50/50">
          <h3 className="text-sm font-semibold text-gray-700">Cutoff Details</h3>
          <div className="flex gap-2">
            <button 
              disabled={generateMutation.isPending}
              onClick={() => generateMutation.mutate({ 
                cutoff_start: currentCutoff.startDate, 
                cutoff_end: currentCutoff.endDate 
              })}
              className="btn-primary py-2 text-xs"
            >
              {generateMutation.isPending ? <Spinner size="sm" /> : <><Plus size={14} /> Generate for Period</>}
            </button>
            <button 
              disabled={finalizedPaystubs.length === 0 || sendPaystubsMutation.isPending}
              onClick={() => {
                setShowEmailModal(true)
                setCcEmails([])
                setBccEmails([])
                setCcInput('')
                setBccInput('')
              }}
              className="btn-primary py-2 text-xs bg-purple-600 hover:bg-purple-700 border-purple-600"
              title={finalizedPaystubs.length === 0 ? 'No finalized paystubs to send' : ''}
            >
              {sendPaystubsMutation.isPending ? <Spinner size="sm" /> : <><Mail size={14} /> Email Paystub</>}
            </button>
          </div>
        </div>

        {isLoading ? (
          <PageSpinner />
        ) : payrolls.length === 0 ? (
          <div className="py-20 flex flex-col items-center justify-center text-center">
            <Banknote size={48} className="text-gray-100 mb-4" />
            <p className="text-sm font-medium text-gray-500">No payroll records for this cutoff</p>
            <p className="text-xs text-gray-400 mt-1">Click "Generate" above to calculate employee salaries</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50/50 text-left">
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Employee</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Base Salary</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider text-center">Hours</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider text-center">OT/Late</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Gross Pay</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Net Pay</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {payrolls.map(p => (
                  <tr key={p.id} className="hover:bg-gray-50 group">
                    <td className="px-5 py-3">
                      <p className="font-semibold text-gray-900">{p.employee?.first_name} {p.employee?.last_name}</p>
                      <p className="text-[10px] text-gray-400 uppercase font-bold">{p.employee?.position}</p>
                    </td>
                    <td className="px-5 py-3 text-gray-600">₱{Number(p.base_salary).toLocaleString()}</td>
                    <td className="px-5 py-3 text-center text-gray-600 font-mono">
                      <div className="flex flex-col">
                        <span>{Number(p.total_hours).toFixed(1)}h</span>
                        <span className="text-[10px] text-gray-400 font-bold">{p.days_worked} Days</span>
                      </div>
                    </td>
                    <td className="px-5 py-3 text-center">
                      <div className="flex flex-col gap-0.5">
                        <span className="text-[10px] text-blue-600 font-bold">OT: {Number(p.overtime_hours).toFixed(1)}h</span>
                        <span className="text-[10px] text-red-500 font-bold">Late: {p.late_minutes}m</span>
                      </div>
                    </td>
                    <td className="px-5 py-3 font-semibold text-gray-900">₱{Number(p.gross_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td className="px-5 py-3 font-bold text-brand-600">₱{Number(p.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button 
                          onClick={() => setSelectedPayroll(p)}
                          className="p-1.5 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-md transition-colors"
                          title="View Details"
                        >
                          <FileDown size={16} />
                        </button>
                        {p.status === 'draft' && (
                          <button 
                            onClick={() => updateStatusMutation.mutate({ id: p.id, status: 'finalized' })}
                            className="p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded-md transition-colors"
                            title="Finalize"
                          >
                            <CheckCircle size={16} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={!!selectedPayroll}
        onClose={() => { setSelectedPayroll(null); setIsEditing(false) }}
        title={isEditing ? "Edit Payroll" : "Payroll Details"}
        size="lg"
        headerAction={
          !isEditing && selectedPayroll && (
            <button 
              onClick={() => exportPayrollToExcel(selectedPayroll)}
              className="btn-secondary py-2 px-3 text-xs flex items-center gap-2"
              title="Export to Excel"
            >
              <Download size={14} /> Export
            </button>
          )
        }
        footer={selectedPayroll && (
          <div className="flex flex-col w-full gap-4">
            <div className="flex justify-between items-center bg-brand-50 p-4 rounded-xl border border-brand-100">
              <div>
                <p className="text-[10px] text-brand-500 uppercase font-bold tracking-widest">Net Disbursement</p>
                <p className="text-2xl font-black text-brand-700">
                  ₱{(isEditing ? editForm.net_pay : selectedPayroll.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </p>
              </div>
              <div className="text-right">
                <p className="text-[10px] text-gray-400 uppercase font-bold">Gross Pay</p>
                <p className="text-sm font-bold text-gray-600">
                  ₱{(isEditing ? editForm.gross_pay : selectedPayroll.gross_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </p>
              </div>
            </div>
            
            <div className="flex gap-2">
              {isEditing ? (
                <>
                  <button 
                    disabled={updateStatusMutation.isPending}
                    onClick={handleSaveEdit}
                    className="btn-primary flex-1 py-3"
                  >
                    {updateStatusMutation.isPending ? <Spinner size="sm" /> : 'Save Changes'}
                  </button>
                  <button onClick={() => setIsEditing(false)} className="btn-secondary px-6">Cancel</button>
                </>
              ) : (
                <>
                  {selectedPayroll.status === 'draft' && (
                    <button 
                      onClick={() => updateStatusMutation.mutate({ id: selectedPayroll.id, status: 'finalized' })}
                      className="btn-primary flex-1 py-3"
                    >
                      Finalize Payroll
                    </button>
                  )}
                  {selectedPayroll.status === 'finalized' && (
                    <div className="flex gap-2 flex-1">
                      <button 
                        onClick={() => updateStatusMutation.mutate({ id: selectedPayroll.id, status: 'paid' })}
                        className="btn-primary flex-1 py-3 bg-green-600 hover:bg-green-700 border-green-600"
                      >
                        Mark as Paid
                      </button>
                      <button 
                        onClick={() => revertToDraftMutation.mutate(selectedPayroll.id)}
                        className="btn-secondary py-3 bg-yellow-500 hover:bg-yellow-600 border-yellow-500 text-white"
                        title="Revert to draft status for editing"
                      >
                        Revert to Draft
                      </button>
                    </div>
                  )}
                  <button onClick={() => setSelectedPayroll(null)} className="btn-secondary px-6">Close</button>
                </>
              )}
            </div>
          </div>
        )}
      >
        {selectedPayroll && (
          <div className="space-y-6">
            <div className="flex justify-between items-start">
              <div>
                <h4 className="text-lg font-bold text-gray-900">{selectedPayroll.employee?.first_name} {selectedPayroll.employee?.last_name}</h4>
                <p className="text-xs text-gray-500">{selectedPayroll.employee?.position} • {selectedPayroll.employee?.department}</p>
              </div>
              <div className="flex flex-col items-end gap-2">
                <StatusBadge status={isEditing ? editForm.status : selectedPayroll.status} />
                {!isEditing && selectedPayroll.status === 'draft' && (
                  <button onClick={handleEditInit} className="text-brand-600 text-xs font-bold hover:underline flex items-center gap-1">
                    <Plus size={12} /> Edit Fields
                  </button>
                )}
                {!isEditing && selectedPayroll.undeclared_salary && selectedPayroll.status === 'draft' && (
                  <button 
                    onClick={() => setShowToggleConfirm(true)}
                    className="text-blue-600 text-xs font-bold hover:underline"
                    title="Toggle undertime deduction salary"
                  >
                    Switch Salary
                  </button>
                )}
                {!isEditing && (selectedPayroll.status === 'finalized' || selectedPayroll.status === 'paid') && (
                  <p className="text-xs text-gray-400 italic">This payroll is locked</p>
                )}
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Earnings</p>
                  {isEditing && (
                    <select 
                      onChange={(e) => {
                        if (e.target.value === 'custom') handleAddField('allowances')
                        else if (e.target.value) handleAddField('allowances', e.target.value)
                        e.target.value = ''
                      }}
                      className="text-[10px] text-brand-600 font-bold bg-brand-50 hover:bg-brand-100 px-2 py-1 rounded border-none focus:ring-0"
                    >
                      <option value="">+ Add Item</option>
                      <option value="Overtime Pay">Overtime Pay</option>
                      <option value="Rest Day Pay">Rest Day Pay</option>
                      <option value="Rest Day OT Pay">Rest Day OT Pay</option>
                      <option value="Night Differential">Night Differential</option>
                      <option value="Special Holiday">Special Holiday</option>
                      <option value="Legal Holiday">Legal Holiday</option>
                      <option value="Bonus">Bonus</option>
                      <option value="Travel Allowance">Travel Allowance</option>
                      <option value="custom">Other (Custom)</option>
                    </select>
                  )}
                </div>
                
                <div className="space-y-2">
                  <div className="flex justify-between items-center text-sm p-3 bg-gray-50 rounded-lg border border-gray-100">
                    <span className="text-gray-600">
                      { (isEditing ? editForm.employee?.rate_type : selectedPayroll.employee?.rate_type) === 'daily' 
                        ? `Base Pay (${isEditing ? editForm.days_worked : selectedPayroll.days_worked} Days)`
                        : 'Base Salary (Half)'
                      }
                    </span>
                    {isEditing ? (
                      <div className="flex items-center gap-1">
                        <span className="text-gray-400">₱</span>
                        <input 
                          type="number" 
                          className="w-24 text-right bg-transparent font-semibold focus:outline-none"
                          value={editForm.base_salary}
                          onChange={(e) => handleFieldChange(null, null, 'base_salary', e.target.value)}
                        />
                      </div>
                    ) : (
                      <span className="font-semibold text-gray-900">
                        ₱{ (selectedPayroll.employee?.rate_type === 'daily' 
                            ? (Number(selectedPayroll.base_salary) * Number(selectedPayroll.days_worked))
                            : (Number(selectedPayroll.base_salary) / 2)
                           ).toLocaleString() }
                      </span>
                    )}
                  </div>

                  {(isEditing ? editForm.allowances : (Array.isArray(selectedPayroll.allowances) ? selectedPayroll.allowances : Object.entries(selectedPayroll.allowances || {}).map(([k, v]) => ({ label: k, amount: v })))).map((item, idx) => (
                    <div key={idx} className="flex justify-between items-center text-sm p-3 bg-blue-50/50 rounded-lg border border-blue-100 group">
                      {isEditing ? (
                        <>
                          <input 
                            className="bg-transparent text-blue-700 placeholder:text-blue-300 focus:outline-none flex-1 mr-2"
                            value={item.label}
                            placeholder="Description"
                            onChange={(e) => handleFieldChange('allowances', idx, 'label', e.target.value)}
                          />
                          <div className="flex items-center gap-2">
                            <input 
                              type="number" 
                              className="w-20 text-right bg-transparent font-semibold focus:outline-none"
                              value={item.amount}
                              onChange={(e) => handleFieldChange('allowances', idx, 'amount', e.target.value)}
                            />
                            <button onClick={() => handleRemoveField('allowances', idx)} className="text-red-400 hover:text-red-600">
                              <Plus size={14} className="rotate-45" />
                            </button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="text-blue-700 capitalize">
                            {item.label.replace('_', ' ')}
                            {item.label === 'Night Differential' && ` (${(item.amount / ((Number(payroll.daily_rate) / 8) * 0.10) || 0).toFixed(1)}h)`}
                            {item.label === 'Special Holiday' && ` (${(item.amount / ((Number(payroll.daily_rate) / 8) * 0.30) || 0).toFixed(1)}h)`}
                            {item.label === 'Legal Holiday' && ` (${(item.amount / ((Number(payroll.daily_rate) / 8) * 1.00) || 0).toFixed(1)}h)`}
                          </span>
                          <span className="font-semibold text-blue-800">+₱{Number(item.amount).toLocaleString()}</span>
                        </>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Deductions</p>
                  {isEditing && (
                    <select 
                      onChange={(e) => {
                        if (e.target.value === 'custom') handleAddField('deductions')
                        else if (e.target.value) handleAddField('deductions', e.target.value)
                        e.target.value = ''
                      }}
                      className="text-[10px] text-red-600 font-bold bg-red-50 hover:bg-red-100 px-2 py-1 rounded border-none focus:ring-0"
                    >
                      <option value="">+ Add Item</option>
                      <option value="Late">Late</option>
                      <option value="Undertime">Undertime</option>
                      <option value="Absent">Absent</option>
                      <option value="Half Day">Half Day</option>
                      <option value="SSS EE Contribution">SSS EE Contribution</option>
                      <option value="PhilHealth EE Contribution">PhilHealth EE Contribution</option>
                      <option value="Pag-IBIG EE Contribution">Pag-IBIG EE Contribution</option>
                      <option value="Cash Advance">Cash Advance</option>
                      <option value="custom">Other (Custom)</option>
                    </select>
                  )}
                </div>
                
                <div className="space-y-2">
                  {(isEditing ? editForm.deductions : (Array.isArray(selectedPayroll.deductions) ? selectedPayroll.deductions : Object.entries(selectedPayroll.deductions || {}).map(([k, v]) => ({ label: k, amount: v })))).map((item, idx) => (
                    <div key={idx} className="flex justify-between items-center text-sm p-3 bg-red-50/50 rounded-lg border border-red-100 group">
                      {isEditing ? (
                        <>
                          <input 
                            className="bg-transparent text-red-700 placeholder:text-red-300 focus:outline-none flex-1 mr-2"
                            value={item.label}
                            placeholder="Description"
                            onChange={(e) => handleFieldChange('deductions', idx, 'label', e.target.value)}
                          />
                          <div className="flex items-center gap-2">
                            <input 
                              type="number" 
                              className="w-20 text-right bg-transparent font-semibold focus:outline-none text-red-700"
                              value={item.amount}
                              onChange={(e) => handleFieldChange('deductions', idx, 'amount', e.target.value)}
                            />
                            <button onClick={() => handleRemoveField('deductions', idx)} className="text-red-400 hover:text-red-600">
                              <Plus size={14} className="rotate-45" />
                            </button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="text-red-700 capitalize">{item.label.replace('_', ' ')}</span>
                          <span className="font-semibold text-red-800">-₱{Number(item.amount).toLocaleString()}</span>
                        </>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </div>

            <div className="bg-gray-50/50 p-4 rounded-xl border border-gray-100">
              <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-3">Attendance Metrics</p>
              <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                {[
                  { label: 'Work Hours', value: isEditing ? editForm.total_hours : selectedPayroll.total_hours, suffix: 'h', key: 'total_hours' },
                  { label: 'Days Worked', value: isEditing ? editForm.days_worked : selectedPayroll.days_worked, suffix: 'd', key: 'days_worked' },
                  { label: 'Overtime', value: isEditing ? editForm.overtime_hours : selectedPayroll.overtime_hours, suffix: 'h', key: 'overtime_hours' },
                  { label: 'Late', value: isEditing ? editForm.late_minutes : selectedPayroll.late_minutes, suffix: 'm', key: 'late_minutes' },
                  { label: 'Undertime', value: isEditing ? editForm.undertime_minutes : selectedPayroll.undertime_minutes, suffix: 'm', key: 'undertime_minutes' },
                ].map(m => (
                  <div key={m.label}>
                    <p className="text-[10px] text-gray-400 font-medium">{m.label}</p>
                    {isEditing ? (
                      <div className="flex items-center gap-1 border-b border-gray-200">
                        <input 
                          type="number" 
                          className="w-full bg-transparent text-sm font-bold focus:outline-none py-1"
                          value={m.value}
                          onChange={(e) => handleFieldChange(null, null, m.key, e.target.value)}
                        />
                        <span className="text-[10px] text-gray-400">{m.suffix}</span>
                      </div>
                    ) : (
                      <p className="text-sm font-bold text-gray-700">{Number(m.value).toFixed(1)}{m.suffix}</p>
                    )}
                  </div>
                ))}
              </div>
            </div>

            {!isEditing && (
              <div className="bg-orange-50/30 p-4 rounded-xl border border-orange-100/50">
                <p className="text-[10px] text-orange-500 uppercase font-bold tracking-widest mb-3">Employer Contributions (ER)</p>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div>
                    <p className="text-[10px] text-gray-400 font-medium">SSS ER</p>
                    <p className="text-sm font-bold text-gray-700">₱{sssER.toLocaleString()}</p>
                  </div>
                  <div>
                    <p className="text-[10px] text-gray-400 font-medium">PhilHealth ER</p>
                    <p className="text-sm font-bold text-gray-700">₱{phicER.toLocaleString()}</p>
                  </div>
                  <div>
                    <p className="text-[10px] text-gray-400 font-medium">Pag-IBIG ER</p>
                    <p className="text-sm font-bold text-gray-700">₱{hdmfER.toLocaleString()}</p>
                  </div>
                  <div className="border-l border-orange-200 pl-4">
                    <p className="text-[10px] text-orange-500 font-bold uppercase">Total ER</p>
                    <p className="text-sm font-black text-orange-600">₱{totalER.toLocaleString()}</p>
                  </div>
                </div>
                <p className="text-[9px] text-gray-400 mt-2 italic">* Employer shares do not affect the employee's net pay and are not included in the payroll slip export.</p>
              </div>
            )}

            <div className="flex justify-center">
              <button 
                onClick={() => setShowBreakdown(!showBreakdown)}
                className="text-[10px] text-gray-400 font-bold hover:text-brand-600 uppercase tracking-widest flex items-center gap-1 transition-colors"
              >
                <Plus size={10} className={showBreakdown ? 'rotate-45' : ''} />
                {showBreakdown ? 'Hide Calculation Logic' : 'View Calculation Logic'}
              </button>
            </div>

            {showBreakdown && (
              <div className="bg-brand-50/30 p-4 rounded-xl border border-brand-100/50 animate-in fade-in slide-in-from-top-2 duration-300">
                <div className="flex items-center gap-2 mb-2">
                  <div className="w-1 h-4 bg-brand-500 rounded-full"></div>
                  <p className="text-[10px] text-brand-600 uppercase font-bold tracking-widest">Calculation Breakdown</p>
                </div>
                
                <div className="space-y-4">
                  <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                    <p className="text-[10px] text-gray-400 font-bold uppercase mb-3">Base Salary Rules</p>
                    <div className="space-y-2">
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Rate Type</span>
                        <span className="font-bold text-gray-700 uppercase">{selectedPayroll.employee.rate_type}</span>
                      </div>
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Base Salary</span>
                        <span className="font-bold text-gray-700">₱{Number(selectedPayroll.base_salary).toLocaleString()}</span>
                      </div>
                      {selectedPayroll.undeclared_salary && (
                        <div className="flex justify-between text-xs">
                          <span className="text-gray-500">Undeclared Salary</span>
                          <span className="font-bold text-blue-700">₱{Number(selectedPayroll.undeclared_salary).toLocaleString()}</span>
                        </div>
                      )}
                      <div className="flex justify-between text-xs pt-1 border-t border-gray-100">
                        <span className="text-gray-500">Derived Daily Rate</span>
                        <span className="font-bold text-brand-600">₱{Number(selectedPayroll.daily_rate).toLocaleString()}</span>
                      </div>
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Hourly Rate</span>
                        <span className="font-bold text-brand-600">₱{(Number(selectedPayroll.daily_rate) / 8).toFixed(2)}</span>
                      </div>
                      <p className="text-[9px] text-gray-400 italic">
                        Daily Rate / 8 hours
                      </p>
                      <p className="text-[9px] text-gray-400 italic">
                        {selectedPayroll.employee.rate_type === 'monthly' 
                          ? `Daily Rate Formula: (Base × 12) / ${Number(selectedPayroll.daily_rate) > 0 ? (Math.round((Number(selectedPayroll.base_salary) * 12) / Number(selectedPayroll.daily_rate))) : 'divisor'}` 
                          : 'Daily Rate Formula: Base salary as daily rate'}
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                      <p className="text-[10px] text-gray-400 font-bold uppercase mb-2">Earnings Logic</p>
                      <div className="space-y-2 text-xs">
                        <div className="flex justify-between items-start">
                          <div className="flex flex-col">
                            <span className="text-gray-500">Regular Base</span>
                            <span className="text-[9px] text-gray-400 italic">
                              {selectedPayroll.employee.rate_type === 'daily' 
                                ? `₱${Number(selectedPayroll.daily_rate).toLocaleString()} × ${selectedPayroll.days_worked}d`
                                : `₱${Number(selectedPayroll.base_salary).toLocaleString()} / 2`}
                            </span>
                          </div>
                          <span className="text-gray-700 font-medium">₱{Number(selectedPayroll.gross_pay - (selectedPayroll.allowances?.reduce((acc, a) => acc + a.amount, 0) || 0)).toLocaleString()}</span>
                        </div>

                        {selectedPayroll.allowances?.map((a, i) => {
                          let formula = '';
                          const hRate = Number(selectedPayroll.daily_rate) / 8;
                          if (a.label === 'Overtime Pay') {
                            const otHours = a.amount / (hRate * 1.25);
                            formula = `${otHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.25`;
                          } else if (a.label === 'Rest Day Pay') {
                            const rdHours = a.amount / (hRate * 1.30);
                            formula = `${rdHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.30`;
                          } else if (a.label === 'Rest Day OT Pay') {
                            const rdotHours = a.amount / (hRate * 1.69);
                            formula = `${rdotHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.69`;
                          } else if (a.label === 'Night Differential') {
                            const ndHours = a.amount / (hRate * 0.10);
                            formula = `${ndHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 0.10`;
                          } else if (a.label === 'Special Holiday') {
                            const shHours = a.amount / (hRate * 0.30);
                            formula = `${shHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 0.30`;
                          } else if (a.label === 'Legal Holiday') {
                            const lhHours = a.amount / (hRate * 1.00);
                            formula = `${lhHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.00`;
                          }

                          return (
                            <div key={i} className="flex justify-between items-start pt-1 border-t border-gray-100/50">
                              <div className="flex flex-col">
                                <span className="text-gray-500">{a.label}</span>
                                {formula && <span className="text-[9px] text-gray-400 italic">{formula}</span>}
                              </div>
                              <span className="text-brand-600 font-medium">+ ₱{Number(a.amount).toLocaleString()}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>

                    <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                      <p className="text-[10px] text-gray-400 font-bold uppercase mb-2">Deductions Logic</p>
                      <div className="space-y-2 text-xs">
                        {Object.entries(selectedPayroll.deductions || {}).map(([rawKey, rawVal]) => {
                          const val = typeof rawVal === 'object' ? rawVal.amount : rawVal;
                          const key = typeof rawVal === 'object' ? rawVal.label : rawKey;
                          
                          let formula = '';
                          const hRate = Number(selectedPayroll.daily_rate) / 8;
                          const dRate = Number(selectedPayroll.daily_rate);
                          
                          if (key === 'Late') {
                            formula = `${selectedPayroll.late_minutes}m / 60 × ₱${hRate.toFixed(2)}`;
                          } else if (key === 'Undertime') {
                            formula = `${selectedPayroll.undertime_minutes}m / 60 × ₱${hRate.toFixed(2)}`;
                          } else if (key === 'Absent') {
                            const days = dRate > 0 ? Number(val) / dRate : 0;
                            formula = `${Math.round(days)}d × ₱${dRate.toLocaleString()}`;
                          } else if (key === 'Half Day') {
                            const count = dRate > 0 ? Number(val) / (dRate / 2) : 0;
                            formula = `${Math.round(count)} sessions × (₱${dRate.toLocaleString()} / 2)`;
                          } else if (key.includes('EE Contribution')) {
                            formula = 'Statutory Fixed';
                          }

                          return (
                            <div key={key} className="flex justify-between items-start pt-1 first:pt-0 border-t first:border-0 border-gray-100/50">
                              <div className="flex flex-col">
                                <span className="text-gray-500">{key}</span>
                                {formula && <span className="text-[9px] text-gray-400 italic">{formula}</span>}
                              </div>
                              <span className="text-red-500 font-medium">- ₱{Number(val).toLocaleString()}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}

          </div>
        )}
      </Modal>

      <Modal
        open={showEmailModal}
        onClose={() => {
          setShowEmailModal(false)
          setSelectedPaystubs(new Set())
          setCcEmails([])
          setBccEmails([])
          setCcInput('')
          setBccInput('')
        }}
        title="Email Paystubs"
        size="lg"
        footer={
          <div className="flex gap-2 justify-end">
            <button 
              onClick={() => {
                setShowEmailModal(false)
                setSelectedPaystubs(new Set())
                setCcEmails([])
                setBccEmails([])
                setCcInput('')
                setBccInput('')
              }}
              className="btn-secondary px-6"
            >
              Cancel
            </button>
            <button 
              disabled={selectedPaystubs.size === 0 || sendPaystubsMutation.isPending}
              onClick={handleSendPaystubs}
              className="btn-primary px-6"
            >
              {sendPaystubsMutation.isPending ? (
                <><Spinner size="sm" /> Sending...</>
              ) : (
                <><Mail size={14} /> Send {selectedPaystubs.size > 0 ? `(${selectedPaystubs.size})` : ''}</>
              )}
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p className="text-sm text-blue-800 font-medium">
              ℹ️ Only paystubs with "Finalized" status can be sent
            </p>
            <p className="text-xs text-blue-600 mt-1">
              Employees will receive their paystub as an attachment. Their status will automatically change to "Paid" after sending.
            </p>
          </div>

          {/* CC/BCC Section */}
          <div className="space-y-3">
            {/* CC Field */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">CC Emails (Optional)</label>
              <div className="flex gap-2 mb-2">
                <input
                  type="email"
                  value={ccInput}
                  onChange={(e) => setCcInput(e.target.value)}
                  onKeyPress={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      addCcEmail(ccInput)
                    }
                  }}
                  placeholder="Enter email and press Enter"
                  className="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
                <button
                  onClick={() => addCcEmail(ccInput)}
                  className="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition"
                >
                  Add
                </button>
              </div>
              
              {/* CC Suggestions */}
              {ccHistory.length > 0 && ccInput === '' && (
                <div className="mb-2">
                  <p className="text-xs text-gray-500 mb-1">Previously used:</p>
                  <div className="flex flex-wrap gap-1">
                    {ccHistory.filter(email => !ccEmails.includes(email)).map(email => (
                      <button
                        key={email}
                        onClick={() => addCcEmail(email)}
                        className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition"
                      >
                        {email}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Added CC Emails */}
              {ccEmails.length > 0 && (
                <div className="flex flex-wrap gap-1">
                  {ccEmails.map(email => (
                    <span key={email} className="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs">
                      {email}
                      <button
                        onClick={() => removeCcEmail(email)}
                        className="hover:text-blue-900 font-bold"
                      >
                        ×
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>

            {/* BCC Field */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">BCC Emails (Optional)</label>
              <div className="flex gap-2 mb-2">
                <input
                  type="email"
                  value={bccInput}
                  onChange={(e) => setBccInput(e.target.value)}
                  onKeyPress={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      addBccEmail(bccInput)
                    }
                  }}
                  placeholder="Enter email and press Enter"
                  className="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
                <button
                  onClick={() => addBccEmail(bccInput)}
                  className="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition"
                >
                  Add
                </button>
              </div>

              {/* BCC Suggestions */}
              {bccHistory.length > 0 && bccInput === '' && (
                <div className="mb-2">
                  <p className="text-xs text-gray-500 mb-1">Previously used:</p>
                  <div className="flex flex-wrap gap-1">
                    {bccHistory.filter(email => !bccEmails.includes(email)).map(email => (
                      <button
                        key={email}
                        onClick={() => addBccEmail(email)}
                        className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition"
                      >
                        {email}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Added BCC Emails */}
              {bccEmails.length > 0 && (
                <div className="flex flex-wrap gap-1">
                  {bccEmails.map(email => (
                    <span key={email} className="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 text-purple-700 rounded text-xs">
                      {email}
                      <button
                        onClick={() => removeBccEmail(email)}
                        className="hover:text-purple-900 font-bold"
                      >
                        ×
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </div>

          {finalizedPaystubs.length === 0 ? (
            <div className="py-8 flex flex-col items-center justify-center text-center">
              <Mail size={48} className="text-gray-200 mb-4" />
              <p className="text-sm font-medium text-gray-500">No finalized paystubs available</p>
              <p className="text-xs text-gray-400 mt-1">Finalize paystubs from the payroll list first</p>
            </div>
          ) : (
            <>
              <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={selectedPaystubs.size === finalizedPaystubs.length}
                    onChange={() => toggleSelectAllPaystubs(finalizedPaystubs.map(p => p.id))}
                    className="w-4 h-4 rounded border-gray-300 accent-brand-600"
                  />
                  <span className="text-sm font-semibold text-gray-700">
                    Select All ({finalizedPaystubs.length})
                  </span>
                </label>
              </div>

              <div className="space-y-2 max-h-96 overflow-y-auto border border-gray-200 rounded-lg p-3">
                {finalizedPaystubs.map((payroll) => (
                  <label key={payroll.id} className="flex items-center gap-3 p-2 hover:bg-gray-50 rounded cursor-pointer">
                    <input
                      type="checkbox"
                      checked={selectedPaystubs.has(payroll.id)}
                      onChange={() => togglePaystubSelection(payroll.id)}
                      className="w-4 h-4 rounded border-gray-300 accent-brand-600"
                    />
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900">
                        {payroll.employee?.first_name} {payroll.employee?.last_name}
                      </p>
                      <p className="text-xs text-gray-500">
                        {payroll.employee?.email}
                      </p>
                      <p className="text-xs text-gray-400 mt-0.5">
                        ₱{Number(payroll.net_pay).toLocaleString()} net pay
                      </p>
                    </div>
                  </label>
                ))}
              </div>
            </>
          )}
        </div>
      </Modal>

      {/* Toggle Salary Basis Confirmation Modal */}
      <Modal
        open={showToggleConfirm}
        onClose={() => setShowToggleConfirm(false)}
        title="Switch Deduction Salary Basis"
        size="sm"
        footer={
          <div className="flex gap-2 justify-end">
            <button 
              onClick={() => setShowToggleConfirm(false)}
              className="btn-secondary px-6"
            >
              Cancel
            </button>
            <button 
              disabled={toggleUndertimeMutation.isPending}
              onClick={() => toggleUndertimeMutation.mutate(selectedPayroll.id)}
              className="btn-primary px-6 bg-blue-600 hover:bg-blue-700 border-blue-600"
            >
              {toggleUndertimeMutation.isPending ? (
                <><Spinner size="sm" /> Switching...</>
              ) : (
                'Confirm Switch'
              )}
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p className="text-sm text-blue-800 font-medium">
              Switch Salary Basis for Deductions
            </p>
          </div>

          <div className="space-y-3">
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Current Setting</p>
              <div className="p-3 bg-gray-50 rounded-lg border border-gray-200">
                <p className="text-sm font-bold text-gray-900">
                  Using: <span className="text-brand-600">{selectedPayroll?.use_undeclared ? 'Undeclared Salary' : 'Base Salary'}</span>
                </p>
                <p className="text-xs text-gray-500 mt-1">
                  Daily rate: ₱{Number(selectedPayroll?.daily_rate).toLocaleString()}
                </p>
                <p className="text-xs text-gray-500">
                  Hourly rate: ₱{(Number(selectedPayroll?.daily_rate) / 8).toFixed(2)}
                </p>
              </div>
            </div>

            <div>
              <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">After Switch</p>
              <div className="p-3 bg-blue-50 rounded-lg border border-blue-200">
                <p className="text-sm font-bold text-blue-900">
                  Will use: <span className="text-blue-600">{selectedPayroll?.use_undeclared ? 'Base Salary' : 'Undeclared Salary'}</span>
                </p>
                <p className="text-xs text-blue-600 mt-1">
                  Daily and hourly rates will be recalculated
                </p>
              </div>
            </div>

            <div className="bg-amber-50 border border-amber-200 rounded-lg p-3">
              <p className="text-xs text-amber-800 font-medium mb-1">
                ⚠️ The following deductions will be recalculated:
              </p>
              <ul className="text-xs text-amber-700 space-y-1 ml-4">
                <li>• <strong>Late</strong> - based on new hourly rate</li>
                <li>• <strong>Undertime</strong> - based on new hourly rate</li>
                <li>• <strong>Absent</strong> - based on new daily rate</li>
                <li>• <strong>Half Day</strong> - based on new daily rate</li>
              </ul>
              <p className="text-xs text-amber-700 mt-2">
                Net pay will be automatically updated.
              </p>
            </div>
          </div>
        </div>
      </Modal>

      {/* Toggle Success Confirmation Modal */}
      <Modal
        open={!!toggleSuccess}
        onClose={() => setToggleSuccess(null)}
        title="Success"
        size="sm"
        footer={
          <div className="flex gap-2 justify-end">
            <button 
              onClick={() => setToggleSuccess(null)}
              className="btn-primary px-6"
            >
              Close
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
            <div className="text-4xl mb-2">✓</div>
            <p className="text-sm text-green-800 font-bold">
              {toggleSuccess?.message}
            </p>
          </div>

          <div className="bg-gray-50 rounded-lg p-4 space-y-2">
            <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Updated Payroll Details</p>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-600">Gross Pay:</span>
                <span className="font-bold text-gray-900">₱{Number(toggleSuccess?.data?.gross_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-600">Total Deductions:</span>
                <span className="font-bold text-red-600">₱{(Number(toggleSuccess?.data?.gross_pay) - Number(toggleSuccess?.data?.net_pay)).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
              </div>
              <div className="flex justify-between border-t pt-2">
                <span className="font-bold text-gray-900">Net Pay:</span>
                <span className="text-lg font-black text-green-600">₱{Number(toggleSuccess?.data?.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
              </div>
            </div>
          </div>
        </div>
      </Modal>

      <AlertModal
        open={!!alert}
        onClose={() => setAlert(null)}
        title={
          alert?.type === 'success' ? 'Success' :
          alert?.type === 'error' ? 'Error' :
          alert?.type === 'warning' ? 'Warning' : 'Information'
        }
        message={alert?.message}
        type={alert?.type}
      />
    </div>
  )
}
