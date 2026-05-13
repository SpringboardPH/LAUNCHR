import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getUsers, getTrashedUsers, deleteUser, hardDeleteUser, restoreUser, userKeys, employeeScheduleKeys } from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { PageHeader, PageSpinner, EmptyState, ConfirmModal } from '../../components/ui/index.jsx'
import { Plus, Search, Pencil, Trash2, Users, UserX, RotateCcw, Archive } from 'lucide-react'
import { format } from 'date-fns'

export default function UserListPage() {
  const { user: currentUser } = useAuth()
  const [search, setSearch] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })
  const queryClient = useQueryClient()


  const { data, isLoading } = useQuery({
    queryKey: userKeys.list({ search }),
    queryFn: () => getUsers({ search }),
    keepPreviousData: true,
  })

  const { data: trashedData, isLoading: isLoadingTrashed } = useQuery({
    queryKey: userKeys.trashed({ search }),
    queryFn: () => getTrashedUsers({ search }),
    keepPreviousData: true,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteUser,
    onSuccess: () => {
      queryClient.invalidateQueries(userKeys.all)
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', 'trashed'] })
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
    },
    onError: (error) => {
      alert(error.response?.data?.message || 'Failed to delete user')
    }
  })

  const hardDeleteMutation = useMutation({
    mutationFn: hardDeleteUser,
    onSuccess: () => {
      queryClient.invalidateQueries(userKeys.all)
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', 'trashed'] })
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
    },
    onError: (error) => {
      alert(error.response?.data?.message || 'Failed to permanently delete user')
    }
  })

  const restoreMutation = useMutation({
    mutationFn: restoreUser,
    onSuccess: () => {
      queryClient.invalidateQueries(userKeys.all)
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', 'trashed'] })
      queryClient.invalidateQueries({ queryKey: employeeScheduleKeys.all })
    },
    onError: (error) => {
      alert(error.response?.data?.message || 'Failed to restore user')
    }
  })

  const users = data?.data ?? []
  const trashedUsers = trashedData?.data ?? []

  const getDisplayEmail = (user) => {
    const email = user?.email || ''
    if (email.endsWith('@archived.local') && user?.employee?.email) {
      return user.employee.email
    }
    return email
  }

  return (
    <div>
      <PageHeader
        title="User Management"
        description="Manage system access and roles"
        action={
          <Link to="/hr/employees/new?manage_account=true" className="btn-primary">
            <Plus size={16} /> Add User
          </Link>
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

      <div className="flex flex-col sm:flex-row gap-3 mb-5">
        <div className="relative flex-1 sm:max-w-md">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            className="input pl-9" placeholder="Search by name or email..."
            value={search} onChange={e => setSearch(e.target.value)}
          />
        </div>
      </div>

      {isLoading ? <PageSpinner /> : users.length === 0 ? (
        <EmptyState icon={Users} title="No users found"
          description={search ? "No users match your search criteria" : "No users exist in the system yet"}
          action={<Link to="/hr/employees/new?manage_account=true" className="btn-primary"><Plus size={14} /> Add User</Link>}
        />
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">User</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Role</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Linked Employee</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Created At</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {users.map(u => (
                  <tr key={u.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-4 py-3">
                      <div>
                        <div className="font-medium text-gray-900">{u.name}</div>
                        <div className="text-gray-500 text-xs">{getDisplayEmail(u)}</div>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                        u.role === 'admin' ? 'bg-purple-100 text-purple-700' :
                        u.role === 'hr' ? 'bg-blue-100 text-blue-700' :
                        'bg-gray-100 text-gray-700'
                      }`}>
                        {u.role === 'admin' ? 'System Administrator' : 
                         u.role === 'hr' ? 'Human Resources' : 'Standard Employee'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {u.employee ? (
                        <div>
                          <div className="text-gray-900">{u.employee.first_name} {u.employee.last_name}</div>
                          <div className="text-gray-500 text-xs">{u.employee.department}</div>
                        </div>
                      ) : <span className="text-gray-400 italic">Not linked</span>}
                    </td>
                    <td className="px-4 py-3 text-gray-600">
                      {format(new Date(u.created_at), 'MMM d, yyyy')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-1">
                        {u.employee ? (
                          <Link to={`/hr/employees/${u.employee.id}/edit?manage_account=true`} className="btn-ghost p-1.5" title="Edit Account">
                            <Pencil size={14} />
                          </Link>
                        ) : (
                          <Link to={`/admin/users/${u.id}/edit`} className="btn-ghost p-1.5" title="Edit User">
                            <Pencil size={14} />
                          </Link>
                        )}
                        <button
                          onClick={() => {
                            setConfirmConfig({
                              open: true,
                              title: 'Soft Delete User',
                              message: `Soft delete ${u.name}? This will deactivate the linked employee record.`,
                              onConfirm: () => deleteMutation.mutate(u.id),
                              type: 'danger'
                            })
                          }}
                          disabled={currentUser?.id === u.id || deleteMutation.isPending || hardDeleteMutation.isPending}
                          className={`btn-ghost p-1.5 ${currentUser?.id === u.id ? 'text-gray-300' : 'text-red-400 hover:text-red-600 hover:bg-red-50'}`}
                          title={currentUser?.id === u.id ? "Cannot delete yourself" : "Soft delete user"}
                        >
                          <UserX size={14} />
                        </button>
                        <button
                          onClick={() => {
                            setConfirmConfig({
                              open: true,
                              title: 'Hard Delete User',
                              message: `Permanently delete ${u.name}? This will remove the user and linked employee record.`,
                              onConfirm: () => hardDeleteMutation.mutate(u.id),
                              type: 'danger'
                            })
                          }}
                          disabled={currentUser?.id === u.id || deleteMutation.isPending || hardDeleteMutation.isPending}
                          className={`btn-ghost p-1.5 ${currentUser?.id === u.id ? 'text-gray-300' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'}`}
                          title={currentUser?.id === u.id ? "Cannot delete yourself" : "Hard delete user"}
                        >
                          <Trash2 size={14} />
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

      <div className="card overflow-hidden mt-6">
        <div className="p-5 border-b border-gray-100 flex items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
              <Archive size={18} className="text-gray-500" />
              Deactivated Users
            </h2>
            <p className="text-sm text-gray-500 mt-1">View and restore soft-deleted user accounts.</p>
          </div>
          <span className="text-xs font-medium text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">
            {trashedUsers.length} deactivated
          </span>
        </div>

        {isLoadingTrashed ? (
          <PageSpinner />
        ) : trashedUsers.length === 0 ? (
          <div className="p-6">
            <EmptyState
              icon={Archive}
              title="No deactivated users"
              description="Soft-deleted users will appear here for review and restoration."
            />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">User</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Role</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Linked Employee</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Deleted At</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {trashedUsers.map(u => (
                  <tr key={u.id} className="hover:bg-gray-50 transition-colors opacity-90">
                    <td className="px-4 py-3">
                      <div>
                        <div className="font-medium text-gray-900">{u.name}</div>
                        <div className="text-gray-500 text-xs">{getDisplayEmail(u)}</div>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                        u.role === 'admin' ? 'bg-purple-100 text-purple-700' :
                        u.role === 'hr' ? 'bg-blue-100 text-blue-700' :
                        'bg-gray-100 text-gray-700'
                      }`}>
                        {u.role === 'admin' ? 'System Administrator' : 
                         u.role === 'hr' ? 'Human Resources' : 'Standard Employee'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {u.employee ? (
                        <div>
                          <div className="text-gray-900">{u.employee.first_name} {u.employee.last_name}</div>
                          <div className="text-gray-500 text-xs">{u.employee.department}</div>
                        </div>
                      ) : <span className="text-gray-400 italic">Not linked</span>}
                    </td>
                    <td className="px-4 py-3 text-gray-600">
                      {u.deleted_at ? format(new Date(u.deleted_at), 'MMM d, yyyy') : 'Unknown'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          onClick={() => {
                            setConfirmConfig({
                              open: true,
                              title: 'Restore User',
                              message: `Restore ${u.name}? This will reactivate the linked employee record.`,
                              onConfirm: () => restoreMutation.mutate(u.id),
                              type: 'info'
                            })
                          }}
                          disabled={restoreMutation.isPending || hardDeleteMutation.isPending}
                          className="btn-ghost p-1.5 text-green-600 hover:text-green-700 hover:bg-green-50"
                          title="Restore user"
                        >
                          <RotateCcw size={14} />
                        </button>
                        <button
                          onClick={() => {
                            setConfirmConfig({
                              open: true,
                              title: 'Hard Delete User',
                              message: `Permanently delete ${u.name}? This cannot be undone.`,
                              onConfirm: () => hardDeleteMutation.mutate(u.id),
                              type: 'danger'
                            })
                          }}
                          disabled={restoreMutation.isPending || hardDeleteMutation.isPending}
                          className="btn-ghost p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100"
                          title="Permanently delete user"
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
