import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { createLeave, leaveKeys } from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import { CalendarOff, AlertCircle } from 'lucide-react'
import { format } from 'date-fns'

const leaveSchema = z.object({
  leave_type: z.enum(['vacation', 'sick', 'unpaid', 'maternity'], { message: 'Invalid leave type' }),
  start_date: z.string().refine(d => new Date(d) >= new Date(new Date().setHours(0, 0, 0, 0)), {
    message: 'Start date must be today or in the future',
  }),
  end_date: z.string(),
  reason: z.string().optional(),
}).refine(d => new Date(d.end_date) >= new Date(d.start_date), {
  message: 'End date must be after start date',
  path: ['end_date'],
})

export default function LeaveRequestFormPage() {
  const [submitted, setSubmitted] = useState(false)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { register, handleSubmit, formState: { errors }, reset } = useForm({
    resolver: zodResolver(leaveSchema),
    defaultValues: {
      leave_type: 'vacation',
      reason: '',
    },
  })

  const mutation = useMutation({
    mutationFn: createLeave,
    onSuccess: () => {
      setSubmitted(true)
      qc.invalidateQueries({ queryKey: leaveKeys.all })
      reset()
      setTimeout(() => navigate('/employee'), 2000)
    },
  })

  const onSubmit = (data) => {
    mutation.mutate(data)
  }

  if (submitted) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="card p-8 text-center max-w-sm">
          <div className="inline-block bg-green-100 p-4 rounded-full mb-4">
            <span className="text-green-600 text-2xl">✓</span>
          </div>
          <h2 className="text-xl font-semibold text-gray-900 mb-2">Request Submitted!</h2>
          <p className="text-sm text-gray-600 mb-4">Your leave request has been submitted for approval.</p>
          <p className="text-xs text-gray-400">Redirecting to dashboard...</p>
        </div>
      </div>
    )
  }

  return (
    <div>
      <PageHeader
        title="Request Leave"
        description="Submit a new leave request"
        action={
          <button onClick={() => navigate('/employee')} className="btn-secondary">
            ← Back
          </button>
        }
      />

      <div className="max-w-xl mx-auto">
        <div className="card p-6">
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
            {/* Leave Type */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Leave Type
              </label>
              <select
                {...register('leave_type')}
                className="input"
              >
                <option value="vacation">Vacation Leave</option>
                <option value="sick">Sick Leave</option>
                <option value="unpaid">Unpaid Leave</option>
                <option value="maternity">Maternity Leave</option>
              </select>
              {errors.leave_type && (
                <p className="text-xs text-red-500 mt-1">{errors.leave_type.message}</p>
              )}
            </div>

            {/* Start Date */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Start Date
              </label>
              <input
                type="date"
                {...register('start_date')}
                className="input"
              />
              {errors.start_date && (
                <p className="text-xs text-red-500 mt-1">{errors.start_date.message}</p>
              )}
            </div>

            {/* End Date */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                End Date
              </label>
              <input
                type="date"
                {...register('end_date')}
                className="input"
              />
              {errors.end_date && (
                <p className="text-xs text-red-500 mt-1">{errors.end_date.message}</p>
              )}
            </div>

            {/* Reason */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Reason (Optional)
              </label>
              <textarea
                {...register('reason')}
                rows="4"
                className="input resize-none"
                placeholder="Provide any additional details..."
              />
            </div>

            {/* Submit Button */}
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
      </div>
    </div>
  )
}
