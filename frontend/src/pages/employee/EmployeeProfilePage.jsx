import { useState } from 'react'
import { useAuth } from '../../store/AuthContext'
import { PageHeader, StatusBadge, Spinner, AlertModal } from '../../components/ui/index.jsx'
import { Mail, Phone, Calendar, Briefcase, CreditCard, Pencil, Check, X } from 'lucide-react'
import { format } from 'date-fns'
import { useMutation } from '@tanstack/react-query'
import { updateProfile } from '../../api/queries'
import { useForm } from 'react-hook-form'

export default function EmployeeProfilePage() {
  const { user, refreshUser } = useAuth()
  const emp = user?.employee
  const [isEditingBank, setIsEditingBank] = useState(false)
  const [alert, setAlert] = useState(null)

  const { register, handleSubmit, reset, formState: { errors } } = useForm({
    defaultValues: {
      bank_account_number: emp?.bank_account_number || '',
      sss_number: emp?.sss_number || '',
      philhealth_number: emp?.philhealth_number || '',
      pagibig_number: emp?.pagibig_number || '',
    }
  })

  const mutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: () => {
      refreshUser()
      setIsEditingBank(false)
    },
    onError: (err) => {
      setAlert({ type: 'error', message: err?.response?.data?.message || 'Failed to update profile' })
    }
  })

  const onSubmit = (data) => {
    mutation.mutate(data)
  }

  const cancelEdit = () => {
    reset({ 
      bank_account_number: emp?.bank_account_number || '',
      sss_number: emp?.sss_number || '',
      philhealth_number: emp?.philhealth_number || '',
      pagibig_number: emp?.pagibig_number || '',
    })
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
        <div className="card p-5 flex flex-col items-center justify-center text-center lg:col-span-1">
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
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
              {[
                { icon: Mail, label: 'Email Address', value: emp.email },
                { icon: Phone, label: 'Phone Number', value: emp.phone || '—' },
                { icon: Briefcase, label: 'Department', value: emp.department },
                { icon: Calendar, label: 'Hire Date', value: emp.hire_date ? format(new Date(emp.hire_date), 'MMM dd, yyyy') : '—' },
                { icon: Briefcase, label: 'Salary', value: `₱${Number(emp.salary).toLocaleString()}` },
              ].map(({ icon: Icon, label, value }) => (
                <div key={label} className="flex items-start gap-2 min-w-0">
                  <Icon size={14} className="text-gray-400 mt-0.5 shrink-0" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs text-gray-400">{label}</p>
                    <p className="font-medium text-gray-800 break-words">{value}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Payroll / Bank Details */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                <CreditCard size={14} className="text-brand-600" /> Payroll & Gov't IDs
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
              <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Bank Account Number</label>
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
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">SSS Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="00-0000000-0"
                      {...register('sss_number')}
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">PhilHealth Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="00-000000000-0"
                      {...register('philhealth_number')}
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Pag-IBIG Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="0000-0000-0000"
                      {...register('pagibig_number')}
                    />
                  </div>
                </div>

                <div className="flex gap-2 justify-end">
                  <button
                    type="button"
                    onClick={cancelEdit}
                    className="btn-secondary px-4 py-2 text-sm"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={mutation.isPending}
                    className="btn-primary px-6 py-2 text-sm min-w-[100px]"
                  >
                    {mutation.isPending ? <Spinner size="sm" /> : 'Save Changes'}
                  </button>
                </div>
              </form>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {[
                  { label: 'Bank Account', value: emp.bank_account_number },
                  { label: 'SSS Number', value: emp.sss_number },
                  { label: 'PhilHealth', value: emp.philhealth_number },
                  { label: 'Pag-IBIG', value: emp.pagibig_number },
                ].map(({ label, value }) => (
                  <div key={label} className="bg-gray-50/50 p-3 rounded-lg border border-gray-100">
                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">{label}</p>
                    <p className="font-mono font-bold text-gray-800 tracking-wider mt-1 truncate">
                      {value || 'Not set'}
                    </p>
                  </div>
                ))}
              </div>
            )}
            <p className="text-[10px] text-gray-400 mt-4 italic">
              These details are used for payroll and government compliance. Please ensure accuracy.
            </p>
          </div>
        </div>
      </div>

      <AlertModal
        open={!!alert}
        onClose={() => setAlert(null)}
        title={
          alert?.type === 'success' ? 'Success' :
          alert?.type === 'error' ? 'Error' :
          alert?.type === 'warning' ? 'Warning' : 'Information'
        }
        message={alert?.message}
        type={alert?.type}
      />
    </div>
  )
}
