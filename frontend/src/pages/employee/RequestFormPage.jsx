import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { createRequest, requestKeys, getRequests } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, ConfirmModal, Modal } from '../../components/ui/index.jsx'
import { ClipboardList, AlertCircle, Plus, Eye } from 'lucide-react'
import { format } from 'date-fns'

const REQUEST_TYPES = [
  { value: 'overtime',        label: 'Overtime' },
  { value: 'half_day',        label: 'Half-Day' },
  { value: 'undertime',       label: 'Undertime' },
  { value: 'schedule_change', label: 'Schedule Change' },
  { value: 'concern',         label: 'Concern' },
  { value: 'coe',             label: 'Certificate of Employment' },
  { value: 'other',           label: 'Other' },
]

const requestSchema = z.object({
  request_type: z.string().min(1, { message: 'Request type is required' }),
  subject:      z.string().min(1, { message: 'Subject is required' }).max(255),
  details:      z.string().optional(),
  date:         z.string().optional(),
  start_time:   z.string().optional(),
  end_time:     z.string().optional(),
  half:         z.string().optional(),
  departure_time: z.string().optional(),
}).superRefine((data, ctx) => {
  const t = data.request_type
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
})

function buildMeta(data) {
  const t = data.request_type
  if (t === 'overtime')        return { date: data.date, start_time: data.start_time, end_time: data.end_time }
  if (t === 'half_day')        return { date: data.date, half: data.half }
  if (t === 'undertime')       return { date: data.date, departure_time: data.departure_time }
  if (t === 'schedule_change') return { date: data.date }
  return null
}

