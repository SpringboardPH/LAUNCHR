import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  createRequest, requestKeys, getRequests,
  createLeave, leaveKeys, getLeaves, getLeaveBalance,
  getSystemClock, systemClockKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, ConfirmModal, Modal } from '../../components/ui/index.jsx'
import { ClipboardList, AlertCircle, Plus, Eye, CalendarOff } from 'lucide-react'
import { format } from 'date-fns'
import { useAuth } from '../../store/AuthContext'

const REQUEST_TYPES = [
  { value: 'overtime',        label: 'Overtime' },
  { value: 'half_day',        label: 'Half-Day' },
  { value: 'undertime',       label: 'Undertime' },
  { value: 'schedule_change', label: 'Schedule Change' },
  { value: 'coe',             label: 'Certificate of Employment' },
  { value: 'leave',           label: 'Leave' },
  { value: 'concern',         label: 'Concern' },
  { value: 'cash_advance',    label: 'Cash Advance' },
  { value: 'company_loan',    label: 'Company Loan' },
]

const requestSchema = z.object({
  request_type:   z.string().min(1, { message: 'Request type is required' }),
  subject:        z.string().optional(),
  details:        z.string().optional(),
  date:           z.string().optional(),
  start_time:     z.string().optional(),
  end_time:       z.string().optional(),
  half:           z.string().optional(),
  departure_time: z.string().optional(),
  leave_type:     z.string().optional(),
  start_date:     z.string().optional(),
  end_date:       z.string().optional(),
  reason:         z.string().optional(),
  principal:      z.string().optional(),
  term_count:     z.string().optional(),
  interest_rate:  z.string().optional(),
}).superRefine((data, ctx) => {
  const t = data.request_type
  if (t === 'leave') {
    if (!data.leave_type) ctx.addIssue({ path: ['leave_type'], code: z.ZodIssueCode.custom, message: 'Leave type is required' })
    if (!data.start_date) ctx.addIssue({ path: ['start_date'], code: z.ZodIssueCode.custom, message: 'Start date is required' })
    if (!data.end_date)   ctx.addIssue({ path: ['end_date'],   code: z.ZodIssueCode.custom, message: 'End date is required' })
  } else {
    if (!data.subject?.trim()) ctx.addIssue({ path: ['subject'], code: z.ZodIssueCode.custom, message: 'Subject is required' })
    if (t === 'overtime') {
      if (!data.date)       ctx.addIssue({ path: ['date'],       code: z.ZodIssueCode.custom, message: 'Date is required' })
      if (!data.start_time) ctx.addIssue({ path: ['start_time'], code: z.ZodIssueCode.custom, message: 'Start time is required' })
      if (!data.end_time)   ctx.addIssue({ path: ['end_time'],   code: z.ZodIssueCode.custom, message: 'End time is required' })
    }
    if (t === 'half_day') {
      if (!data.date) ctx.addIssue({ path: ['date'], code: z.ZodIssueCode.custom, message: 'Date is required' })
      if (!data.half) ctx.addIssue({ path: ['half'], code: z.ZodIssueCode.custom, message: 'Half (AM/PM) is required' })
    }
    if (t === 'undertime') {
      if (!data.date)           ctx.addIssue({ path: ['date'],           code: z.ZodIssueCode.custom, message: 'Date is required' })
      if (!data.departure_time) ctx.addIssue({ path: ['departure_time'], code: z.ZodIssueCode.custom, message: 'Departure time is required' })
    }
    if (t === 'schedule_change') {
      if (!data.date) ctx.addIssue({ path: ['date'], code: z.ZodIssueCode.custom, message: 'Date is required' })
    }
    if (['concern', 'coe'].includes(t) && !data.details?.trim()) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Details are required for this request type', path: ['details'] })
    }
    if (['cash_advance', 'company_loan'].includes(t)) {
      const principal = Number(data.principal)
      const termCount = Number(data.term_count)
      if (!data.principal || isNaN(principal) || principal <= 0) ctx.addIssue({ path: ['principal'], code: z.ZodIssueCode.custom, message: 'Amount must be greater than 0' })
      if (!data.term_count || isNaN(termCount) || termCount < 1) ctx.addIssue({ path: ['term_count'], code: z.ZodIssueCode.custom, message: 'Term (number of cutoffs) is required' })
    }
  }
})

