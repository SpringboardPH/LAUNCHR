import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getEmployee, createEmployee, updateEmployee, employeeKeys, getAdminDepartments, adminDepartmentKeys } from '../../api/queries'
import { PageHeader, FormField, PageSpinner, Spinner } from '../../components/ui/index.jsx'
import { ArrowLeft } from 'lucide-react'

const schema = z.object({
  first_name:   z.string().min(1, 'Required'),
  last_name:    z.string().min(1, 'Required'),
  email:        z.string().email('Invalid email'),
  phone:        z.string().optional(),
  position:     z.string().min(1, 'Required'),
  department:   z.string().min(1, 'Required'),
  hire_date:    z.string().min(1, 'Required'),
  salary:       z.coerce.number().min(0, 'Must be ≥ 0'),
})

export default function EmployeeFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: emp, isLoading } = useQuery({
    queryKey: employeeKeys.detail(id),
    queryFn: () => getEmployee(id),
    enabled: isEdit,
  })

  const { data: departments = [] } = useQuery({
    queryKey: adminDepartmentKeys.all,
    queryFn: getAdminDepartments,
  })

  // Filter only active departments
  const activeDepts = departments.filter(d => !d.deleted_at)

  const { register, handleSubmit, reset, formState: { errors } } = useForm({
    resolver: zodResolver(schema),
    defaultValues: { salary: 0 },
  })

  useEffect(() => {
    if (emp) reset({
      ...emp,
      hire_date: emp.hire_date?.split('T')[0] ?? emp.hire_date,
    })
  }, [emp, reset])

  const mutation = useMutation({
    mutationFn: (data) => isEdit ? updateEmployee(id, data) : createEmployee(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeKeys.all })
      navigate('/admin/employees')
    },
  })

  if (isEdit && isLoading) return <PageSpinner />

  return (
    <div className="max-w-2xl">
      <PageHeader
        title={isEdit ? 'Edit Employee' : 'Add Employee'}
        description={isEdit ? `Editing ${emp?.first_name} ${emp?.last_name}` : 'Fill in the details below'}
        action={
          <button onClick={() => navigate('/admin/employees')} className="btn-secondary">
            <ArrowLeft size={14} /> Back
          </button>
        }
      />

      <form onSubmit={handleSubmit(data => mutation.mutate(data))} className="card p-6 space-y-5">
        <div className="grid grid-cols-2 gap-4">
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

        <div className="grid grid-cols-2 gap-4">
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

        <div className="grid grid-cols-2 gap-4">
          <FormField label="Hire date" error={errors.hire_date?.message} required>
            <input type="date" {...register('hire_date')} className={`input ${errors.hire_date ? 'input-error' : ''}`} />
          </FormField>
          <FormField label="Salary (₱/month)" error={errors.salary?.message} required>
            <input type="number" step="0.01" {...register('salary')} className={`input ${errors.salary ? 'input-error' : ''}`} />
          </FormField>
        </div>

        {mutation.isError && (
          <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
            {mutation.error?.response?.data?.message ?? 'Something went wrong.'}
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={() => navigate('/admin/employees')} className="btn-secondary">
            Cancel
          </button>
          <button type="submit" disabled={mutation.isPending} className="btn-primary">
            {mutation.isPending ? <Spinner size="sm" /> : isEdit ? 'Save changes' : 'Add employee'}
          </button>
        </div>
      </form>
    </div>
  )
}
