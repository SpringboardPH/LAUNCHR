import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader, Spinner, Modal, FormField, StatusBadge, ConfirmModal } from '../../components/ui/index.jsx'
import { Edit2, Trash2, Info } from 'lucide-react'
import {
  calendarEventTypeKeys,
  getAdminCalendarEventTypes,
  createCalendarEventType,
  updateCalendarEventType,
  deleteCalendarEventType,
} from '../../api/queries'

const EMPTY_TYPE_FORM = {
  name: '',
  description: '',
  color: '#3b82f6', // Default blue-500
  counts_as_absence: false,
  is_active: true,
}

export default function AdminCalendarEventTypesPage() {
  const qc = useQueryClient()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingType, setEditingType] = useState(null)
  const [form, setForm] = useState(EMPTY_TYPE_FORM)
  const [error, setError] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '', type: 'info' })

  const { data: eventTypes = [], isLoading } = useQuery({
    queryKey: calendarEventTypeKeys.list({ include_inactive: 1 }),
    queryFn: () => getAdminCalendarEventTypes({ include_inactive: 1 }),
  })

  const saveMutation = useMutation({
    mutationFn: (payload) => (
      editingType ? updateCalendarEventType(editingType.id, payload) : createCalendarEventType(payload)
    ),
    onSuccess: () => {
      setModalOpen(false)
      setEditingType(null)
      setForm(EMPTY_TYPE_FORM)
      setError('')
      qc.invalidateQueries({ queryKey: calendarEventTypeKeys.all })
    },
    onError: (err) => {
      setError(err?.response?.data?.message || 'Unable to save calendar event type')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id) => deleteCalendarEventType(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: calendarEventTypeKeys.all })
    },
    onError: (err) => {
      setError(err?.response?.data?.message || 'Unable to delete event type')
    },
  })

  const openModal = (type = null) => {
    if (type) {
      setEditingType(type)
      setForm({
        name: type.name,
        description: type.description || '',
        color: type.color,
        counts_as_absence: Boolean(type.counts_as_absence),
        is_active: Boolean(type.is_active),
      })
    } else {
      setEditingType(null)
      setForm(EMPTY_TYPE_FORM)
    }
    setError('')
    setModalOpen(true)
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    saveMutation.mutate(form)
  }

  const handleDelete = (type) => {
    setConfirmConfig({
      open: true,
      title: 'Delete Event Type',
      message: `Are you sure you want to delete the event type "${type.name}"? This will affect any existing events of this type.`,
      onConfirm: () => deleteMutation.mutate(type.id),
      type: 'danger'
    })
  }

  return (
    <div>
      <PageHeader
        title="Calendar Event Types"
        description="Configure different types of events (e.g., Holidays, Company Events) and their default behaviors."
        action={
          <button onClick={() => openModal()} className="btn-primary">
            Add Event Type
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

      <div className="card">
        {isLoading ? (
          <div className="py-20 flex items-center justify-center text-sm text-gray-500 gap-2">
            <Spinner size="md" /> Loading event types...
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type Name</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Color</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Behavior</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {eventTypes.map((type) => (
                  <tr key={type.id} className="hover:bg-gray-50/50">
                    <td className="px-6 py-4">
                      <div className="font-medium text-gray-900">{type.name}</div>
                      {type.description && <p className="text-xs text-gray-500 mt-0.5 max-w-xs truncate">{type.description}</p>}
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2">
                        <div className="w-4 h-4 rounded-full border border-gray-200" style={{ backgroundColor: type.color }} />
                        <span className="text-xs font-mono text-gray-500 uppercase">{type.color}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex flex-col gap-1">
                        <span className={`inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-tight ${type.counts_as_absence ? 'text-amber-600' : 'text-emerald-600'}`}>
                          {type.counts_as_absence ? 'Counts as Absence' : 'Non-Working / Holiday'}
                        </span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <StatusBadge status={type.is_active ? 'active' : 'inactive'} />
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button
                          onClick={() => openModal(type)}
                          className="p-1.5 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"
                          title="Edit"
                        >
                          <Edit2 size={16} />
                        </button>
                        <button
                          onClick={() => handleDelete(type)}
                          className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                          title="Delete"
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}

                {eventTypes.length === 0 && (
                  <tr>
                    <td colSpan={5} className="py-20 text-center text-sm text-gray-400">
                      No calendar event types configured.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editingType ? 'Edit Event Type' : 'Add Event Type'}
        size="md"
      >
        <form onSubmit={handleSubmit} className="space-y-4">
          <FormField label="Type Name" required error={error && error.includes('name') ? error : null}>
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="input"
              placeholder="e.g. Regular Holiday, Team Building"
              required
            />
          </FormField>

          <FormField label="Description">
            <textarea
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              className="input min-h-[80px]"
              placeholder="Explain how this event type should be used..."
            />
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField label="Default Color" required>
              <div className="flex gap-2">
                <input
                  type="color"
                  value={form.color}
                  onChange={(e) => setForm({ ...form, color: e.target.value })}
                  className="w-10 h-10 p-0 border-0 rounded overflow-hidden cursor-pointer"
                />
                <input
                  value={form.color}
                  onChange={(e) => setForm({ ...form, color: e.target.value })}
                  className="input font-mono uppercase"
                  placeholder="#HEXCODE"
                  maxLength={7}
                  required
                />
              </div>
            </FormField>

            <div className="flex flex-col gap-2">
              <label className="text-xs font-medium text-gray-600">Behavior</label>
              <div className="space-y-3 rounded-lg border border-gray-200 p-4">
                <label className="flex items-center justify-between gap-3 text-sm text-gray-700 cursor-pointer">
                  <div className="flex items-center gap-1.5">
                    <span>Counts as absence</span>
                    <div className="group relative">
                      <Info size={12} className="text-gray-400" />
                      <div className="hidden group-hover:block absolute bottom-full left-1/2 -translate-x-1/2 mb-2 p-2 bg-gray-900 text-white text-[10px] rounded shadow-xl w-48 z-10">
                        If checked, employees are expected to work unless they file a leave. If unchecked (like Holidays), it won't count as an absence.
                      </div>
                    </div>
                  </div>
                  <input
                    type="checkbox"
                    checked={form.counts_as_absence}
                    onChange={(e) => setForm({ ...form, counts_as_absence: e.target.checked })}
                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                  />
                </label>
                <label className="flex items-center justify-between gap-3 text-sm text-gray-700 cursor-pointer">
                  <span>Is Active</span>
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                  />
                </label>
              </div>
            </div>
          </div>

          {error && !error.includes('name') && <p className="text-xs text-red-500">{error}</p>}

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={() => setModalOpen(false)} className="btn-secondary">
              Cancel
            </button>
            <button
              type="submit"
              disabled={saveMutation.isPending}
              className="btn-primary min-w-[100px]"
            >
              {saveMutation.isPending ? 'Saving...' : (editingType ? 'Update' : 'Create')}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
