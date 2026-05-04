import { useState } from 'react'
import { useAuth } from '../../store/AuthContext'
import { PageHeader, StatusBadge } from '../../components/ui/index.jsx'
import { Mail, Phone, Calendar, Briefcase, CreditCard, Pencil, Check, X } from 'lucide-react'
import { format } from 'date-fns'
import { useMutation } from '@tanstack/react-query'
import { updateProfile } from '../../api/queries'
import { useForm } from 'react-hook-form'

export default function EmployeeProfilePage() {
  const { user, refreshUser } = useAuth()
  const emp = user?.employee
  const [isEditingBank, setIsEditingBank] = useState(false)

  const { register, handleSubmit, reset, formState: { errors } } = useForm({
    defaultValues: {
      bank_account_number: emp?.bank_account_number || ''
    }
  })

  const mutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: () => {
      refreshUser()
      setIsEditingBank(false)
    },
    onError: (err) => {
      alert(err?.response?.data?.message || 'Failed to update profile')
    }
  })

  const onSubmit = (data) => {
    mutation.mutate(data)
  }

  const cancelEdit = () => {
    reset({ bank_account_number: emp?.bank_account_number || '' })
    setIsEditingBank(false)
  }

  if (!emp) {
    return (
      <div>
        <PageHeader title="My Profile" />
        <div className="card p-6 text-center text-gray-500">
          No employee profile information found.
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-3xl">
      <PageHeader
        title="My Profile"
        description="View your personal and employment details"
      />

      <div className="grid lg:grid-cols-3 gap-5">
        {/* Profile card */}
        <div className="card p-5 flex flex-col items-center text-center lg:col-span-1">
          <div className="w-16 h-16 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-xl font-semibold mb-3">
            {emp.first_name?.charAt(0)}{emp.last_name?.charAt(0)}
          </div>
          <p className="font-semibold text-gray-900">{emp.first_name} {emp.last_name}</p>
          <p className="text-sm text-gray-500 mb-2">{emp.position}</p>
          <StatusBadge status={emp.status} />
          <p className="text-xs text-gray-400 font-mono mt-2">ID: {emp.employee_id}</p>
        </div>

        <div className="lg:col-span-2 space-y-5">
          {/* Details */}
          <div className="card p-5 space-y-4">
            <h2 className="text-sm font-semibold text-gray-700">Employment Details</h2>
            <div className="grid grid-cols-2 gap-4 text-sm">
              {[
                { icon: Mail, label: 'Email Address', value: emp.email },
                { icon: Phone, label: 'Phone Number', value: emp.phone || '—' },
                { icon: Briefcase, label: 'Department', value: emp.department },
                { icon: Calendar, label: 'Hire Date', value: emp.hire_date ? format(new Date(emp.hire_date), 'MMM dd, yyyy') : '—' },
                { icon: Briefcase, label: 'Salary', value: `₱${Number(emp.salary).toLocaleString()}` },
              ].map(({ icon: Icon, label, value }) => (
                <div key={label} className="flex items-start gap-2">
                  <Icon size={14} className="text-gray-400 mt-0.5 shrink-0" />
                  <div>
                    <p className="text-xs text-gray-400">{label}</p>
                    <p className="font-medium text-gray-800">{value}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Payroll / Bank Details */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                <CreditCard size={14} className="text-brand-600" /> Payroll Details
              </h2>
              {!isEditingBank && (
                <button
                  onClick={() => setIsEditingBank(true)}
                  className="text-xs font-medium text-brand-600 hover:text-brand-700 flex items-center gap-1"
                >
                  <Pencil size={12} /> Edit
                </button>
              )}
            </div>

            {isEditingBank ? (
              <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
                <div>
                  <label className="text-xs text-gray-400 mb-1 block">Bank Account Number (10-12 digits)</label>
                  <div className="flex gap-2">
                    <div className="grow">
                      <input
                        type="text"
                        className={`input text-sm w-full ${errors.bank_account_number ? 'border-red-500' : ''}`}
                        placeholder="0000000000"
                        {...register('bank_account_number', {
                          required: 'Account number is required',
                          minLength: { value: 10, message: 'Must be between 10 and 12 digits' },
                          maxLength: { value: 12, message: 'Must be between 10 and 12 digits' },
                          pattern: { value: /^\d+$/, message: 'Must be numbers only' }
                        })}
                      />
                      {errors.bank_account_number && (
                        <p className="text-[10px] text-red-500 mt-1">{errors.bank_account_number.message}</p>
                      )}
                    </div>
                    <div className="flex gap-1 shrink-0">
                      <button
                        type="submit"
                        disabled={mutation.isPending}
                        className="btn-primary p-2 h-10 w-10 flex items-center justify-center"
                        title="Save"
                      >
                        {mutation.isPending ? (
                          <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                        ) : (
                          <Check size={16} />
                        )}
                      </button>
                      <button
                        type="button"
                        onClick={cancelEdit}
                        className="btn-secondary p-2 h-10 w-10 flex items-center justify-center"
                        title="Cancel"
                      >
                        <X size={16} />
                      </button>
                    </div>
                  </div>
                </div>
              </form>
            ) : (
              <div className="flex items-start gap-2 text-sm">
                <div className="bg-gray-50 p-2 rounded-lg border border-gray-100 flex-1">
                  <p className="text-xs text-gray-400">Bank Account Number</p>
                  <p className="font-mono font-medium text-gray-800 tracking-wider">
                    {emp.bank_account_number || 'Not set'}
                  </p>
                </div>
              </div>
            )}
            <p className="text-[10px] text-gray-400 mt-3">
              This account number will be used for your payroll disbursements. Please ensure it is correct.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