export default function RequestFormPage() {
  const [submitted, setSubmitted]       = useState(false)
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const [selectedRequest, setSelectedRequest] = useState(null)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: requestsData, isLoading: isLoadingRequests } = useQuery({
    queryKey: requestKeys.list({ personal: true }),
    queryFn: () => getRequests({ personal: true }),
  })

  const myRequests = requestsData?.data ?? []

  const { register, handleSubmit, watch, reset, formState: { errors } } = useForm({
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
    },
  })

  const requestType = watch('request_type')

  const mutation = useMutation({
    mutationFn: createRequest,
    onSuccess: () => {
      setSubmitted(true)
      qc.invalidateQueries({ queryKey: requestKeys.all })
      reset()
      setTimeout(() => setSubmitted(false), 3000)
    },
  })

  const onSubmit = (data) => {
    const meta = buildMeta(data)
    setConfirmConfig({
      open: true,
      title: 'Submit Request',
      message: `Submit "${data.subject}" as a ${REQUEST_TYPES.find(t => t.value === data.request_type)?.label ?? data.request_type} request?`,
      type: 'info',
      onConfirm: () => mutation.mutate({ request_type: data.request_type, subject: data.subject, details: data.details || null, meta }),
    })
  }

  const showDate           = ['overtime', 'half_day', 'undertime', 'schedule_change'].includes(requestType)
  const showStartEndTime   = requestType === 'overtime'
  const showHalf           = requestType === 'half_day'
  const showDepartureTime  = requestType === 'undertime'

  return (
    <div>
      <PageHeader
        title="My Requests"
        description="Submit and track your HR requests"
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
              <p className="text-sm text-gray-600">Your request has been submitted for review.</p>
            </div>
          ) : (
            <div className="card p-6">
              <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
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

              <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                {/* Type */}
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Type</label>
                  <select {...register('request_type')} className="input">
                    {REQUEST_TYPES.map(t => (
                      <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                  </select>
                  {errors.request_type && (
                    <p className="text-xs text-red-500 mt-1">{errors.request_type.message}</p>
                  )}
                </div>

                {/* Subject — always shown */}
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Subject</label>
                  <input type="text" {...register('subject')} className="input" placeholder="Brief subject..." />
                  {errors.subject && (
                    <p className="text-xs text-red-500 mt-1">{errors.subject.message}</p>
                  )}
                </div>

                {/* Date (overtime / half_day / undertime / schedule_change) */}
                {showDate && (
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Date</label>
                    <input type="date" {...register('date')} className="input px-2" />
                    {errors.date && (
                      <p className="text-xs text-red-500 mt-1">{errors.date.message}</p>
                    )}
                  </div>
                )}

                {/* Overtime: start_time + end_time */}
                {showStartEndTime && (
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Start Time</label>
                      <input type="time" {...register('start_time')} className="input px-2" />
                      {errors.start_time && (
                        <p className="text-xs text-red-500 mt-1">{errors.start_time.message}</p>
                      )}
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">End Time</label>
                      <input type="time" {...register('end_time')} className="input px-2" />
                      {errors.end_time && (
                        <p className="text-xs text-red-500 mt-1">{errors.end_time.message}</p>
                      )}
                    </div>
                  </div>
                )}

                {/* Half-Day: AM / PM select */}
                {showHalf && (
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Half</label>
                    <select {...register('half')} className="input">
                      <option value="am">AM (Morning)</option>
                      <option value="pm">PM (Afternoon)</option>
                    </select>
                    {errors.half && (
                      <p className="text-xs text-red-500 mt-1">{errors.half.message}</p>
                    )}
                  </div>
                )}

                {/* Undertime: departure_time */}
                {showDepartureTime && (
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Departure Time</label>
                    <input type="time" {...register('departure_time')} className="input px-2" />
                    {errors.departure_time && (
                      <p className="text-xs text-red-500 mt-1">{errors.departure_time.message}</p>
                    )}
                  </div>
                )}

                {/* Details — always shown, optional */}
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Details (Optional)</label>
                  <textarea
                    {...register('details')}
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
                  <ClipboardList size={16} />
                  {mutation.isPending ? 'Submitting...' : 'Submit Request'}
                </button>
              </form>
            </div>
          )}
        </div>

        {/* Right Column: Table */}
        <div className="lg:col-span-2">
          <div className="card overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-gray-700">My Requests</h2>
            </div>
            {isLoadingRequests ? (
              <PageSpinner />
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Subject</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Filed</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                    <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wide">Details</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {myRequests.map(req => (
                    <tr key={req.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-gray-900 font-medium capitalize">
                        {REQUEST_TYPES.find(t => t.value === req.request_type)?.label ?? req.request_type?.replace(/_/g, ' ')}
                      </td>
                      <td className="px-4 py-3 text-gray-700 max-w-[160px] truncate" title={req.subject}>
                        {req.subject}
                      </td>
                      <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                        {req.created_at ? format(new Date(req.created_at), 'MMM d, yyyy') : '—'}
                        <br />
                        <span className="text-[9px] text-gray-300">{req.created_at ? format(new Date(req.created_at), 'h:mm a') : ''}</span>
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={req.status} />
                      </td>
                      <td className="px-4 py-3 text-center">
                        <button
                          onClick={() => setSelectedRequest(req)}
                          className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50"
                          title="View Details"
                        >
                          <Eye size={16} />
                        </button>
                      </td>
                    </tr>
                  ))}
                  {myRequests.length === 0 && (
                    <tr>
                      <td colSpan={5} className="py-12 text-center">
                        <ClipboardList size={32} className="text-gray-200 mx-auto mb-2" />
                        <p className="text-sm text-gray-400">You have no requests yet</p>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>

      {/* Request Detail Modal */}
      <Modal
        open={Boolean(selectedRequest)}
        onClose={() => setSelectedRequest(null)}
        title="Request Details"
        size="sm"
      >
        {selectedRequest && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                <p className="text-sm font-semibold text-gray-900">
                  {REQUEST_TYPES.find(t => t.value === selectedRequest.request_type)?.label ?? selectedRequest.request_type}
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <StatusBadge status={selectedRequest.status} />
              </div>
              <div className="col-span-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Subject</p>
                <p className="text-sm text-gray-800">{selectedRequest.subject}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Filed At</p>
                <p className="text-sm text-gray-800">
                  {selectedRequest.created_at ? format(new Date(selectedRequest.created_at), 'MMM d, yyyy h:mm a') : '—'}
                </p>
              </div>
            </div>

            {selectedRequest.details && (
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Details</p>
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700 italic">
                  "{selectedRequest.details}"
                </div>
              </div>
            )}

            {(selectedRequest.status === 'approved' || selectedRequest.status === 'rejected') && selectedRequest.response_notes && (
              <div className={`p-3 rounded-lg border ${selectedRequest.status === 'rejected' ? 'bg-red-50 border-red-100' : 'bg-green-50 border-green-100'}`}>
                <p className={`text-[10px] font-bold uppercase tracking-widest mb-1 ${selectedRequest.status === 'rejected' ? 'text-red-400' : 'text-green-500'}`}>
                  HR Response Notes
                </p>
                <p className={`text-sm font-medium ${selectedRequest.status === 'rejected' ? 'text-red-900' : 'text-green-900'}`}>
                  {selectedRequest.response_notes}
                </p>
              </div>
            )}

            <div className="flex justify-end pt-2">
              <button onClick={() => setSelectedRequest(null)} className="btn-primary px-6">
                Close
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
