import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader, Spinner, Modal, FormField, StatusBadge, ConfirmModal } from '../../components/ui/index.jsx'
import { Edit2, Trash2 } from 'lucide-react'
import {
  adminSettingsKeys,
  employeeKeys,
  employeeLeaveBalanceKeys,
  leaveKeys,
  leaveTypeKeys,
  systemClockKeys,
  getAdminSettings,
  updateAdminSetting,
  getAdminLeaveTypes,
  createLeaveType,
  updateLeaveType,
  deleteLeaveType,
  getEmployees,
  getSystemClock,
  getEmployeeLeaveBalances,
  upsertEmployeeLeaveBalance,
} from '../../api/queries'

const EMPTY_TYPE_FORM = {
  name: '',
  description: '',
  default_days: 0,
  requires_balance: true,
  is_active: true,
}

export default function ConfigureLeavePage() {
  const qc = useQueryClient()
  const [selectedEmployeeId, setSelectedEmployeeId] = useState('')
  const [typeModalOpen, setTypeModalOpen] = useState(false)
  const [editingType, setEditingType] = useState(null)
  const [typeForm, setTypeForm] = useState(EMPTY_TYPE_FORM)
  const [typeError, setTypeError] = useState('')
  const [balanceModalOpen, setBalanceModalOpen] = useState(false)
  const [balanceError, setBalanceError] = useState('')
  const [confirmConfig, setConfirmConfig] = useState({ open: false, onConfirm: () => {}, message: '', title: '', type: 'info' })
  const [balanceForm, setBalanceForm] = useState({
    leaveTypeId: '',
    leaveTypeName: '',
    allocated_days: 0,
    notes: '',
    is_active: true,
  })

  const { data: settings = [], isLoading: settingsLoading } = useQuery({
    queryKey: adminSettingsKeys.all,
    queryFn: getAdminSettings,
  })

  const { data: leaveTypes = [], isLoading: typesLoading } = useQuery({
    queryKey: leaveTypeKeys.list({ include_inactive: 1 }),
    queryFn: () => getAdminLeaveTypes({ include_inactive: 1 }),
  })

  const { data: employeesData, isLoading: employeesLoading } = useQuery({
    queryKey: employeeKeys.list({ status: 'active' }),
    queryFn: () => getEmployees({ status: 'active' }),
  })

  const { data: systemClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchOnMount: 'always',
    refetchInterval: 30_000,
  })

  const { data: employeeBalanceData, isLoading: employeeBalanceLoading } = useQuery({
    queryKey: [...employeeLeaveBalanceKeys.detail(selectedEmployeeId), systemClock?.date],
    queryFn: () => getEmployeeLeaveBalances(selectedEmployeeId),
    enabled: Boolean(selectedEmployeeId),
  })

  const leaveIncludeWeekends = settings.find((setting) => setting.key === 'leave_include_weekends')
  const employees = employeesData?.data ?? []

  useEffect(() => {
    if (!selectedEmployeeId && employees.length > 0) {
      setSelectedEmployeeId(String(employees[0].id))
    }
  }, [employees, selectedEmployeeId])

  const selectedEmployee = employees.find((employee) => String(employee.id) === String(selectedEmployeeId))

  const refreshLeaveData = () => {
    qc.invalidateQueries({ queryKey: adminSettingsKeys.all })
    qc.invalidateQueries({ queryKey: leaveTypeKeys.all })
    qc.invalidateQueries({ queryKey: employeeLeaveBalanceKeys.all })
    qc.invalidateQueries({ queryKey: leaveKeys.all })
  }

  const updateMutation = useMutation({
    mutationFn: ({ value }) => updateAdminSetting(
      'leave_include_weekends',
      value,
      'Whether leave date ranges count Saturdays and Sundays',
      'boolean'
    ),
    onSuccess: refreshLeaveData,
    onSettled: () => qc.invalidateQueries({ queryKey: ['leaves', 'balance'] }),
  })

  const saveTypeMutation = useMutation({
    mutationFn: (payload) => (
      editingType ? updateLeaveType(editingType.id, payload) : createLeaveType(payload)
    ),
    onSuccess: () => {
      setTypeModalOpen(false)
      setEditingType(null)
      setTypeForm(EMPTY_TYPE_FORM)
      setTypeError('')
      refreshLeaveData()
    },
    onError: (error) => {
      setTypeError(error?.response?.data?.message || 'Unable to save leave type')
    },
  })

  const deleteTypeMutation = useMutation({
    mutationFn: (leaveTypeId) => deleteLeaveType(leaveTypeId),
    onSuccess: refreshLeaveData,
    onError: (error) => {
      setTypeError(error?.response?.data?.message || 'Unable to delete leave type')
    },
  })

  const saveBalanceMutation = useMutation({
    mutationFn: (payload) => upsertEmployeeLeaveBalance(selectedEmployeeId, payload.leaveTypeId, payload),
    onSuccess: () => {
      setBalanceModalOpen(false)
      setBalanceForm({
        leaveTypeId: '',
        leaveTypeName: '',
        allocated_days: 0,
        notes: '',
        is_active: true,
      })
      setBalanceError('')
      qc.invalidateQueries({ queryKey: employeeLeaveBalanceKeys.detail(selectedEmployeeId) })
      qc.invalidateQueries({ queryKey: leaveKeys.balance(selectedEmployeeId) })
      qc.invalidateQueries({ queryKey: leaveKeys.balance() })
    },
    onError: (error) => {
      setBalanceError(error?.response?.data?.message || 'Unable to save employee balance')
    },
  })

  const openTypeModal = (leaveType = null) => {
    if (leaveType) {
      setEditingType(leaveType)
      setTypeForm({
        name: leaveType.name,
        description: leaveType.description || '',
        default_days: leaveType.default_days,
        requires_balance: Boolean(leaveType.requires_balance),
        is_active: Boolean(leaveType.is_active),
      })
    } else {
      setEditingType(null)
      setTypeForm(EMPTY_TYPE_FORM)
    }

    setTypeError('')
    setTypeModalOpen(true)
  }

  const openBalanceModal = (balanceRow) => {
    setBalanceForm({
      leaveTypeId: balanceRow.leave_type.id,
      leaveTypeName: balanceRow.leave_type.name,
      allocated_days: balanceRow.override?.allocated_days ?? balanceRow.leave_type.default_days,
      notes: balanceRow.override?.notes ?? '',
      is_active: balanceRow.override ? Boolean(balanceRow.override.is_active) : true,
    })
    setBalanceError('')
    setBalanceModalOpen(true)
  }

  const handleTypeSubmit = (event) => {
    event.preventDefault()

    saveTypeMutation.mutate({
      name: typeForm.name,
      description: typeForm.description,
      default_days: Number(typeForm.default_days),
      requires_balance: Boolean(typeForm.requires_balance),
      is_active: Boolean(typeForm.is_active),
    })
  }

  const handleBalanceSubmit = (event) => {
    event.preventDefault()

    if (!selectedEmployeeId) {
      setBalanceError('Select an employee first.')
      return
    }

    saveBalanceMutation.mutate({
      leaveTypeId: balanceForm.leaveTypeId,
      allocated_days: Number(balanceForm.allocated_days),
      notes: balanceForm.notes,
      is_active: balanceForm.is_active,
    })
  }

  const handleDeleteType = (leaveType) => {
    setConfirmConfig({
      open: true,
      title: 'Delete Leave Type',
      message: `Are you sure you want to delete the leave type "${leaveType.name}"? This will affect all associated employee balances.`,
      onConfirm: () => deleteTypeMutation.mutate(leaveType.id),
      type: 'danger'
    })
  }

  const activeTypes = leaveTypes.filter((type) => type.is_active)

  return (
    <div>
      <PageHeader
        title="Configure Leave"
        description="Control leave policy, leave types, and employee-specific balances"
      />

      <ConfirmModal
        open={confirmConfig.open}
        onClose={() => setConfirmConfig({ ...confirmConfig, open: false })}
        onConfirm={confirmConfig.onConfirm}
        title={confirmConfig.title}
        message={confirmConfig.message}
        type={confirmConfig.type}
      />

      <div className="space-y-5">
        <div className="card p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-2">Leave Counting Policy</h2>
          <p className="text-sm text-gray-600 mb-4">
            Control whether Saturdays and Sundays count as leave days when employees file requests.
          </p>

          {settingsLoading ? (
            <div className="py-4 flex items-center gap-2 text-sm text-gray-500">
              <Spinner size="sm" /> Loading settings...
            </div>
          ) : (
            <div className="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-4">
              <div>
                <p className="text-sm font-medium text-gray-900">Count weekends in leave requests</p>
                <p className="text-xs text-gray-500 mt-1">
                  Current value: {leaveIncludeWeekends?.value === 'true' || leaveIncludeWeekends?.value === '1' ? 'Enabled' : 'Disabled'}
                </p>
              </div>

              <button
                type="button"
                onClick={() => updateMutation.mutate({ value: !(leaveIncludeWeekends?.value === 'true' || leaveIncludeWeekends?.value === '1') })}
                disabled={updateMutation.isPending}
                className={`inline-flex items-center rounded-full px-4 py-2 text-sm font-medium transition ${
                  leaveIncludeWeekends?.value === 'true' || leaveIncludeWeekends?.value === '1'
                    ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                } disabled:opacity-50`}
              >
                {updateMutation.isPending ? 'Saving...' : (leaveIncludeWeekends?.value === 'true' || leaveIncludeWeekends?.value === '1' ? 'On' : 'Off')}
              </button>
            </div>
          )}
        </div>

        <div className="card p-5">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <div>
              <h2 className="text-sm font-semibold text-gray-700">Leave Types</h2>
              <p className="text-sm text-gray-500">Add, edit, or delete leave types. Active status can be updated inside Edit.</p>
            </div>
            <button type="button" onClick={() => openTypeModal()} className="btn-primary">
              Add Leave Type
            </button>
          </div>

          {typesLoading ? (
            <div className="py-8 flex items-center justify-center text-sm text-gray-500 gap-2">
              <Spinner size="sm" /> Loading leave types...
            </div>
          ) : (
            <div className="overflow-hidden rounded-xl border border-gray-200">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    {['Name', 'Code', 'Default Days', 'Balance Rule', 'Status', 'Actions'].map((heading) => (
                      <th key={heading} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        {heading}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {leaveTypes.map((leaveType) => (
                    <tr key={leaveType.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{leaveType.name}</div>
                        {leaveType.description && <p className="text-xs text-gray-500 mt-0.5">{leaveType.description}</p>}
                      </td>
                      <td className="px-4 py-3 text-gray-500 text-xs font-mono">{leaveType.code}</td>
                      <td className="px-4 py-3 text-gray-700">{leaveType.default_days}</td>
                      <td className="px-4 py-3 text-gray-700">{leaveType.requires_balance ? 'Counts against balance' : 'Does not require balance'}</td>
                      <td className="px-4 py-3"><StatusBadge status={leaveType.is_active ? 'active' : 'inactive'} /></td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-2">
                          <button
                            type="button"
                            onClick={() => openTypeModal(leaveType)}
                            className="btn-ghost p-1.5 text-brand-600 hover:bg-brand-50"
                            title="Edit"
                          >
                            <Edit2 size={14} />
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDeleteType(leaveType)}
                            disabled={deleteTypeMutation.isPending}
                            className="btn-ghost p-1.5 text-red-600 hover:bg-red-50"
                            title="Delete"
                          >
                            <Trash2 size={14} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}

                  {leaveTypes.length === 0 && (
                    <tr>
                      <td colSpan={6} className="py-10 text-center text-sm text-gray-400">
                        No leave types configured yet.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
          {typeError && <p className="text-xs text-red-500 mt-3">{typeError}</p>}
        </div>

        <div className="card p-5">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <div>
              <h2 className="text-sm font-semibold text-gray-700">Employee Leave Balances</h2>
              <p className="text-sm text-gray-500">Set or cancel leave day allocations for a specific employee.</p>
            </div>
            <div className="w-full sm:w-72">
              <select
                value={selectedEmployeeId}
                onChange={(event) => setSelectedEmployeeId(event.target.value)}
                className="input"
              >
                <option value="">Select employee...</option>
                {employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.first_name} {employee.last_name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {employeesLoading || (selectedEmployeeId && employeeBalanceLoading) ? (
            <div className="py-8 flex items-center justify-center text-sm text-gray-500 gap-2">
              <Spinner size="sm" /> Loading employee balances...
            </div>
          ) : !selectedEmployeeId ? (
            <div className="rounded-lg border border-dashed border-gray-200 py-10 text-center text-sm text-gray-400">
              Choose an employee to manage their leave allocations.
            </div>
          ) : (
            <div className="space-y-4">
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 flex items-center justify-between gap-4">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    {selectedEmployee?.first_name} {selectedEmployee?.last_name}
                  </p>
                  <p className="text-xs text-gray-500 mt-1">
                    Employee ID: {selectedEmployee?.employee_id}
                    {employeeBalanceData?.cycle && (
                      <span className="ml-2">
                        Cycle: {employeeBalanceData.cycle.start} to {employeeBalanceData.cycle.end}
                      </span>
                    )}
                  </p>
                </div>
                <p className="text-xs text-gray-500">
                  {activeTypes.length} active leave type{activeTypes.length === 1 ? '' : 's'}
                </p>
              </div>

              {employeeBalanceData?.balances?.length ? (
                <div className="overflow-hidden rounded-xl border border-gray-200">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 border-b border-gray-200">
                      <tr>
                        {['Type', 'Default', 'Override', 'Used', 'Remaining', 'Status', 'Actions'].map((heading) => (
                          <th key={heading} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            {heading}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {employeeBalanceData.balances.map((balanceRow) => (
                        <tr key={balanceRow.leave_type.id} className="hover:bg-gray-50">
                          <td className="px-4 py-3">
                            <div className="font-medium text-gray-900">{balanceRow.leave_type.name}</div>
                            <p className="text-xs text-gray-500 mt-0.5">{balanceRow.leave_type.code}</p>
                          </td>
                          <td className="px-4 py-3 text-gray-700">{balanceRow.leave_type.default_days}</td>
                          <td className="px-4 py-3 text-gray-700">
                            {balanceRow.override?.is_active ? balanceRow.override.allocated_days : 'Uses default'}
                          </td>
                          <td className="px-4 py-3 text-gray-700">{balanceRow.used}</td>
                          <td className="px-4 py-3 text-gray-700">{balanceRow.remaining}</td>
                          <td className="px-4 py-3">
                            <StatusBadge status={(balanceRow.override && !balanceRow.override.is_active) ? 'inactive' : 'active'} />
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex flex-wrap gap-2">
                              <button
                                type="button"
                                onClick={() => openBalanceModal(balanceRow)}
                                className="btn-ghost p-1.5 text-brand-600 hover:bg-brand-50"
                                title="Edit"
                              >
                                <Edit2 size={14} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="rounded-lg border border-dashed border-gray-200 py-10 text-center text-sm text-gray-400">
                  No active leave types are available to assign.
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <Modal
        open={typeModalOpen}
        onClose={() => setTypeModalOpen(false)}
        title={editingType ? 'Edit Leave Type' : 'Add Leave Type'}
      >
        <form onSubmit={handleTypeSubmit} className="space-y-4">
          <FormField label="Leave type name" required>
            <input
              value={typeForm.name}
              onChange={(event) => setTypeForm((current) => ({ ...current, name: event.target.value }))}
              className="input"
              placeholder="Vacation Leave"
            />
          </FormField>

          <FormField label="Description">
            <textarea
              value={typeForm.description}
              onChange={(event) => setTypeForm((current) => ({ ...current, description: event.target.value }))}
              className="input h-20 resize-none"
              placeholder="Short description for admin reference"
            />
          </FormField>

          <div className="grid grid-cols-2 gap-3">
            <FormField label="Default days" required>
              <input
                type="number"
                min="0"
                value={typeForm.default_days}
                onChange={(event) => setTypeForm((current) => ({ ...current, default_days: event.target.value }))}
                className="input"
              />
            </FormField>
            <div className="space-y-3 rounded-lg border border-gray-200 p-4">
              <label className="flex items-center justify-between gap-3 text-sm text-gray-700">
                <span>Requires balance</span>
                <input
                  type="checkbox"
                  checked={typeForm.requires_balance}
                  onChange={(event) => setTypeForm((current) => ({ ...current, requires_balance: event.target.checked }))}
                  className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                />
              </label>
              <label className="flex items-center justify-between gap-3 text-sm text-gray-700">
                <span>Active</span>
                <input
                  type="checkbox"
                  checked={typeForm.is_active}
                  onChange={(event) => setTypeForm((current) => ({ ...current, is_active: event.target.checked }))}
                  className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                />
              </label>
            </div>
          </div>

          {typeError && <p className="text-xs text-red-500">{typeError}</p>}

          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={() => setTypeModalOpen(false)} className="btn-secondary">Cancel</button>
            <button type="submit" disabled={saveTypeMutation.isPending} className="btn-primary">
              {saveTypeMutation.isPending ? 'Saving...' : 'Save leave type'}
            </button>
          </div>
        </form>
      </Modal>

      <Modal
        open={balanceModalOpen}
        onClose={() => setBalanceModalOpen(false)}
        title={`Edit ${balanceForm.leaveTypeName || 'Leave Type'} Override`}
        size="sm"
      >
        <form onSubmit={handleBalanceSubmit} className="space-y-4">
          <FormField label="Allocated days" required>
            <input
              type="number"
              min="0"
              value={balanceForm.allocated_days}
              onChange={(event) => setBalanceForm((current) => ({ ...current, allocated_days: event.target.value }))}
              className="input"
            />
          </FormField>

          <FormField label="Notes">
            <textarea
              value={balanceForm.notes}
              onChange={(event) => setBalanceForm((current) => ({ ...current, notes: event.target.value }))}
              className="input h-20 resize-none"
              placeholder="Optional admin note"
            />
          </FormField>

          <label className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-3 text-sm text-gray-700">
            <div>
              <span className="font-medium">Active override</span>
              <p className="text-xs text-gray-500 mt-1">
                On: use employee-specific allocated days. Off: use leave type default days.
              </p>
            </div>
            <input
              type="checkbox"
              checked={balanceForm.is_active}
              onChange={(event) => setBalanceForm((current) => ({ ...current, is_active: event.target.checked }))}
              className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
            />
          </label>

          {balanceError && <p className="text-xs text-red-500">{balanceError}</p>}

          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={() => setBalanceModalOpen(false)} className="btn-secondary">Cancel</button>
            <button type="submit" disabled={saveBalanceMutation.isPending} className="btn-primary">
              {saveBalanceMutation.isPending ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
