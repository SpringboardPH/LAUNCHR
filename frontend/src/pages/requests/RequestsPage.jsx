import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { getRequests, approveRequest, rejectRequest, requestKeys } from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, FormField, Spinner, ConfirmModal } from '../../components/ui/index.jsx'
import { Check, X, Eye, ClipboardList } from 'lucide-react'

const REQUEST_TYPES = [
  { value: '', label: 'All Types' },
  { value: 'overtime', label: 'Overtime' },
  { value: 'half_day', label: 'Half-Day' },
  { value: 'undertime', label: 'Undertime' },
  { value: 'concern', label: 'Concern' },
  { value: 'schedule_change', label: 'Schedule Change' },
  { value: 'certificate_of_employment', label: 'Certificate of Employment' },
  { value: 'other', label: 'Other' },
]

function formatType(type) {
  if (!type) return '—'
  return type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function formatMeta(meta, requestType) {
  if (!meta || typeof meta !== 'object') return null
  return Object.entries(meta).map(([key, value]) => ({
    label: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
    value: value !== null && value !== undefined ? String(value) : '—',
  }))
}

export default function RequestsPage() {
  const [status, setStatus] = useState('pending')
  const [typeFilter, setTypeFilter] = useState('')
  const [viewRequest, setViewRequest] = useState(null)
  const [rejectModal, setRejectModal] = useState(null) // request id
  const [rejectNotes, setRejectNotes] = useState('')
  const [approveNotes, setApproveNotes] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: requestKeys.list({ status, request_type: typeFilter || undefined }),
    queryFn: () => getRequests({ status, ...(typeFilter ? { request_type: typeFilter } : {}) }),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id, notes }) => approveRequest(id, notes || null),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: requestKeys.all })
      setConfirmConfig(c => ({ ...c, open: false }))
      setApproveNotes('')
    },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, notes }) => rejectRequest(id, notes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: requestKeys.all })
      setRejectModal(null)
      setRejectNotes('')
    },
  })

  const requests = data?.data ?? []

  const handleApproveClick = (request) => {
    const employeeName = `${request.employee?.first_name ?? ''} ${request.employee?.last_name ?? ''}`.trim()
    const typeName = formatType(request.request_type)
    setApproveNotes('')
    setConfirmConfig({
      open: true,
      title: 'Approve Request',
      message: `Approve this ${typeName} request from ${employeeName}?`,
      onConfirm: () => approveMutation.mutate({ id: request.id, notes: approveNotes }),
      type: 'info',
    })
  }

  const handleRejectClick = (request) => {
    setRejectNotes('')
    setRejectModal(request.id)
  }

  return (
    <div>
      <PageHeader
        title="Employee Requests"
        description="Review and action employee requests"
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig(c => ({ ...c, open: false }))}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />

      {/* Filter row */}
      <div className="flex flex-wrap items-center gap-3 mb-5">
        {/* Status tabs */}
        <div className="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
          {['pending', 'approved', 'rejected'].map(s => (
            <button
              key={s}
              onClick={() => setStatus(s)}
              className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors capitalize ${
                status === s ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {s}
            </button>
          ))}
        </div>

        {/* Type filter */}
        <select
          value={typeFilter}
          onChange={e => setTypeFilter(e.target.value)}
          className="input py-1.5 text-sm w-auto"
        >
          {REQUEST_TYPES.map(t => (
            <option key={t.value} value={t.value}>{t.label}</option>
          ))}
        </select>
      </div>

      {isLoading ? <PageSpinner /> : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                {['Employee', 'Type', 'Subject', 'Filed', 'Status', 'Actions'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {requests.map(request => (
                <tr key={request.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">
                    {request.employee?.first_name} {request.employee?.last_name}
                  </td>
                  <td className="px-4 py-3 text-gray-600 capitalize">
                    {formatType(request.request_type)}
                  </td>
                  <td className="px-4 py-3 text-gray-500 max-w-xs truncate" title={request.subject}>
                    {request.subject || '—'}
                  </td>
                  <td className="px-4 py-3 text-gray-400 text-[10px] leading-tight">
                    {request.created_at ? format(new Date(request.created_at), 'MMM d, yyyy') : '—'}
                    <br />
                    <span className="text-[9px] text-gray-300">
                      {request.created_at ? format(new Date(request.created_at), 'h:mm a') : ''}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={request.status} />
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1">
                      <button
                        onClick={() => setViewRequest(request)}
                        className="btn-ghost p-1.5 text-brand-500 hover:text-brand-700 hover:bg-brand-50"
                        title="View Details"
                      >
                        <Eye size={14} />
                      </button>
                      {request.status === 'pending' && (
                        <>
                          <button
                            onClick={() => handleApproveClick(request)}
                            disabled={approveMutation.isPending}
                            className="btn-ghost p-1.5 text-green-500 hover:text-green-700 hover:bg-green-50"
                            title="Approve"
                          >
                            <Check size={14} />
                          </button>
                          <button
                            onClick={() => handleRejectClick(request)}
                            className="btn-ghost p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50"
                            title="Reject"
                          >
                            <X size={14} />
                          </button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {requests.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-12 text-center">
                    <ClipboardList size={32} className="text-gray-200 mx-auto mb-2" />
                    <p className="text-sm text-gray-400">No {status} requests</p>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* View Details Modal */}
      <Modal open={Boolean(viewRequest)} onClose={() => setViewRequest(null)} title="Request Details" size="md">
        {viewRequest && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Employee</p>
                <p className="text-sm font-semibold text-gray-900">
                  {viewRequest.employee?.first_name} {viewRequest.employee?.last_name}
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <StatusBadge status={viewRequest.status} />
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                <p className="text-sm text-gray-700">{formatType(viewRequest.request_type)}</p>
              </div>
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Filed At</p>
                <p className="text-sm text-gray-700">
                  {viewRequest.created_at ? format(new Date(viewRequest.created_at), 'MMM d, yyyy h:mm a') : '—'}
                </p>
              </div>
            </div>

            <div>
              <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Subject</p>
              <p className="text-sm text-gray-700">{viewRequest.subject || '—'}</p>
            </div>

            {viewRequest.details && (
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Details</p>
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100 text-sm text-gray-700">
                  {viewRequest.details}
                </div>
              </div>
            )}

            {/* Meta fields formatted per type */}
            {viewRequest.meta && formatMeta(viewRequest.meta, viewRequest.request_type)?.length > 0 && (
              <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Additional Info</p>
                <div className="grid grid-cols-2 gap-3">
                  {formatMeta(viewRequest.meta, viewRequest.request_type).map(({ label, value }) => (
                    <div key={label}>
                      <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">{label}</p>
                      <p className="text-sm text-gray-700">{value}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {viewRequest.status === 'rejected' && (
              <div className="p-3 bg-red-50 rounded-lg border border-red-100">
                <p className="text-[10px] font-bold text-red-400 uppercase tracking-widest mb-1">HR Response Notes</p>
                <p className="text-sm text-red-900 font-medium">
                  {viewRequest.response_notes || 'No notes provided.'}
                </p>
              </div>
            )}

            {viewRequest.status === 'approved' && (
              <div className="p-3 bg-emerald-50 rounded-lg border border-emerald-100">
                <p className="text-[10px] font-bold text-emerald-600 uppercase tracking-widest mb-1">Approval Info</p>
                {viewRequest.response_notes && (
                  <p className="text-sm text-emerald-900 mb-1">{viewRequest.response_notes}</p>
                )}
                {viewRequest.approver && (
                  <p className="text-xs text-emerald-700">
                    Approved by {viewRequest.approver?.name ?? viewRequest.approver?.first_name}
                  </p>
                )}
              </div>
            )}

            <div className="flex justify-end pt-2">
              <button onClick={() => setViewRequest(null)} className="btn-primary px-6">Close</button>
            </div>
          </div>
        )}
      </Modal>

      {/* Reject Modal */}
      <Modal open={Boolean(rejectModal)} onClose={() => setRejectModal(null)} title="Reject Request" size="sm">
        <div className="space-y-3">
          <FormField label="Response notes" required>
            <textarea
              className="input h-20 resize-none"
              value={rejectNotes}
              onChange={e => setRejectNotes(e.target.value)}
              placeholder="Provide a reason for rejection…"
            />
          </FormField>
          {rejectMutation.isError && (
            <p className="text-xs text-red-500">{rejectMutation.error?.response?.data?.message}</p>
          )}
          <div className="flex justify-end gap-2">
            <button onClick={() => setRejectModal(null)} className="btn-secondary">Cancel</button>
            <button
              onClick={() => rejectMutation.mutate({ id: rejectModal, notes: rejectNotes })}
              disabled={!rejectNotes.trim() || rejectMutation.isPending}
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
