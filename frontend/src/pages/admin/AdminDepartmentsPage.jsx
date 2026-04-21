import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getAdminDepartments,
  createAdminDepartment,
  updateAdminDepartment,
  deleteAdminDepartment,
  hardDeleteAdminDepartment,
  restoreAdminDepartment,
  adminDepartmentKeys,
} from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import { useState } from 'react'
import { Plus, Trash2, RotateCcw, Edit2 } from 'lucide-react'

export default function AdminDepartmentsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({ name: '', description: '' })

  const { data: departments, isLoading } = useQuery({
    queryKey: adminDepartmentKeys.all,
    queryFn: getAdminDepartments,
  })

  const createMutation = useMutation({
    mutationFn: createAdminDepartment,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminDepartmentKeys.all })
      setFormData({ name: '', description: '' })
      setShowForm(false)
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => updateAdminDepartment(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminDepartmentKeys.all })
      setFormData({ name: '', description: '' })
      setEditingId(null)
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteAdminDepartment,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminDepartmentKeys.all })
    },
  })

  const hardDeleteMutation = useMutation({
    mutationFn: hardDeleteAdminDepartment,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminDepartmentKeys.all })
    },
  })

  const restoreMutation = useMutation({
    mutationFn: restoreAdminDepartment,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminDepartmentKeys.all })
    },
  })

  if (isLoading) return <PageSpinner />

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!formData.name.trim()) return

    if (editingId) {
      updateMutation.mutate({ id: editingId, data: formData })
    } else {
      createMutation.mutate(formData)
    }
  }

  const handleEdit = (dept) => {
    setFormData({ name: dept.name, description: dept.description || '' })
    setEditingId(dept.id)
    setShowForm(true)
  }

  const handleCancel = () => {
    setFormData({ name: '', description: '' })
    setEditingId(null)
    setShowForm(false)
  }

  const activeDepartments = departments?.filter(d => !d.deleted_at) || []
  const trashedDepartments =
    departments?.filter(d => d.deleted_at)?.length || 0

  return (
    <div>
      <PageHeader
        title="Departments"
        description="Manage company departments and organizational units"
        action={
          !showForm && (
            <button
              onClick={() => setShowForm(true)}
              className="btn-primary"
            >
              <Plus size={16} /> New Department
            </button>
          )
        }
      />

      {showForm && (
        <div className="card p-5 mb-5">
          <h3 className="text-sm font-semibold text-gray-700 mb-4">
            {editingId ? 'Edit Department' : 'Create Department'}
          </h3>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Department Name *
              </label>
              <input
                type="text"
                value={formData.name}
                onChange={e =>
                  setFormData(prev => ({ ...prev, name: e.target.value }))
                }
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="e.g., Engineering, Sales, HR"
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Description
              </label>
              <textarea
                value={formData.description}
                onChange={e =>
                  setFormData(prev => ({
                    ...prev,
                    description: e.target.value,
                  }))
                }
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="Optional department description"
                rows="3"
              />
            </div>
            <div className="flex gap-3">
              <button
                type="submit"
                disabled={
                  createMutation.isPending || updateMutation.isPending
                }
                className="btn-primary disabled:opacity-50"
              >
                {createMutation.isPending || updateMutation.isPending
                  ? 'Saving...'
                  : 'Save'}
              </button>
              <button
                type="button"
                onClick={handleCancel}
                className="btn-secondary"
              >
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Active Departments */}
      <div className="card p-5 mb-5">
        <h3 className="text-sm font-semibold text-gray-700 mb-4">Active Departments</h3>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="border-b border-gray-100">
              <tr>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">
                  Name
                </th>
                <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">
                  Description
                </th>
                <th className="pb-2 text-right text-xs text-gray-400 font-medium pr-4">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {activeDepartments.map(dept => (
                <tr key={dept.id} className="hover:bg-gray-50">
                  <td className="py-2.5 pr-4 font-medium text-gray-900">
                    {dept.name}
                  </td>
                  <td className="py-2.5 pr-4 text-gray-600 text-sm">
                    {dept.description || '-'}
                  </td>
                  <td className="py-2.5 pr-4 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => handleEdit(dept)}
                        className="p-2 text-blue-600 hover:bg-blue-50 rounded transition"
                        title="Edit"
                      >
                        <Edit2 size={18} />
                      </button>
                      <button
                        onClick={() => deleteMutation.mutate(dept.id)}
                        disabled={deleteMutation.isPending}
                        className="p-2 text-red-600 hover:bg-red-50 rounded transition disabled:opacity-50"
                        title="Soft Delete"
                      >
                        <Trash2 size={18} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {activeDepartments.length === 0 && (
            <div className="py-6 text-center text-gray-400 text-sm">
              No departments yet
            </div>
          )}
        </div>
      </div>

      {/* Trashed Departments */}
      {trashedDepartments > 0 && (
        <div className="card p-5">
          <h3 className="text-sm font-semibold text-gray-700 mb-4">
            Deleted Departments ({trashedDepartments})
          </h3>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-gray-100">
                <tr>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">
                    Name
                  </th>
                  <th className="pb-2 text-left text-xs text-gray-400 font-medium pr-4">
                    Description
                  </th>
                  <th className="pb-2 text-right text-xs text-gray-400 font-medium pr-4">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {departments
                  ?.filter(d => d.deleted_at)
                  .map(dept => (
                    <tr key={dept.id} className="opacity-75 hover:bg-gray-50">
                      <td className="py-2.5 pr-4 font-medium text-gray-900">
                        {dept.name}
                      </td>
                      <td className="py-2.5 pr-4 text-gray-600 text-sm">
                        {dept.description || '-'}
                      </td>
                      <td className="py-2.5 pr-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => restoreMutation.mutate(dept.id)}
                            disabled={restoreMutation.isPending}
                            className="p-2 text-green-600 hover:bg-green-50 rounded transition disabled:opacity-50"
                            title="Restore"
                          >
                            <RotateCcw size={18} />
                          </button>
                          <button
                            onClick={() =>
                              hardDeleteMutation.mutate(dept.id)
                            }
                            disabled={hardDeleteMutation.isPending}
                            className="p-2 text-red-600 hover:bg-red-50 rounded transition disabled:opacity-50"
                            title="Permanent Delete"
                          >
                            <Trash2 size={18} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
