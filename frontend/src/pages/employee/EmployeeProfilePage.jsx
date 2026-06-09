import { useState } from 'react'
import { useAuth } from '../../store/AuthContext'
import { PageHeader, StatusBadge, Spinner, AlertModal } from '../../components/ui/index.jsx'
import { Mail, Phone, Calendar, Briefcase, CreditCard, Pencil, Check, X, Key } from 'lucide-react'
import { format } from 'date-fns'
import { useMutation } from '@tanstack/react-query'
import { updateProfile, updatePassword } from '../../api/queries'
import { useForm } from 'react-hook-form'

export default function EmployeeProfilePage() {
  const { user, refreshUser } = useAuth()
  const emp = user?.employee
  const [isEditingBank, setIsEditingBank] = useState(false)
  const [isEditingPassword, setIsEditingPassword] = useState(false)
  const [alert, setAlert] = useState(null)

  const { register: registerProfile, handleSubmit: handleSubmitProfile, reset: resetProfile, formState: { errors: errorsProfile } } = useForm({
    defaultValues: {
      bank_account_number: emp?.bank_account_number || '',
      sss_number: emp?.sss_number || '',
      philhealth_number: emp?.philhealth_number || '',
      pagibig_number: emp?.pagibig_number || '',
    }
  })

  const { register: registerPassword, handleSubmit: handleSubmitPassword, reset: resetPassword, formState: { errors: errorsPassword } } = useForm()

  const mutationProfile = useMutation({
    mutationFn: updateProfile,
    onSuccess: () => {
      refreshUser()
      setIsEditingBank(false)
      setAlert({ type: 'success', message: 'Profile updated successfully' })
    },
    onError: (err) => {
      setAlert({ type: 'error', message: err?.response?.data?.message || 'Failed to update profile' })
    }
  })

  const mutationPassword = useMutation({
    mutationFn: updatePassword,
    onSuccess: () => {
      resetPassword()
      setIsEditingPassword(false)
      setAlert({ type: 'success', message: 'Password updated successfully' })
    },
    onError: (err) => {
      setAlert({ type: 'error', message: err?.response?.data?.message || 'Failed to update password' })
    }
  })

  const onSubmitProfile = (data) => {
    mutationProfile.mutate(data)
  }

  const onSubmitPassword = (data) => {
    if (data.password !== data.password_confirmation) {
      setAlert({ type: 'error', message: 'New password and confirmation do not match.' })
      return
    }
    mutationPassword.mutate(data)
  }

  const cancelEdit = () => {
    resetProfile({ 
      bank_account_number: emp?.bank_account_number || '',
      sss_number: emp?.sss_number || '',
      philhealth_number: emp?.philhealth_number || '',
      pagibig_number: emp?.pagibig_number || '',
    })
    setIsEditingBank(false)
    setIsEditingPassword(false)
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
              <form onSubmit={handleSubmitProfile(onSubmitProfile)} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Bank Account Number</label>
                    <input
                      type="text"
                      className={`input text-sm w-full ${errorsProfile.bank_account_number ? 'border-red-500' : ''}`}
                      placeholder="0000000000"
                      {...registerProfile('bank_account_number', {
                        required: 'Account number is required',
                        minLength: { value: 10, message: 'Must be between 10 and 12 digits' },
                        maxLength: { value: 12, message: 'Must be between 10 and 12 digits' },
                        pattern: { value: /^\d+$/, message: 'Must be numbers only' }
                      })}
                    />
                    {errorsProfile.bank_account_number && (
                      <p className="text-[10px] text-red-500 mt-1">{errorsProfile.bank_account_number.message}</p>
                    )}
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">SSS Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="00-0000000-0"
                      {...registerProfile('sss_number')}
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">PhilHealth Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="00-000000000-0"
                      {...registerProfile('philhealth_number')}
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Pag-IBIG Number</label>
                    <input
                      type="text"
                      className="input text-sm w-full"
                      placeholder="0000-0000-0000"
                      {...registerProfile('pagibig_number')}
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
                    disabled={mutationProfile.isPending}
                    className="btn-primary px-6 py-2 text-sm min-w-[100px]"
                  >
                    {mutationProfile.isPending ? <Spinner size="sm" /> : 'Save Changes'}
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

          {/* Change Password */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                <Key size={14} className="text-brand-600" /> Security
              </h2>
              {!isEditingPassword && (
                <button
                  onClick={() => setIsEditingPassword(true)}
                  className="text-xs font-medium text-brand-600 hover:text-brand-700 flex items-center gap-1"
                >
                  <Pencil size={12} /> Change Password
                </button>
              )}
            </div>

            {isEditingPassword ? (
              <form onSubmit={handleSubmitPassword(onSubmitPassword)} className="space-y-4">
                <div className="grid grid-cols-1 gap-3">
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Current Password</label>
                    <input
                      type="password"
                      className={`input text-sm w-full ${errorsPassword.current_password ? 'border-red-500' : ''}`}
                      {...registerPassword('current_password', { required: 'Required' })}
                    />
                    {errorsPassword.current_password && <p className="text-[10px] text-red-500 mt-1">{errorsPassword.current_password.message}</p>}
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">New Password</label>
                    <input
                      type="password"
                      className={`input text-sm w-full ${errorsPassword.password ? 'border-red-500' : ''}`}
                      {...registerPassword('password', { required: 'Required', minLength: { value: 8, message: 'Must be at least 8 characters' } })}
                    />
                    {errorsPassword.password && <p className="text-[10px] text-red-500 mt-1">{errorsPassword.password.message}</p>}
                  </div>
                  <div>
                    <label className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Confirm New Password</label>
                    <input
                      type="password"
                      className={`input text-sm w-full ${errorsPassword.password_confirmation ? 'border-red-500' : ''}`}
                      {...registerPassword('password_confirmation', { required: 'Required' })}
                    />
                    {errorsPassword.password_confirmation && <p className="text-[10px] text-red-500 mt-1">{errorsPassword.password_confirmation.message}</p>}
                  </div>
                </div>
                <div className="flex gap-2 justify-end">
                  <button type="button" onClick={cancelEdit} className="btn-secondary px-4 py-2 text-sm">Cancel</button>
                  <button type="submit" disabled={mutationPassword.isPending} className="btn-primary px-6 py-2 text-sm">
                    {mutationPassword.isPending ? <Spinner size="sm" /> : 'Update Password'}
                  </button>
                </div>
              </form>
            ) : null}
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
