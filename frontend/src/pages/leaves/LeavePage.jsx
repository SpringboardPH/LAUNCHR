import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { format } from 'date-fns'
import {
  getLeaves, createLeave, approveLeave, rejectLeave,
  getEmployees, getLeaveTypes, leaveKeys, employeeKeys, leaveTypeKeys, dashboardKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, FormField, Spinner, ConfirmModal } from '../../components/ui/index.jsx'
import { Plus, Check, X, CalendarOff } from 'lucide-react'

export default function LeavePage() {
  const [status, setStatus] = useState('pending')
  const [showModal, setShowModal] = useState(false)
  const [rejectModal, setRejectModal] = useState(null) // leaveId
  const [rejectReason, setRejectReason] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: leaveKeys.list({ status }),
    queryFn: () => getLeaves({ status }),
  })

  const { data: employees } = useQuery({
    queryKey: employeeKeys.list({}),
    queryFn: () => getEmployees({ status: 'active' }),
  })

  const { data: leaveTypes } = useQuery({
    queryKey: leaveTypeKeys.all,
    queryFn: () => getLeaveTypes(),
  })

  const { register, handleSubmit, reset, setValue, watch, formState: { errors } } = useForm({
    defaultValues: {
      leave_type: '',
    },
  })

  const selectedLeaveType = watch('leave_type')

  const createMutation = useMutation({
    mutationFn: createLeave,
    onSuccess: () => { qc.invalidateQueries({ queryKey: leaveKeys.all }); setShowModal(false); reset() },
  })

  const approveMutation = useMutation({
    mutationFn: approveLeave,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: leaveKeys.all })
      qc.invalidateQueries({ queryKey: dashboardKeys.all })
    },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }) => rejectLeave(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: leaveKeys.all })
      qc.invalidateQueries({ queryKey: dashboardKeys.all })
      setRejectModal(null)
      setRejectReason('')
    },
  })

  const leaves = data?.data ?? []
  const activeEmps = employees?.data ?? []
  const activeLeaveTypes = leaveTypes ?? []

  useEffect(() => {
    if (!activeLeaveTypes.length) return

    if (!activeLeaveTypes.some((type) => type.code === selectedLeaveType)) {
      setValue('leave_type', activeLeaveTypes[0].code, { shouldValidate: true })
    }
  }, [activeLeaveTypes, selectedLeaveType, setValue])

  const handleApproveClick = (leave) => {
    setConfirmConfig({
      open: true,
      title: 'Approve Leave Request',
      message: `Are you sure you want to approve the leave request for ${leave.employee?.first_name} ${leave.employee?.last_name}?`,
      onConfirm: () => approveMutation.mutate(leave.id),
      type: 'info'
    })
  }

  return (
    <div>
      <PageHeader
        title="Leave Management"
        description="Manage employee leave requests"
        action={
          <button onClick={() => setShowModal(true)} className="btn-primary">
            <Plus size={14} /> New Request
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

      {/* Filter tabs */}
      <div className="flex gap-1 mb-5 bg-gray-100 p-1 rounded-lg w-fit">
        {['pending', 'approved', 'rejected'].map(s => (
          <button key={s} onClick={() => setStatus(s)}
            className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors capitalize ${
              status === s ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            {s}
          </button>
        ))}
      </div>

      {isLoading ? <PageSpinner /> : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                {['Employee', 'Type', 'Dates', 'Days', 'Reason', 'Status', 'Actions'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {leaves.map(leave => (
                <tr key={leave.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">
                    {leave.employee?.first_name} {leave.employee?.last_name}
                  </td>
                  <td className="px-4 py-3 text-gray-600 capitalize">
                    {typeof leave.leave_type === 'string' 
                      ? leave.leave_type.replace(/_/g, ' ') 
                      : (leave.leave_type?.name || leave.leave_type?.code || 'Unknown')}
                  </td>
                  <td className="px-4 py-3 text-gray-600 text-xs">
                    {format(new Date(leave.start_date), 'MMM d')} – {format(new Date(leave.end_date), 'MMM d, yyyy')}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{leave.days_requested}d</td>
                  <td className="px-4 py-3 text-gray-500 max-w-xs truncate" title={leave.status === 'rejected' ? leave.rejection_reason : leave.reason}>
                    {leave.status === 'rejected' ? (
                      <span className="text-red-600 font-medium">RJ: {leave.rejection_reason || 'No reason provided'}</span>
                    ) : (
                      leave.reason
                    )}
                  </td>
                  <td className="px-4 py-3"><StatusBadge status={leave.status} /></td>
                  <td className="px-4 py-3">
                    {leave.status === 'pending' && (
                      <div className="flex gap-1">
                        <button onClick={() => handleApproveClick(leave)}
                          disabled={approveMutation.isPending}
                          className="btn-ghost p-1.5 text-green-500 hover:text-green-700 hover:bg-green-50" title="Approve">
                          <Check size={14} />
                        </button>
                        <button onClick={() => setRejectModal(leave.id)}
                          className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50" title="Reject">
                          <X size={14} />
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
              {leaves.length === 0 && (
                <tr>
                  <td colSpan={7} className="py-12 text-center">
                    <CalendarOff size={32} className="text-gray-200 mx-auto mb-2" />
                    <p className="text-sm text-gray-400">No {status} leave requests</p>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* New leave request modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title="New Leave Request">
        <form onSubmit={handleSubmit(data => createMutation.mutate(data))} className="space-y-4">
          <FormField label="Employee" error={errors.employee_id?.message} required>
            <select {...register('employee_id', { required: 'Required' })} className="input">
              <option value="">Select employee…</option>
              {activeEmps.map(e => (
                <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>
              ))}
            </select>
          </FormField>
          <FormField label="Leave type" error={errors.leave_type?.message} required>
            <select {...register('leave_type', { required: 'Required' })} className="input">
              {activeLeaveTypes.map(type => (
                <option key={type.id} value={type.code}>
                  {type.name}
                </option>
              ))}
            </select>
          </FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Start date" error={errors.start_date?.message} required>
              <input type="date" {...register('start_date', { required: 'Required' })} className="input" />
            </FormField>
            <FormField label="End date" error={errors.end_date?.message} required>
              <input type="date" {...register('end_date', { required: 'Required' })} className="input" />
            </FormField>
          </div>
          <FormField label="Reason" error={errors.reason?.message} required>
            <textarea {...register('reason', { required: 'Required' })} className="input h-20 resize-none" />
          </FormField>
          {createMutation.isError && (
            <p className="text-xs text-red-500">{createMutation.error?.response?.data?.message}</p>
          )}
          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={() => setShowModal(false)} className="btn-secondary">Cancel</button>
            <button type="submit" disabled={createMutation.isPending} className="btn-primary">
              {createMutation.isPending ? <Spinner size="sm" /> : 'Submit request'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Reject reason modal */}
      <Modal open={Boolean(rejectModal)} onClose={() => setRejectModal(null)} title="Reject Leave Request" size="sm">
        <div className="space-y-3">
          <FormField label="Reason for rejection" required>
            <textarea
              className="input h-20 resize-none" value={rejectReason}
              onChange={e => setRejectReason(e.target.value)} placeholder="Provide a reason…"
            />
          </FormField>
          <div className="flex justify-end gap-2">
            <button onClick={() => setRejectModal(null)} className="btn-secondary">Cancel</button>
            <button
              onClick={() => rejectMutation.mutate({ id: rejectModal, reason: rejectReason })}
              disabled={!rejectReason || rejectMutation.isPending}
              className="btn-danger"
            >
              {rejectMutation.isPending ? <Spinner size="sm" /> : 'Reject'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
