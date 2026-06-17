import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { getUser, createUser, updateUser, userKeys } from '../../api/queries'
import { PageHeader, PageSpinner, FormField, ConfirmModal } from '../../components/ui/index.jsx'
import { ArrowLeft, Save, Loader2 } from 'lucide-react'

// Form validation schema
const userSchema = (isEdit) => z.object({
  name: z.string().min(2, 'Name is required'),
  email: z.string().email('Valid email is required'),
  role: z.enum(['admin', 'hr', 'accounting', 'employee']),
  password: isEdit 
    ? z.string().min(8, 'Password must be at least 8 characters').optional().or(z.literal(''))
    : z.string().min(8, 'Password must be at least 8 characters'),
})

export default function UserFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })


  const { data: user, isLoading } = useQuery({
    queryKey: userKeys.detail(id),
    queryFn: () => getUser(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(userSchema(isEdit)),
    defaultValues: {
      name: '',
      email: '',
      role: 'employee',
      password: '',
    },
  })

  useEffect(() => {
    if (user) {
      reset({
        name: user.name,
        email: user.email,
        role: user.role,
        password: '', // Never populate password field
      })
    }
  }, [user, reset])

  const mutation = useMutation({
    mutationFn: (data) => isEdit ? updateUser(id, data) : createUser(data),
    onSuccess: () => {
      queryClient.invalidateQueries(userKeys.all)
      navigate('/admin/users')
    },
  })

  const onSubmit = (data) => {
    // Clean up empty password on edit
    if (isEdit && !data.password) {
      delete data.password
    }


    setConfirmConfig({
      open: true,
      title: isEdit ? 'Save Changes' : 'Create User',
      message: isEdit 
        ? `Are you sure you want to save changes for ${user?.name}?` 
        : `Are you sure you want to create a new user account for ${data.name}?`,
      onConfirm: () => mutation.mutate(data),
      type: 'info'
    })
  }

  if (isEdit && isLoading) return <PageSpinner />

  return (
    <div className="max-w-3xl">
      <PageHeader
        title={isEdit ? 'Edit User' : 'New User'}
        description={isEdit ? 'Update system user details and roles' : 'Create a new user account for system access'}
        action={
          <Link to="/admin/users" className="btn-secondary">
            <ArrowLeft size={16} /> Back to Users
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

      <div className="card p-6">
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
          <div className="grid grid-cols-1 gap-6">
            <h3 className="text-sm font-medium text-gray-900 border-b pb-2">Account Information</h3>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <FormField label="Full Name" error={errors.name?.message} required>
                <input
                  type="text"
                  className="input"
                  placeholder="e.g. Jane Doe"
                  {...register('name')}
                />
              </FormField>

              <FormField label="Email Address" error={errors.email?.message} required>
                <input
                  type="email"
                  className="input"
                  placeholder="jane@company.com"
                  {...register('email')}
                />
              </FormField>

              <FormField label="System Role" error={errors.role?.message} required>
                <select className="input" {...register('role')}>
                  <option value="employee">Standard Employee</option>
                  <option value="hr">Human Resources (HR)</option>
                  <option value="accounting">Accounting</option>
                  <option value="admin">System Administrator</option>
                </select>
              </FormField>

              <FormField 
                label={isEdit ? "New Password" : "Password"} 
                error={errors.password?.message}
                required={!isEdit}
              >
                <input
                  type="password"
                  className="input"
                  placeholder={isEdit ? "Leave blank to keep current" : "••••••••"}
                  {...register('password')}
                />
                {isEdit && (
                  <p className="mt-1 text-xs text-gray-500">Only fill this if you want to change the user's password.</p>
                )}
              </FormField>
            </div>
          </div>

          {mutation.isError && (
            <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
              {mutation.error?.response?.data?.message ?? 'Failed to save user.'}
            </div>
          )}

          <div className="flex justify-end pt-4 border-t border-gray-100">
            <button
              type="submit"
              className="btn-primary"
              disabled={isSubmitting || mutation.isLoading}
            >
              {(isSubmitting || mutation.isLoading) ? (
                <><Loader2 size={16} className="animate-spin" /> Saving...</>
              ) : (
                <><Save size={16} /> {isEdit ? 'Save Changes' : 'Create User'}</>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
