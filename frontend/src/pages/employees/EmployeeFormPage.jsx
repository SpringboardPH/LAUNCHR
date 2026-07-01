import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getEmployee, createEmployee, updateEmployee, getEmployeeGroups, employeeKeys, getDepartments, departmentKeys, userKeys } from '../../api/queries'
import { PageHeader, FormField, PageSpinner, Spinner, ConfirmModal } from '../../components/ui/index.jsx'
import { useAuth } from '../../store/AuthContext'
import { ArrowLeft, Eye, EyeOff } from 'lucide-react'

const schema = z.object({
  first_name:   z.string().min(1, 'Required'),
  last_name:    z.string().min(1, 'Required'),
  email:        z.string().email('Invalid email'),
  phone:        z.string().nullable().optional(),
  position:     z.string().min(1, 'Required'),
  department:   z.string().min(1, 'Required'),
  hire_date:    z.string().min(1, 'Required'),
  salary:            z.coerce.number().min(0, 'Must be ≥ 0'),
  undeclared_salary: z.coerce.number().nullable().optional(),
  rate_type:         z.enum(['monthly', 'daily']),
  role:         z.enum(['employee', 'hr', 'accounting', 'admin']).optional(),
  password:     z.string().min(8, 'Must be at least 8 characters').optional().or(z.literal('')),
  bank_account_number: z.string().nullable().optional().refine(val => !val || (val.length >= 10 && val.length <= 12 && /^\d+$/.test(val)), {
    message: 'Must be between 10 and 12 digits'
  }),
  sss_number:        z.string().nullable().optional(),
  philhealth_number: z.string().nullable().optional(),
  pagibig_number:    z.string().nullable().optional(),
  tin_number:        z.string().nullable().optional(),
  group:             z.string().nullable().optional(),
})