function buildMeta(data) {
  const t = data.request_type
  if (t === 'overtime')        return { date: data.date, start_time: data.start_time, end_time: data.end_time }
  if (t === 'half_day')        return { date: data.date, half: data.half }
  if (t === 'undertime')       return { date: data.date, departure_time: data.departure_time }
  if (t === 'schedule_change') return { date: data.date }
  if (['cash_advance', 'company_loan'].includes(t)) {
    return {
      principal: Number(data.principal),
      term_count: Number(data.term_count),
      interest_rate: data.interest_rate ? Number(data.interest_rate) : 0,
    }
  }
  return null
}

export default function RequestFormPage() {
  const [submitted, setSubmitted]             = useState(false)
  const [localError, setLocalError]           = useState('')
  const [confirmConfig, setConfirmConfig]     = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const [selectedItem, setSelectedItem]       = useState(null)
  const [historyTab, setHistoryTab]           = useState('requests')
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()

  const { data: requestsData, isLoading: isLoadingRequests } = useQuery({
    queryKey: requestKeys.list({ personal: true }),
    queryFn: () => getRequests({ personal: true }),
  })

  const { data: leavesData, isLoading: isLoadingLeaves } = useQuery({
    queryKey: leaveKeys.list({ personal: true, userId: user?.id }),
    queryFn: () => getLeaves({ personal: true }),
  })

  const { data: balanceData } = useQuery({
    queryKey: leaveKeys.balance(user?.id),
    queryFn: () => getLeaveBalance(),
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const { data: systemClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const myRequests      = requestsData?.data ?? []
  const myLeaves        = leavesData?.data ?? []
  const leaveTypes      = balanceData?.leave_types ?? []
  const includeWeekends = Boolean(balanceData?.policy?.include_weekends)

  const { register, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm({
    resolver: zodResolver(requestSchema),
    defaultValues: {
      request_type:   'overtime',
      subject:        '',
      details:        '',
      date:           '',
      start_time:     '',
      end_time:       '',
      half:           'am',
      departure_time: '',
      leave_type:     '',
      start_date:     '',
      end_date:       '',
      reason:         '',
      principal:      '',
      term_count:     '',
      interest_rate:  '',
    },
  })

  const requestType = watch('request_type')
  const startDate   = watch('start_date')
  const endDate     = watch('end_date')
  const leaveType   = watch('leave_type')

  useEffect(() => {
    setValue('date', '')
    setValue('start_time', '')
    setValue('end_time', '')
    setValue('half', 'am')
    setValue('departure_time', '')
    setValue('start_date', '')
    setValue('end_date', '')
    setValue('principal', '')
    setValue('term_count', '')
    setValue('interest_rate', '')
    setLocalError('')
  }, [requestType, setValue])

  useEffect(() => {
    if (!leaveTypes.length) return
    if (!leaveTypes.some(t => t.code === leaveType)) {
      setValue('leave_type', leaveTypes[0].code, { shouldValidate: true })
    }
  }, [leaveTypes, leaveType, setValue])

  const calculateDays = (start, end) => {
    if (!start || !end) return 0
    const s = new Date(`${start}T00:00:00`)
    const e = new Date(`${end}T00:00:00`)
    if (isNaN(s) || isNaN(e) || e < s) return 0
    let count = 0
    const cur = new Date(s)
    while (cur <= e) {
      const d = cur.getDay()
      if (includeWeekends || (d !== 0 && d !== 6)) count++
      cur.setDate(cur.getDate() + 1)
    }
    return count
  }

  const requestedDays    = calculateDays(startDate, endDate)
  const selectedBalance  = balanceData?.balances?.[leaveType]

  const requestMutation = useMutation({
    mutationFn: createRequest,
    onSuccess: () => {
      setSubmitted(true)
      qc.invalidateQueries({ queryKey: requestKeys.all })
      reset()
      setTimeout(() => setSubmitted(false), 3000)
    },
  })

  const leaveMutation = useMutation({
    mutationFn: createLeave,
    onSuccess: () => {
      setSubmitted(true)
      setLocalError('')
      qc.invalidateQueries({ queryKey: leaveKeys.all })
      reset()
      setTimeout(() => setSubmitted(false), 3000)
    },
  })

  const onSubmit = (data) => {
    if (data.request_type === 'leave') {
      const virtualToday  = systemClock?.date
        ? new Date(`${systemClock.date}T00:00:00`)
        : new Date(new Date().setHours(0, 0, 0, 0))
      const selectedStart = new Date(`${data.start_date}T00:00:00`)
      if (selectedStart < virtualToday) {
        setLocalError('Start date must be today or in the future.')
        return
      }
      const days = calculateDays(data.start_date, data.end_date)
      if (days < 1) {
        setLocalError('Selected leave range does not include any countable work days.')
        return
      }
      if (selectedBalance?.requires_balance && selectedBalance.remaining !== null && days > selectedBalance.remaining) {
        setLocalError(`You only have ${selectedBalance.remaining} day(s) remaining for this leave type.`)
        return
      }
      setLocalError('')
      setConfirmConfig({
        open: true,
        title: 'Submit Leave Request',
        message: `Submit ${days} day(s) leave request for ${data.start_date} to ${data.end_date}?`,
        type: 'info',
        onConfirm: () => leaveMutation.mutate({ leave_type: data.leave_type, start_date: data.start_date, end_date: data.end_date, reason: data.reason }),
      })
    } else {
      const meta = buildMeta(data)
      setConfirmConfig({
        open: true,
        title: 'Submit Request',
        message: `Submit "${data.subject}" as a ${REQUEST_TYPES.find(t => t.value === data.request_type)?.label ?? data.request_type} request?`,
        type: 'info',
        onConfirm: () => requestMutation.mutate({ request_type: data.request_type, subject: data.subject, details: data.details || null, meta }),
      })
    }
  }

  const isLeave          = requestType === 'leave'
  const showDate         = ['overtime', 'half_day', 'undertime', 'schedule_change'].includes(requestType)
  const showStartEndTime = requestType === 'overtime'
  const showHalf         = requestType === 'half_day'
  const showDeparture    = requestType === 'undertime'
  const showLoanFields   = ['cash_advance', 'company_loan'].includes(requestType)
  const isPending        = requestMutation.isPending || leaveMutation.isPending
  const mutationError    = requestMutation.error || leaveMutation.error

  return (
    <div>
      <PageHeader
        title="My Requests"
        description="Submit and track your HR requests"
        action={<button onClick={() => navigate('/employee')} className="btn-secondary">← Back</button>}
        help={[
          { heading: 'Submitting a Request', items: [
            'Choose a request type from the dropdown at the top of the form, fill in the required fields, then click Submit.',
            'Each request type shows only the fields relevant to it — unused fields are hidden automatically.',
          ]},
          { heading: 'Request Types', items: [
            'Overtime — specify the date and start/end time of overtime work.',
            'Half-Day — specify the date and whether it is the AM or PM half.',
            'Undertime — specify the date and your early departure time.',
            'Schedule Change — describe the requested schedule adjustment.',
            'Certificate of Employment (COE) — no extra fields needed.',
            'Leave — select a leave type, then pick your start and end dates. Your remaining balance is shown per leave type.',
            'Concern — describe any HR-related concern.',
          ]},
          { heading: 'Request History', items: [
            'All previously submitted requests are listed below the form, with their current status: Pending, Approved, or Rejected.',
            'Click the eye icon on any row to view the full details of that request.',
            'Switch to the Leaves tab to see your filed leave requests separately.',
          ]},
        ]}
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig({ ...confirmConfig, open: false })}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />

      <div className="grid lg:grid-cols-3 gap-6 items-start">
        {/* Form */}
        <div className="lg:col-span-1">
          {submitted ? (
            <div className="card p-8 text-center flex flex-col items-center justify-center">
              <div className="inline-block bg-green-100 p-4 rounded-full mb-4">
                <span className="text-green-600 text-2xl">✓</span>
              </div>
              <h2 className="text-xl font-semibold text-gray-900 mb-2">Request Submitted!</h2>
              <p className="text-sm text-gray-600">Your request has been submitted for review.</p>
            </div>
          ) : (
            <div className="card p-6">
              <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <Plus size={18} className="text-brand-600" /> New Request
              </h2>

              {mutationError && (
                <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
                  <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
                  <p className="text-sm font-medium text-red-900">
                    {mutationError.response?.data?.message || 'Failed to submit request'}
                  </p>
                </div>
              )}
              {localError && (
                <div className="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex gap-2">
                  <AlertCircle size={16} className="text-amber-600 shrink-0 mt-0.5" />
                  <p className="text-sm font-medium text-amber-900">{localError}</p>
                </div>
              )}

              <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Type</label>
                  <select {...register('request_type')} className="input">
                    {REQUEST_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                </div>

                {/* Leave-specific fields */}
                {isLeave && (
                  <>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Leave Type</label>
                      <select {...register('leave_type')} className="input">
                        {leaveTypes.length
                          ? leaveTypes.map(t => <option key={t.id} value={t.code}>{t.name}</option>)
                          : <option value="">No leave types available</option>}
                      </select>
                      {errors.leave_type && <p className="text-xs text-red-500 mt-1">{errors.leave_type.message}</p>}
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-gray-600 mb-1">Start Date</label>
                        <input type="date" {...register('start_date')} className="input px-2" />
                        {errors.start_date && <p className="text-xs text-red-500 mt-1">{errors.start_date.message}</p>}
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-gray-600 mb-1">End Date</label>
                        <input type="date" {...register('end_date')} className="input px-2" />
                        {errors.end_date && <p className="text-xs text-red-500 mt-1">{errors.end_date.message}</p>}
                      </div>
                    </div>
                    <div className="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm text-gray-600">
                      <p>Requested days: <span className="font-medium text-gray-900">{requestedDays}</span></p>
                      <p>Weekend policy: <span className="font-medium text-gray-900">{includeWeekends ? 'Weekends count' : 'Weekends do not count'}</span></p>
                      {selectedBalance?.requires_balance && selectedBalance.remaining !== null && (
                        <p>Remaining balance after request: <span className="font-medium text-gray-900">{Math.max(0, selectedBalance.remaining - requestedDays)}</span>
                          <span className="text-gray-500"> / {selectedBalance.remaining}</span>
                        </p>
                      )}
                      {selectedBalance && !selectedBalance.requires_balance && (
                        <p>This leave type: <span className="font-medium text-gray-900">No balance required</span></p>
                      )}
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Reason (Optional)</label>
                      <textarea {...register('reason')} rows="3" className="input resize-none" placeholder="Provide any additional details..." />
                    </div>
                  </>
                )}

                {/* Non-leave fields */}
                {!isLeave && (
                  <>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Subject</label>
                      <input type="text" {...register('subject')} className="input" placeholder="Brief subject..." />
                      {errors.subject && <p className="text-xs text-red-500 mt-1">{errors.subject.message}</p>}
                    </div>
                    {showDate && (
                      <div>
                        <label className="block text-xs font-medium text-gray-600 mb-1">Date</label>
                        <input type="date" {...register('date')} className="input px-2" />
                        {errors.date && <p className="text-xs text-red-500 mt-1">{errors.date.message}</p>}
                      </div>
                    )}
                    {showStartEndTime && (
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                          <label className="block text-xs font-medium text-gray-600 mb-1">Start Time</label>
                          <input type="time" {...register('start_time')} className="input px-2" />
                          {errors.start_time && <p className="text-xs text-red-500 mt-1">{errors.start_time.message}</p>}
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-600 mb-1">End Time</label>
                          <input type="time" {...register('end_time')} className="input px-2" />
                          {errors.end_time && <p className="text-xs text-red-500 mt-1">{errors.end_time.message}</p>}
                        </div>
                      </div>
                    )}
                    {showHalf && (
                      <div>
                        <label className="block text-xs font-medium text-gray-600 mb-1">Half</label>
                        <select {...register('half')} className="input">
                          <option value="am">AM (Morning)</option>
                          <option value="pm">PM (Afternoon)</option>
                        </select>
                        {errors.half && <p className="text-xs text-red-500 mt-1">{errors.half.message}</p>}
                      </div>
                    )}
                    {showDeparture && (
                      <div>
                        <label className="block text-xs font-medium text-gray-600 mb-1">Departure Time</label>
                        <input type="time" {...register('departure_time')} className="input px-2" />
                        {errors.departure_time && <p className="text-xs text-red-500 mt-1">{errors.departure_time.message}</p>}
                      </div>
                    )}
                    {showLoanFields && (
                      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                          <label className="block text-xs font-medium text-gray-600 mb-1">Amount (₱)</label>
                          <input type="number" min="1" step="0.01" {...register('principal')} className="input px-2" />
                          {errors.principal && <p className="text-xs text-red-500 mt-1">{errors.principal.message}</p>}
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-600 mb-1">Term (cutoffs)</label>
                          <input type="number" min="1" step="1" {...register('term_count')} className="input px-2" />
                          {errors.term_count && <p className="text-xs text-red-500 mt-1">{errors.term_count.message}</p>}
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-gray-600 mb-1">Interest Rate (optional)</label>
                          <input type="number" min="0" max="1" step="0.01" {...register('interest_rate')} className="input px-2" placeholder="e.g. 0.05 = 5%" />
                        </div>
                      </div>
                    )}
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Details (Optional)</label>
                      <textarea {...register('details')} rows="3" className="input resize-none" placeholder="Provide any additional details..." />
                      {errors.details && <p className="text-xs text-red-500 mt-1">{errors.details.message}</p>}
                    </div>
                  </>
                )}

                <button type="submit" disabled={isPending} className="btn btn-primary w-full">
                  {isLeave ? <CalendarOff size={16} /> : <ClipboardList size={16} />}
                  {isPending ? 'Submitting...' : 'Submit Request'}
                </button>
              </form>
            </div>
          )}
        </div>

        {/* History */}
        <div className="lg:col-span-2">
          <div className="card overflow-hidden">
            <div className="p-4 border-b border-gray-100">
              <div className="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
                {[['requests', 'Requests'], ['leaves', 'Leaves']].map(([tab, lbl]) => (
                  <button key={tab} onClick={() => setHistoryTab(tab)}
                    className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors ${
                      historyTab === tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                    }`}
                  >{lbl}</button>
                ))}
              </div>
            </div>

            {historyTab === 'requests' ? (
              isLoadingRequests ? <PageSpinner /> : (
                <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 border-b border-gray-200">
                    <tr>
                      {['Type', 'Subject', 'Filed', 'Status', 'Details'].map(h => (
                        <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {myRequests.map(req => (
                      <tr key={req.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-gray-900 font-medium capitalize">
                          {REQUEST_TYPES.find(t => t.value === req.request_type)?.label ?? req.request_type?.replace(/_/g, ' ')}
                        </td>
                        <td className="px-4 py-3 text-gray-700 max-w-[160px] truncate" title={req.subject}>{req.subject}</td>
                        <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                          {req.created_at ? format(new Date(req.created_at), 'MMM d, yyyy') : '—'}
                          <br /><span className="text-[9px] text-gray-300">{req.created_at ? format(new Date(req.created_at), 'h:mm a') : ''}</span>
                        </td>
                        <td className="px-4 py-3"><StatusBadge status={req.status} /></td>
                        <td className="px-4 py-3 text-center">
                          <button onClick={() => setSelectedItem({ ...req, _kind: 'request' })}
                            className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50" title="View Details">
                            <Eye size={16} />
                          </button>
                        </td>
                      </tr>
                    ))}
                    {myRequests.length === 0 && (
                      <tr><td colSpan={5} className="py-12 text-center">
                        <ClipboardList size={32} className="text-gray-200 mx-auto mb-2" />
                        <p className="text-sm text-gray-400">You have no requests yet</p>
                      </td></tr>
                    )}
                  </tbody>
                </table>
                </div>
              )
            ) : (
              isLoadingLeaves ? <PageSpinner /> : (
                <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 border-b border-gray-200">
                    <tr>
                      {['Type', 'Dates', 'Days', 'Filed', 'Status', 'Details'].map(h => (
                        <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {myLeaves.map(leave => (
                      <tr key={leave.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-gray-900 font-medium capitalize">
                          {typeof leave.leave_type === 'string'
                            ? leave.leave_type.replace(/_/g, ' ')
                            : (leave.leave_type?.name || leave.leave_type?.code || 'Unknown')}
                        </td>
                        <td className="px-4 py-3 text-gray-600 text-xs">
                          {format(new Date(leave.start_date), 'MMM d')} – {format(new Date(leave.end_date), 'MMM d, yyyy')}
                        </td>
                        <td className="px-4 py-3 text-gray-600">{leave.days_requested}d</td>
                        <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                          {leave.created_at ? format(new Date(leave.created_at), 'MMM d, yyyy') : '—'}
                          <br /><span className="text-[9px] text-gray-300">{leave.created_at ? format(new Date(leave.created_at), 'h:mm a') : ''}</span>
                        </td>
                        <td className="px-4 py-3"><StatusBadge status={leave.status} /></td>
                        <td className="px-4 py-3 text-center">
                          <button onClick={() => setSelectedItem({ ...leave, _kind: 'leave' })}
                            className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50" title="View Details">
                            <Eye size={16} />
                          </button>
                        </td>
                      </tr>
                    ))}
                    {myLeaves.length === 0 && (
                      <tr><td colSpan={6} className="py-12 text-center">
                        <CalendarOff size={32} className="text-gray-200 mx-auto mb-2" />
                        <p className="text-sm text-gray-400">You have no leave requests yet</p>
                      </td></tr>
                    )}
                  </tbody>
                </table>
                </div>
              )
            )}
          </div>
        </div>
      </div>

      {/* Detail modal — request */}
      <Modal open={Boolean(selectedItem) && selectedItem?._kind === 'request'}
        onClose={() => setSelectedItem(null)} title="Request Details" size="sm">
        {selectedItem?._kind === 'request' && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                <p className="text-sm font-semibold text-gray-900">
                  {REQUEST_TYPES.find(t => t.value === selectedItem.request_type)?.label ?? selectedItem.request_type}
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <StatusBadge status={selectedItem.status} />
              </div>
              <div className="col-span-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Subject</p>
                <p className="text-sm text-gray-800">{selectedItem.subject}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Filed At</p>
                <p className="text-sm text-gray-800">
                  {selectedItem.created_at ? format(new Date(selectedItem.created_at), 'MMM d, yyyy h:mm a') : '—'}
                </p>
              </div>
            </div>
            {selectedItem.details && (
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Details</p>
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700 italic">"{selectedItem.details}"</div>
              </div>
            )}
            {(selectedItem.status === 'approved' || selectedItem.status === 'rejected') && selectedItem.response_notes && (
              <div className={`p-3 rounded-lg border ${selectedItem.status === 'rejected' ? 'bg-red-50 border-red-100' : 'bg-green-50 border-green-100'}`}>
                <p className={`text-[10px] font-bold uppercase tracking-widest mb-1 ${selectedItem.status === 'rejected' ? 'text-red-400' : 'text-green-500'}`}>HR Response Notes</p>
                <p className={`text-sm font-medium ${selectedItem.status === 'rejected' ? 'text-red-900' : 'text-green-900'}`}>{selectedItem.response_notes}</p>
              </div>
            )}
            <div className="flex justify-end pt-2">
              <button onClick={() => setSelectedItem(null)} className="btn-primary px-6">Close</button>
            </div>
          </div>
        )}
      </Modal>

      {/* Detail modal — leave */}
      <Modal open={Boolean(selectedItem) && selectedItem?._kind === 'leave'}
        onClose={() => setSelectedItem(null)} title="Leave Request Details" size="sm">
        {selectedItem?._kind === 'leave' && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                <p className="text-sm font-semibold text-gray-900 capitalize">
                  {typeof selectedItem.leave_type === 'string'
                    ? selectedItem.leave_type.replace(/_/g, ' ')
                    : (selectedItem.leave_type?.name || 'Unknown')}
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <StatusBadge status={selectedItem.status} />
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Start Date</p>
                <p className="text-sm text-gray-800">{format(new Date(selectedItem.start_date), 'MMM dd, yyyy')}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">End Date</p>
                <p className="text-sm text-gray-800">{format(new Date(selectedItem.end_date), 'MMM dd, yyyy')}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Days</p>
                <p className="text-sm text-gray-800">{selectedItem.days_requested} day(s)</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Filed At</p>
                <p className="text-sm text-gray-800">
                  {selectedItem.created_at ? format(new Date(selectedItem.created_at), 'MMM d, yyyy h:mm a') : '—'}
                </p>
              </div>
            </div>
            {selectedItem.reason && (
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">My Reason</p>
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700 italic">"{selectedItem.reason}"</div>
              </div>
            )}
            {selectedItem.status === 'rejected' && (
              <div className="p-3 bg-red-50 rounded-lg border border-red-100">
                <p className="text-[10px] font-bold text-red-400 uppercase tracking-widest mb-1">Rejection Reason (HR)</p>
                <p className="text-sm text-red-900 font-medium">{selectedItem.rejection_reason || 'No specific reason provided.'}</p>
              </div>
            )}
            {selectedItem.status === 'approved' && selectedItem.approver && (
              <p className="text-[10px] text-gray-400 text-right italic">Approved by {selectedItem.approver.name}</p>
            )}
            <div className="flex justify-end pt-2">
              <button onClick={() => setSelectedItem(null)} className="btn-primary px-6">Close</button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
