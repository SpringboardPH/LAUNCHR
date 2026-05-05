import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { createLeave, leaveKeys, getLeaves, getLeaveBalance, getSystemClock, systemClockKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, ConfirmModal, Modal } from '../../components/ui/index.jsx'
import { CalendarOff, AlertCircle, Plus, Eye } from 'lucide-react'
import { format } from 'date-fns'
import { useAuth } from '../../store/AuthContext'

const leaveSchema = z.object({
  leave_type: z.string().min(1, { message: 'Invalid leave type' }),
  start_date: z.string().min(1, { message: 'Start date is required' }),
  end_date: z.string(),
  reason: z.string().optional(),
}).refine(d => new Date(d.end_date) >= new Date(d.start_date), {
  message: 'End date must be after start date',
  path: ['end_date'],
})

export default function LeaveRequestFormPage() {
  const [submitted, setSubmitted] = useState(false)
  const [localError, setLocalError] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const [selectedLeave, setSelectedLeave] = useState(null)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()

  const { data: leavesData, isLoading: isLoadingLeaves } = useQuery({
    queryKey: leaveKeys.list({ personal: true, userId: user?.id }),
    queryFn: () => getLeaves({ personal: true }),
  })

  const { data: systemClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
    refetchInterval: 30_000,
  })

  const { data: balanceData } = useQuery({
    queryKey: [...leaveKeys.balance(user?.id), systemClock?.date],
    queryFn: () => getLeaveBalance(),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })
  
  const myLeaves = leavesData?.data ?? []

  const { register, handleSubmit, watch, setValue, formState: { errors }, reset } = useForm({
    resolver: zodResolver(leaveSchema),
    defaultValues: {
      leave_type: '',
      reason: '',
    },
  })

  const startDate = watch('start_date')
  const endDate = watch('end_date')
  const leaveType = watch('leave_type')

  const includeWeekends = Boolean(balanceData?.policy?.include_weekends)
  const leaveTypes = balanceData?.leave_types ?? []

  useEffect(() => {
    if (!leaveTypes.length) return

    if (!leaveTypes.some((type) => type.code === leaveType)) {
      setValue('leave_type', leaveTypes[0].code, { shouldValidate: true })
    }
  }, [leaveType, leaveTypes, setValue])

  const calculateRequestedDays = (start, end) => {
    if (!start || !end) return 0

    const current = new Date(`${start}T00:00:00`)
    const final = new Date(`${end}T00:00:00`)
    if (Number.isNaN(current.getTime()) || Number.isNaN(final.getTime()) || final < current) return 0

    let count = 0
    while (current <= final) {
      const day = current.getDay()
      if (includeWeekends || (day !== 0 && day !== 6)) {
        count += 1
      }
      current.setDate(current.getDate() + 1)
    }

    return count
  }

  const requestedDays = calculateRequestedDays(startDate, endDate)
  const selectedBalance = balanceData?.balances?.[leaveType]
  const remainingBalance = selectedBalance?.remaining ?? null

  const mutation = useMutation({
    mutationFn: createLeave,
    onSuccess: () => {
      setSubmitted(true)
      setLocalError('')
      qc.invalidateQueries({ queryKey: leaveKeys.all })
      reset()
      setTimeout(() => {
        setSubmitted(false)
      }, 3000)
    },
  })

  const onSubmit = (data) => {
    const virtualToday = systemClock?.date
      ? new Date(`${systemClock.date}T00:00:00`)
      : new Date(new Date().setHours(0, 0, 0, 0))
    const selectedStart = new Date(`${data.start_date}T00:00:00`)

    if (selectedStart < virtualToday) {
      setLocalError('Start date must be today or in the future.')
      return
    }

    const days = calculateRequestedDays(data.start_date, data.end_date)

    if (days < 1) {
      setLocalError('Selected leave range does not include any countable work days.')
      return
    }

    if (selectedBalance?.requires_balance && remainingBalance !== null && days > remainingBalance) {
      setLocalError(`You only have ${remainingBalance} day(s) remaining for this leave type.`)
      return
    }

    setLocalError('')
    
    setConfirmConfig({
      open: true,
      title: 'Submit Leave Request',
      message: `Submit ${days} day(s) leave request for ${data.start_date} to ${data.end_date}?`,
      onConfirm: () => mutation.mutate(data),
      type: 'info'
    })
  }

  return (
    <div>
      <PageHeader
        title="Leave Management"
        description="Request and track your leaves"
        action={
          <button onClick={() => navigate('/employee')} className="btn-secondary">
            ← Back
          </button>
        }
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
        {/* Left Column: Form */}
        <div className="lg:col-span-1">
          {submitted ? (
            <div className="card p-8 text-center h-full flex flex-col items-center justify-center">
              <div className="inline-block bg-green-100 p-4 rounded-full mb-4">
                <span className="text-green-600 text-2xl">✓</span>
              </div>
              <h2 className="text-xl font-semibold text-gray-900 mb-2">Request Submitted!</h2>
              <p className="text-sm text-gray-600">Your leave request has been submitted for approval.</p>
            </div>
          ) : (
            <div className="card p-6">
              <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <Plus size={18} className="text-brand-600" /> New Request
              </h2>
              {mutation.error && (
                <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
                  <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
                  <div>
                    <p className="text-sm font-medium text-red-900">
                      {mutation.error.response?.data?.message || 'Failed to submit request'}
                    </p>
                  </div>
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
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Leave Type
                  </label>
                  <select {...register('leave_type')} className="input">
                    {leaveTypes.length ? (
                      leaveTypes.map((type) => (
                        <option key={type.id} value={type.code}>
                          {type.name}
                        </option>
                      ))
                    ) : (
                      <option value="">No leave types available</option>
                    )}
                  </select>
                  {errors.leave_type && (
                    <p className="text-xs text-red-500 mt-1">{errors.leave_type.message}</p>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Start Date
                    </label>
                    <input type="date" {...register('start_date')} className="input px-2" />
                    {errors.start_date && (
                      <p className="text-xs text-red-500 mt-1">{errors.start_date.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      End Date
                    </label>
                    <input type="date" {...register('end_date')} className="input px-2" />
                    {errors.end_date && (
                      <p className="text-xs text-red-500 mt-1">{errors.end_date.message}</p>
                    )}
                  </div>
                </div>

                <div className="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm text-gray-600">
                  <p>Requested days: <span className="font-medium text-gray-900">{requestedDays}</span></p>
                  <p>Weekend policy: <span className="font-medium text-gray-900">{includeWeekends ? 'Weekends count' : 'Weekends do not count'}</span></p>
                  {selectedBalance?.requires_balance && remainingBalance !== null && (
                    <p>
                      Remaining balance after request: <span className="font-medium text-gray-900">
                        {Math.max(0, remainingBalance - requestedDays)}
                      </span>
                      <span className="text-gray-500"> / {remainingBalance}</span>
                    </p>
                  )}
                  {selectedBalance && !selectedBalance.requires_balance && (
                    <p>
                      This leave type: <span className="font-medium text-gray-900">No balance required</span>
                    </p>
                  )}
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Reason (Optional)
                  </label>
                  <textarea
                    {...register('reason')}
                    rows="3"
                    className="input resize-none"
                    placeholder="Provide any additional details..."
                  />
                </div>

                <button
                  type="submit"
                  disabled={mutation.isPending}
                  className="btn btn-primary w-full"
                >
                  <CalendarOff size={16} />
                  {mutation.isPending ? 'Submitting...' : 'Submit Request'}
                </button>
              </form>
            </div>
          )}
        </div>

        {/* Right Column: List */}
        <div className="lg:col-span-2">
          <div className="card overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-gray-900">My Leaves</h2>
            </div>
            {isLoadingLeaves ? (
              <PageSpinner />
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Dates</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Days</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                    <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wide">Notes</th>
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
                      <td className="px-4 py-3">
                        <StatusBadge status={leave.status} />
                      </td>
                      <td className="px-4 py-3 text-center">
                        <button 
                          onClick={() => setSelectedLeave(leave)}
                          className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50"
                          title="View Details"
                        >
                          <Eye size={16} />
                        </button>
                      </td>
                    </tr>
                  ))}
                  {myLeaves.length === 0 && (
                    <tr>
                      <td colSpan={5} className="py-12 text-center">
                        <CalendarOff size={32} className="text-gray-200 mx-auto mb-2" />
                        <p className="text-sm text-gray-400">You have no leave requests</p>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>

      {/* Leave Details Modal */}
      <Modal 
        open={Boolean(selectedLeave)} 
        onClose={() => setSelectedLeave(null)} 
        title="Leave Request Details"
        size="sm"
      >
        {selectedLeave && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                <p className="text-sm font-semibold text-gray-900 capitalize">
                  {typeof selectedLeave.leave_type === 'string' 
                    ? selectedLeave.leave_type.replace(/_/g, ' ') 
                    : (selectedLeave.leave_type?.name || 'Unknown')}
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <StatusBadge status={selectedLeave.status} />
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Start Date</p>
                <p className="text-sm text-gray-800">{format(new Date(selectedLeave.start_date), 'MMM dd, yyyy')}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">End Date</p>
                <p className="text-sm text-gray-800">{format(new Date(selectedLeave.end_date), 'MMM dd, yyyy')}</p>
              </div>
            </div>

            <div>
              <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">My Reason</p>
              <div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700 italic">
                "{selectedLeave.reason || 'No reason provided'}"
              </div>
            </div>

            {selectedLeave.status === 'rejected' && (
              <div className="p-3 bg-red-50 rounded-lg border border-red-100">
                <p className="text-[10px] font-bold text-red-400 uppercase tracking-widest mb-1">Rejection Reason (HR)</p>
                <p className="text-sm text-red-900 font-medium">
                  {selectedLeave.rejection_reason || 'No specific reason provided by HR.'}
                </p>
              </div>
            )}

            {selectedLeave.status === 'approved' && selectedLeave.approver && (
              <p className="text-[10px] text-gray-400 text-right italic">
                Approved by {selectedLeave.approver.name}
              </p>
            )}

            <div className="flex justify-end pt-2">
              <button onClick={() => setSelectedLeave(null)} className="btn-primary px-6">
                Close
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