export default function EmployeeFormPage() {
  const { user: currentUser } = useAuth()
  const [searchParams] = useSearchParams()
  const manageAccount = searchParams.get('manage_account') === 'true'
  const isAdmin = currentUser?.role === 'admin'
  const canManageAccount = isAdmin && manageAccount

  const [showPassword, setShowPassword] = useState(false)
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '' })

  const { data: emp, isLoading } = useQuery({
    queryKey: employeeKeys.detail(id),
    queryFn: () => getEmployee(id),
    enabled: isEdit,
  })

  const { data: departments = [] } = useQuery({
    queryKey: departmentKeys.all,
    queryFn: getDepartments,
  })

  const { data: existingGroups = [] } = useQuery({
    queryKey: employeeKeys.groups,
    queryFn: getEmployeeGroups,
  })

  // Filter only active departments
  const activeDepts = departments.filter(d => !d.deleted_at)

  const { register, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm({
    resolver: zodResolver(schema),
    defaultValues: { salary: 0, undeclared_salary: 0, rate_type: 'monthly', role: 'employee', password: '' },
  })

  const rateType = watch('rate_type')

  useEffect(() => {
    if (emp) reset({
      ...emp,
      hire_date: emp.hire_date?.split('T')[0] ?? emp.hire_date,
      role: emp.user?.role ?? 'employee',
    })
  }, [emp, reset])

  const mutation = useMutation({
    mutationFn: (data) => isEdit ? updateEmployee(id, data) : createEmployee(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeKeys.all })
      qc.invalidateQueries({ queryKey: userKeys.all })
      navigate(manageAccount ? '/admin/users' : '/hr/employees')
    },
  })

  if (isEdit && isLoading) return <PageSpinner />

  return (
    <div className="max-w-2xl">
      <PageHeader
        title={manageAccount ? (isEdit ? 'Manage User Account' : 'Add User Account') : (isEdit ? 'Edit Employee' : 'Add Employee')}
        description={manageAccount ? 'Manage login credentials and system permissions' : (isEdit ? `Editing ${emp?.first_name} ${emp?.last_name}` : 'Fill in the details below')}
        action={
          <button onClick={() => navigate(manageAccount ? '/admin/users' : '/hr/employees')} className="btn-secondary">
            <ArrowLeft size={14} /> Back
          </button>
        }
        help={[
          { heading: 'Basic Information', items: [
            'Fill in the employee\'s full name, email address, phone number, position title, department, and group.',
            'Group assignment is optional — click an existing group chip to assign, or leave blank.',
          ]},
          { heading: 'Employment Details', items: [
            'Set the hire date, select Monthly or Daily as the pay rate type, and enter the base salary.',
            'Undeclared Salary is an optional separate amount used for internal payroll calculations while keeping the declared salary different.',
          ]},
          { heading: 'Bank & Government IDs', items: [
            'Enter the bank account number, SSS, PhilHealth, Pag-IBIG, and TIN numbers.',
            'These are used by the payroll engine for statutory deduction computations.',
          ]},
          { heading: 'System Role (Admin only)', items: [
            'Admins can change the employee\'s system login role: Employee, HR, Accounting, or Admin.',
            'Use the password reset fields in this section to set or change the employee\'s login password.',
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

      <form 
        onSubmit={handleSubmit(data => {
          setConfirmConfig({
            open: true,
            title: isEdit ? 'Save Changes' : 'Add Employee',
            message: isEdit 
              ? `Save changes for ${data.first_name} ${data.last_name}?` 
              : `Add ${data.first_name} ${data.last_name} to the system?`,
            onConfirm: () => mutation.mutate(data),
            type: 'info'
          })
        })} 
        className="card p-6 space-y-5"
      >
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="First name" error={errors.first_name?.message} required>
            <input {...register('first_name')} className={`input ${errors.first_name ? 'input-error' : ''}`} />
          </FormField>
          <FormField label="Last name" error={errors.last_name?.message} required>
            <input {...register('last_name')} className={`input ${errors.last_name ? 'input-error' : ''}`} />
          </FormField>
        </div>

        <FormField label="Email address" error={errors.email?.message} required>
          <input type="email" {...register('email')} className={`input ${errors.email ? 'input-error' : ''}`} />
        </FormField>

        <FormField label="Phone number" error={errors.phone?.message}>
          <input {...register('phone')} className="input" placeholder="+63 9XX XXX XXXX" />
        </FormField>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="Position" error={errors.position?.message} required>
            <input {...register('position')} className={`input ${errors.position ? 'input-error' : ''}`} />
          </FormField>
          <FormField label="Department" error={errors.department?.message} required>
            <select {...register('department')} className={`input ${errors.department ? 'input-error' : ''}`}>
              <option value="">Select…</option>
              {activeDepts.map(d => <option key={d.id} value={d.name}>{d.name}</option>)}
            </select>
          </FormField>
        </div>

        <FormField label="Group" error={errors.group?.message}>
          <input {...register('group')} className="input" placeholder="e.g. Night Shift, Probationary, Field" />
          {existingGroups.length > 0 && (
            <div className="flex flex-wrap gap-1.5 mt-2">
              {existingGroups.map(g => (
                <button
                  key={g}
                  type="button"
                  onClick={() => setValue('group', g, { shouldValidate: true })}
                  className={`px-2.5 py-1 rounded-full text-xs font-medium border transition-colors ${watch('group') === g ? 'bg-brand-600 text-white border-brand-600' : 'bg-gray-50 text-gray-600 border-gray-200 hover:border-brand-400 hover:text-brand-600'}`}
                >
                  {g}
                </button>
              ))}
            </div>
          )}
        </FormField>

        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          <FormField label="Hire date" error={errors.hire_date?.message} required>
            <input type="date" {...register('hire_date')} className={`input ${errors.hire_date ? 'input-error' : ''}`} />
          </FormField>
          <FormField label="Rate Type" error={errors.rate_type?.message} required>
            <select {...register('rate_type')} className={`input ${errors.rate_type ? 'input-error' : ''}`}>
              <option value="monthly">Monthly Fixed</option>
              <option value="daily">Daily Rate</option>
            </select>
          </FormField>
          <FormField label={rateType === 'daily' ? 'Daily Rate (₱)' : 'Monthly Salary (₱)'} error={errors.salary?.message} required>
            <input type="number" step="0.01" {...register('salary')} className={`input ${errors.salary ? 'input-error' : ''}`} />
          </FormField>
        </div>

        <FormField label="Undeclared: Salary + Allowance" error={errors.undeclared_salary?.message}>
          <input type="number" step="0.01" {...register('undeclared_salary')} className="input" placeholder="Optional side information" />
        </FormField>

        <FormField label="Bank Account Number" error={errors.bank_account_number?.message}>
          <input {...register('bank_account_number')} className={`input ${errors.bank_account_number ? 'input-error' : ''}`} placeholder="10-12 digits" />
        </FormField>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <FormField label="SSS Number" error={errors.sss_number?.message}>
            <input {...register('sss_number')} className="input" placeholder="00-0000000-0" />
          </FormField>
          <FormField label="PhilHealth Number" error={errors.philhealth_number?.message}>
            <input {...register('philhealth_number')} className="input" placeholder="00-000000000-0" />
          </FormField>
          <FormField label="Pag-IBIG Number" error={errors.pagibig_number?.message}>
            <input {...register('pagibig_number')} className="input" placeholder="0000-0000-0000" />
          </FormField>
          <FormField label="TIN Number" error={errors.tin_number?.message}>
            <input {...register('tin_number')} className="input" placeholder="000-000-000-000" />
          </FormField>
        </div>

        {canManageAccount && (
          <div className="space-y-5 border-t border-gray-100 pt-5">
            <h3 className="text-sm font-semibold text-gray-900">System Access</h3>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <FormField label="System Role" error={errors.role?.message} required>
                <select {...register('role')} className={`input ${errors.role ? 'input-error' : ''}`}>
                  <option value="employee">Standard Employee</option>
                  <option value="hr">Human Resources (HR)</option>
                  <option value="accounting">Accounting</option>
                  {canManageAccount && <option value="admin">System Administrator</option>}
                </select>
              </FormField>

              <FormField label={isEdit ? "Change Password" : "Account Password"} error={errors.password?.message}>
                <div className="relative">
                  <input 
                    type={showPassword ? "text" : "password"} 
                    {...register('password')} 
                    className={`input pr-10 ${errors.password ? 'input-error' : ''}`}
                    placeholder={isEdit ? "Leave blank to keep current" : "Default: password123"} 
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  >
                    {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                  </button>
                </div>
              </FormField>
            </div>
          </div>
        )}

        {mutation.isError && (
          <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
            {mutation.error?.response?.data?.message ?? 'Something went wrong.'}
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={() => navigate(manageAccount ? '/admin/users' : '/hr/employees')} className="btn-secondary">
            Cancel
          </button>
          <button type="submit" disabled={mutation.isPending} className="btn-primary">
            {mutation.isPending ? <Spinner size="sm" /> : isEdit ? 'Save changes' : 'Add account'}
          </button>
        </div>
      </form>
    </div>
  )
}
