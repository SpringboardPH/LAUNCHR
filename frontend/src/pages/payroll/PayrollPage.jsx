import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { 
  getPayrolls, generatePayroll, updatePayroll, payrollKeys, 
  getSystemClock, systemClockKeys 
} from '../../api/queries'
import { PageHeader, PageSpinner, StatusBadge, Modal, Spinner } from '../../components/ui/index.jsx'
import { Plus, Banknote, Calendar, ChevronLeft, ChevronRight, FileDown, CheckCircle } from 'lucide-react'
import { getCutoffPeriod, getNextCutoff, getPrevCutoff } from '../../utils/attendance'

export default function PayrollPage() {
  const [activeCutoff, setActiveCutoff] = useState(null)
  const [selectedPayroll, setSelectedPayroll] = useState(null)
  const [isEditing, setIsEditing] = useState(false)
  const [showBreakdown, setShowBreakdown] = useState(false)
  const [editForm, setEditForm] = useState(null)
  const qc = useQueryClient()

  const { data: sysClock } = useQuery({
    queryKey: systemClockKeys.all,
    queryFn: getSystemClock,
  })

  useEffect(() => {
    if (sysClock && activeCutoff === null) {
      setActiveCutoff(getCutoffPeriod(sysClock.date))
    }
  }, [sysClock, activeCutoff])

  const currentCutoff = activeCutoff || getCutoffPeriod(sysClock?.date || new Date())

  const { data: payrolls = [], isLoading } = useQuery({
    queryKey: payrollKeys.list({ 
      cutoff_start: currentCutoff.startDate, 
      cutoff_end: currentCutoff.endDate 
    }),
    queryFn: () => getPayrolls({ 
      cutoff_start: currentCutoff.startDate, 
      cutoff_end: currentCutoff.endDate 
    }),
    enabled: !!currentCutoff,
  })

  const generateMutation = useMutation({
    mutationFn: generatePayroll,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
    },
  })

  const updateStatusMutation = useMutation({
    mutationFn: ({ id, ...data }) => updatePayroll(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollKeys.all })
      setSelectedPayroll(null)
      setIsEditing(false)
    }
  })

  const handleEditInit = () => {
    setEditForm({
      ...selectedPayroll,
      allowances: Array.isArray(selectedPayroll.allowances) 
        ? selectedPayroll.allowances 
        : Object.entries(selectedPayroll.allowances || {}).map(([k, v]) => ({ label: k, amount: v })),
      deductions: Array.isArray(selectedPayroll.deductions)
        ? selectedPayroll.deductions
        : Object.entries(selectedPayroll.deductions || {}).map(([k, v]) => ({ label: k, amount: v })),
    })
    setIsEditing(true)
  }

  const handleAddField = (type, label = '') => {
    setEditForm(prev => ({
      ...prev,
      [type]: [...prev[type], { label, amount: 0 }]
    }))
  }

  const handleRemoveField = (type, index) => {
    setEditForm(prev => ({
      ...prev,
      [type]: prev[type].filter((_, i) => i !== index)
    }))
  }

  const handleFieldChange = (type, index, field, value) => {
    setEditForm(prev => {
      const next = { ...prev }
      if (type && index !== undefined) {
        next[type][index][field] = value
      } else if (field) {
        next[field] = value
      }
      
      const dRate = Number(next.daily_rate || 0)
      const hRate = dRate / 8

      // Auto-sync line items if metrics changed
      if (field === 'overtime_hours') {
        const item = next.allowances.find(a => a.label === 'Overtime Pay')
        if (item) item.amount = Math.round(Number(value) * hRate * 1.25 * 100) / 100
      }
      if (field === 'late_minutes') {
        const item = next.deductions.find(d => d.label === 'Late')
        if (item) item.amount = Math.round((Number(value) / 60) * hRate * 100) / 100
      }
      if (field === 'undertime_minutes') {
        const item = next.deductions.find(d => d.label === 'Undertime')
        if (item) item.amount = Math.round((Number(value) / 60) * hRate * 100) / 100
      }

      // Recalculate totals
      const totalAllowances = next.allowances.reduce((s, a) => s + Number(a.amount || 0), 0)
      const totalDeductions = next.deductions.reduce((s, d) => s + Number(d.amount || 0), 0)
      
      const isDaily = next.employee?.rate_type === 'daily'
      const baseGross = isDaily 
        ? (Number(next.base_salary) * Number(next.days_worked || 0))
        : (Number(next.base_salary) / 2)
      
      next.gross_pay = baseGross + totalAllowances
      next.net_pay = next.gross_pay - totalDeductions
      
      return next
    })
  }

  const handleSaveEdit = () => {
    updateStatusMutation.mutate({ 
      id: editForm.id, 
      status: editForm.status,
      base_salary: editForm.base_salary,
      days_worked: editForm.days_worked,
      gross_pay: editForm.gross_pay,
      net_pay: editForm.net_pay,
      allowances: editForm.allowances,
      deductions: editForm.deductions,
      total_hours: editForm.total_hours,
      overtime_hours: editForm.overtime_hours,
      late_minutes: editForm.late_minutes,
      undertime_minutes: editForm.undertime_minutes,
    })
  }

  const moveCutoff = (delta) => {
    if (delta > 0) setActiveCutoff(getNextCutoff(currentCutoff))
    else setActiveCutoff(getPrevCutoff(currentCutoff))
  }

  const totals = useMemo(() => {
    return payrolls.reduce((acc, p) => ({
      gross: acc.gross + Number(p.gross_pay),
      net: acc.net + Number(p.net_pay),
      count: acc.count + 1
    }), { gross: 0, net: 0, count: 0 })
  }, [payrolls])

  return (
    <div className="space-y-5">
      <PageHeader
        title="Payroll Processing"
        description="Calculate salaries and manage disbursements"
        action={
          <div className="flex items-center gap-2">
            <button 
              onClick={() => moveCutoff(-1)}
              className="btn-secondary p-2"
            >
              <ChevronLeft size={16} />
            </button>
            <div className="flex items-center gap-2 bg-white border border-gray-200 px-4 py-2 rounded-lg shadow-sm">
              <Calendar size={14} className="text-brand-600" />
              <span className="text-sm font-semibold text-gray-700 min-w-[160px] text-center">
                {currentCutoff.label}
              </span>
            </div>
            <button 
              onClick={() => moveCutoff(1)}
              className="btn-secondary p-2"
            >
              <ChevronRight size={16} />
            </button>
          </div>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="card p-4 flex flex-col justify-between">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Employees</p>
          <p className="text-2xl font-bold text-gray-900 mt-1">{totals.count}</p>
        </div>
        <div className="card p-4 flex flex-col justify-between border-l-4 border-brand-500">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Gross Pay</p>
          <p className="text-2xl font-bold text-brand-600 mt-1">₱{totals.gross.toLocaleString()}</p>
        </div>
        <div className="card p-4 flex flex-col justify-between border-l-4 border-green-500">
          <p className="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Net Pay</p>
          <p className="text-2xl font-bold text-green-600 mt-1">₱{totals.net.toLocaleString()}</p>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
          <h3 className="text-sm font-semibold text-gray-700">Cutoff Details</h3>
          <button 
            disabled={generateMutation.isPending}
            onClick={() => generateMutation.mutate({ 
              cutoff_start: currentCutoff.startDate, 
              cutoff_end: currentCutoff.endDate 
            })}
            className="btn-primary py-2 text-xs"
          >
            {generateMutation.isPending ? <Spinner size="sm" /> : <><Plus size={14} /> Generate for Period</>}
          </button>
        </div>

        {isLoading ? (
          <PageSpinner />
        ) : payrolls.length === 0 ? (
          <div className="py-20 flex flex-col items-center justify-center text-center">
            <Banknote size={48} className="text-gray-100 mb-4" />
            <p className="text-sm font-medium text-gray-500">No payroll records for this cutoff</p>
            <p className="text-xs text-gray-400 mt-1">Click "Generate" above to calculate employee salaries</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50/50 text-left">
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Employee</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Base Salary</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider text-center">Hours</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider text-center">OT/Late</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Gross Pay</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Net Pay</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-5 py-3 text-xs font-bold text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {payrolls.map(p => (
                  <tr key={p.id} className="hover:bg-gray-50 group">
                    <td className="px-5 py-3">
                      <p className="font-semibold text-gray-900">{p.employee?.first_name} {p.employee?.last_name}</p>
                      <p className="text-[10px] text-gray-400 uppercase font-bold">{p.employee?.position}</p>
                    </td>
                    <td className="px-5 py-3 text-gray-600">₱{Number(p.base_salary).toLocaleString()}</td>
                    <td className="px-5 py-3 text-center text-gray-600 font-mono">
                      <div className="flex flex-col">
                        <span>{Number(p.total_hours).toFixed(1)}h</span>
                        <span className="text-[10px] text-gray-400 font-bold">{p.days_worked} Days</span>
                      </div>
                    </td>
                    <td className="px-5 py-3 text-center">
                      <div className="flex flex-col gap-0.5">
                        <span className="text-[10px] text-blue-600 font-bold">OT: {Number(p.overtime_hours).toFixed(1)}h</span>
                        <span className="text-[10px] text-red-500 font-bold">Late: {p.late_minutes}m</span>
                      </div>
                    </td>
                    <td className="px-5 py-3 font-semibold text-gray-900">₱{Number(p.gross_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td className="px-5 py-3 font-bold text-brand-600">₱{Number(p.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button 
                          onClick={() => setSelectedPayroll(p)}
                          className="p-1.5 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-md transition-colors"
                          title="View Details"
                        >
                          <FileDown size={16} />
                        </button>
                        {p.status === 'draft' && (
                          <button 
                            onClick={() => updateStatusMutation.mutate({ id: p.id, status: 'finalized' })}
                            className="p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded-md transition-colors"
                            title="Finalize"
                          >
                            <CheckCircle size={16} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={!!selectedPayroll}
        onClose={() => { setSelectedPayroll(null); setIsEditing(false) }}
        title={isEditing ? "Edit Payroll" : "Payroll Details"}
        size="lg"
        footer={selectedPayroll && (
          <div className="flex flex-col w-full gap-4">
            <div className="flex justify-between items-center bg-brand-50 p-4 rounded-xl border border-brand-100">
              <div>
                <p className="text-[10px] text-brand-500 uppercase font-bold tracking-widest">Net Disbursement</p>
                <p className="text-2xl font-black text-brand-700">
                  ₱{(isEditing ? editForm.net_pay : selectedPayroll.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </p>
              </div>
              <div className="text-right">
                <p className="text-[10px] text-gray-400 uppercase font-bold">Gross Pay</p>
                <p className="text-sm font-bold text-gray-600">
                  ₱{(isEditing ? editForm.gross_pay : selectedPayroll.gross_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </p>
              </div>
            </div>
            
            <div className="flex gap-2">
              {isEditing ? (
                <>
                  <button 
                    disabled={updateStatusMutation.isPending}
                    onClick={handleSaveEdit}
                    className="btn-primary flex-1 py-3"
                  >
                    {updateStatusMutation.isPending ? <Spinner size="sm" /> : 'Save Changes'}
                  </button>
                  <button onClick={() => setIsEditing(false)} className="btn-secondary px-6">Cancel</button>
                </>
              ) : (
                <>
                  {selectedPayroll.status === 'draft' && (
                    <button 
                      onClick={() => updateStatusMutation.mutate({ id: selectedPayroll.id, status: 'finalized' })}
                      className="btn-primary flex-1 py-3"
                    >
                      Finalize Payroll
                    </button>
                  )}
                  {selectedPayroll.status === 'finalized' && (
                    <button 
                      onClick={() => updateStatusMutation.mutate({ id: selectedPayroll.id, status: 'paid' })}
                      className="btn-primary flex-1 py-3 bg-green-600 hover:bg-green-700 border-green-600"
                    >
                      Mark as Paid
                    </button>
                  )}
                  <button onClick={() => setSelectedPayroll(null)} className="btn-secondary px-6">Close</button>
                </>
              )}
            </div>
          </div>
        )}
      >
        {selectedPayroll && (
          <div className="space-y-6">
            <div className="flex justify-between items-start">
              <div>
                <h4 className="text-lg font-bold text-gray-900">{selectedPayroll.employee?.first_name} {selectedPayroll.employee?.last_name}</h4>
                <p className="text-xs text-gray-500">{selectedPayroll.employee?.position} • {selectedPayroll.employee?.department}</p>
              </div>
              <div className="flex flex-col items-end gap-2">
                <StatusBadge status={isEditing ? editForm.status : selectedPayroll.status} />
                {!isEditing && selectedPayroll.status === 'draft' && (
                  <button onClick={handleEditInit} className="text-brand-600 text-xs font-bold hover:underline flex items-center gap-1">
                    <Plus size={12} /> Edit Fields
                  </button>
                )}
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Earnings</p>
                  {isEditing && (
                    <select 
                      onChange={(e) => {
                        if (e.target.value === 'custom') handleAddField('allowances')
                        else if (e.target.value) handleAddField('allowances', e.target.value)
                        e.target.value = ''
                      }}
                      className="text-[10px] text-brand-600 font-bold bg-brand-50 hover:bg-brand-100 px-2 py-1 rounded border-none focus:ring-0"
                    >
                      <option value="">+ Add Item</option>
                      <option value="Overtime Pay">Overtime Pay</option>
                      <option value="Rest Day Pay">Rest Day Pay</option>
                      <option value="Rest Day OT Pay">Rest Day OT Pay</option>
                      <option value="Bonus">Bonus</option>
                      <option value="Travel Allowance">Travel Allowance</option>
                      <option value="custom">Other (Custom)</option>
                    </select>
                  )}
                </div>
                
                <div className="space-y-2">
                  <div className="flex justify-between items-center text-sm p-3 bg-gray-50 rounded-lg border border-gray-100">
                    <span className="text-gray-600">
                      { (isEditing ? editForm.employee?.rate_type : selectedPayroll.employee?.rate_type) === 'daily' 
                        ? `Base Pay (${isEditing ? editForm.days_worked : selectedPayroll.days_worked} Days)`
                        : 'Base Salary (Half)'
                      }
                    </span>
                    {isEditing ? (
                      <div className="flex items-center gap-1">
                        <span className="text-gray-400">₱</span>
                        <input 
                          type="number" 
                          className="w-24 text-right bg-transparent font-semibold focus:outline-none"
                          value={editForm.base_salary}
                          onChange={(e) => handleFieldChange(null, null, 'base_salary', e.target.value)}
                        />
                      </div>
                    ) : (
                      <span className="font-semibold text-gray-900">
                        ₱{ (selectedPayroll.employee?.rate_type === 'daily' 
                            ? (Number(selectedPayroll.base_salary) * Number(selectedPayroll.days_worked))
                            : (Number(selectedPayroll.base_salary) / 2)
                           ).toLocaleString() }
                      </span>
                    )}
                  </div>

                  {(isEditing ? editForm.allowances : (Array.isArray(selectedPayroll.allowances) ? selectedPayroll.allowances : Object.entries(selectedPayroll.allowances || {}).map(([k, v]) => ({ label: k, amount: v })))).map((item, idx) => (
                    <div key={idx} className="flex justify-between items-center text-sm p-3 bg-blue-50/50 rounded-lg border border-blue-100 group">
                      {isEditing ? (
                        <>
                          <input 
                            className="bg-transparent text-blue-700 placeholder:text-blue-300 focus:outline-none flex-1 mr-2"
                            value={item.label}
                            placeholder="Description"
                            onChange={(e) => handleFieldChange('allowances', idx, 'label', e.target.value)}
                          />
                          <div className="flex items-center gap-2">
                            <input 
                              type="number" 
                              className="w-20 text-right bg-transparent font-semibold focus:outline-none"
                              value={item.amount}
                              onChange={(e) => handleFieldChange('allowances', idx, 'amount', e.target.value)}
                            />
                            <button onClick={() => handleRemoveField('allowances', idx)} className="text-red-400 hover:text-red-600">
                              <Plus size={14} className="rotate-45" />
                            </button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="text-blue-700 capitalize">{item.label.replace('_', ' ')}</span>
                          <span className="font-semibold text-blue-800">+₱{Number(item.amount).toLocaleString()}</span>
                        </>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest">Deductions</p>
                  {isEditing && (
                    <select 
                      onChange={(e) => {
                        if (e.target.value === 'custom') handleAddField('deductions')
                        else if (e.target.value) handleAddField('deductions', e.target.value)
                        e.target.value = ''
                      }}
                      className="text-[10px] text-red-600 font-bold bg-red-50 hover:bg-red-100 px-2 py-1 rounded border-none focus:ring-0"
                    >
                      <option value="">+ Add Item</option>
                      <option value="Late">Late</option>
                      <option value="Undertime">Undertime</option>
                      <option value="Absent">Absent</option>
                      <option value="Half Day">Half Day</option>
                      <option value="SSS EE Contribution">SSS EE Contribution</option>
                      <option value="PhilHealth EE Contribution">PhilHealth EE Contribution</option>
                      <option value="Pag-IBIG EE Contribution">Pag-IBIG EE Contribution</option>
                      <option value="Cash Advance">Cash Advance</option>
                      <option value="custom">Other (Custom)</option>
                    </select>
                  )}
                </div>
                
                <div className="space-y-2">
                  {(isEditing ? editForm.deductions : (Array.isArray(selectedPayroll.deductions) ? selectedPayroll.deductions : Object.entries(selectedPayroll.deductions || {}).map(([k, v]) => ({ label: k, amount: v })))).map((item, idx) => (
                    <div key={idx} className="flex justify-between items-center text-sm p-3 bg-red-50/50 rounded-lg border border-red-100 group">
                      {isEditing ? (
                        <>
                          <input 
                            className="bg-transparent text-red-700 placeholder:text-red-300 focus:outline-none flex-1 mr-2"
                            value={item.label}
                            placeholder="Description"
                            onChange={(e) => handleFieldChange('deductions', idx, 'label', e.target.value)}
                          />
                          <div className="flex items-center gap-2">
                            <input 
                              type="number" 
                              className="w-20 text-right bg-transparent font-semibold focus:outline-none text-red-700"
                              value={item.amount}
                              onChange={(e) => handleFieldChange('deductions', idx, 'amount', e.target.value)}
                            />
                            <button onClick={() => handleRemoveField('deductions', idx)} className="text-red-400 hover:text-red-600">
                              <Plus size={14} className="rotate-45" />
                            </button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="text-red-700 capitalize">{item.label.replace('_', ' ')}</span>
                          <span className="font-semibold text-red-800">-₱{Number(item.amount).toLocaleString()}</span>
                        </>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </div>

            <div className="bg-gray-50/50 p-4 rounded-xl border border-gray-100">
              <p className="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-3">Attendance Metrics</p>
              <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                {[
                  { label: 'Work Hours', value: isEditing ? editForm.total_hours : selectedPayroll.total_hours, suffix: 'h', key: 'total_hours' },
                  { label: 'Days Worked', value: isEditing ? editForm.days_worked : selectedPayroll.days_worked, suffix: 'd', key: 'days_worked' },
                  { label: 'Overtime', value: isEditing ? editForm.overtime_hours : selectedPayroll.overtime_hours, suffix: 'h', key: 'overtime_hours' },
                  { label: 'Late', value: isEditing ? editForm.late_minutes : selectedPayroll.late_minutes, suffix: 'm', key: 'late_minutes' },
                  { label: 'Undertime', value: isEditing ? editForm.undertime_minutes : selectedPayroll.undertime_minutes, suffix: 'm', key: 'undertime_minutes' },
                ].map(m => (
                  <div key={m.label}>
                    <p className="text-[10px] text-gray-400 font-medium">{m.label}</p>
                    {isEditing ? (
                      <div className="flex items-center gap-1 border-b border-gray-200">
                        <input 
                          type="number" 
                          className="w-full bg-transparent text-sm font-bold focus:outline-none py-1"
                          value={m.value}
                          onChange={(e) => handleFieldChange(null, null, m.key, e.target.value)}
                        />
                        <span className="text-[10px] text-gray-400">{m.suffix}</span>
                      </div>
                    ) : (
                      <p className="text-sm font-bold text-gray-700">{Number(m.value).toFixed(1)}{m.suffix}</p>
                    )}
                  </div>
                ))}
              </div>
            </div>

            <div className="flex justify-center">
              <button 
                onClick={() => setShowBreakdown(!showBreakdown)}
                className="text-[10px] text-gray-400 font-bold hover:text-brand-600 uppercase tracking-widest flex items-center gap-1 transition-colors"
              >
                <Plus size={10} className={showBreakdown ? 'rotate-45' : ''} />
                {showBreakdown ? 'Hide Calculation Logic' : 'View Calculation Logic'}
              </button>
            </div>

            {showBreakdown && (
              <div className="bg-brand-50/30 p-4 rounded-xl border border-brand-100/50 animate-in fade-in slide-in-from-top-2 duration-300">
                <div className="flex items-center gap-2 mb-2">
                  <div className="w-1 h-4 bg-brand-500 rounded-full"></div>
                  <p className="text-[10px] text-brand-600 uppercase font-bold tracking-widest">Calculation Breakdown</p>
                </div>
                
                <div className="space-y-4">
                  <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                    <p className="text-[10px] text-gray-400 font-bold uppercase mb-2">Base Salary Rules</p>
                    <div className="space-y-2">
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Rate Type</span>
                        <span className="font-bold text-gray-700 uppercase">{selectedPayroll.employee.rate_type}</span>
                      </div>
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Base Salary</span>
                        <span className="font-bold text-gray-700">₱{Number(selectedPayroll.base_salary).toLocaleString()}</span>
                      </div>
                      <div className="flex justify-between text-xs pt-1 border-t border-gray-100">
                        <span className="text-gray-500">Derived Daily Rate</span>
                        <span className="font-bold text-brand-600">₱{Number(selectedPayroll.daily_rate).toLocaleString()}</span>
                      </div>
                      <div className="flex justify-between text-xs">
                        <span className="text-gray-500">Hourly Rate</span>
                        <span className="font-bold text-brand-600">₱{(Number(selectedPayroll.daily_rate) / 8).toFixed(2)}</span>
                      </div>
                      <p className="text-[9px] text-gray-400 italic">
                        Daily Rate / 8 hours
                      </p>
                      <p className="text-[9px] text-gray-400 italic">
                        {selectedPayroll.employee.rate_type === 'monthly' 
                          ? `Daily Rate Formula: (Base × 12) / ${Number(selectedPayroll.daily_rate) > 0 ? (Math.round((Number(selectedPayroll.base_salary) * 12) / Number(selectedPayroll.daily_rate))) : 'divisor'}` 
                          : 'Daily Rate Formula: Base salary as daily rate'}
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                      <p className="text-[10px] text-gray-400 font-bold uppercase mb-2">Earnings Logic</p>
                      <div className="space-y-2 text-xs">
                        <div className="flex justify-between items-start">
                          <div className="flex flex-col">
                            <span className="text-gray-500">Regular Base</span>
                            <span className="text-[9px] text-gray-400 italic">
                              {selectedPayroll.employee.rate_type === 'daily' 
                                ? `₱${Number(selectedPayroll.daily_rate).toLocaleString()} × ${selectedPayroll.days_worked}d`
                                : `₱${Number(selectedPayroll.base_salary).toLocaleString()} / 2`}
                            </span>
                          </div>
                          <span className="text-gray-700 font-medium">₱{Number(selectedPayroll.gross_pay - (selectedPayroll.allowances?.reduce((acc, a) => acc + a.amount, 0) || 0)).toLocaleString()}</span>
                        </div>

                        {selectedPayroll.allowances?.map((a, i) => {
                          let formula = '';
                          const hRate = Number(selectedPayroll.daily_rate) / 8;
                          if (a.label === 'Overtime Pay') {
                            const otHours = a.amount / (hRate * 1.25);
                            formula = `${otHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.25`;
                          } else if (a.label === 'Rest Day Pay') {
                            const rdHours = a.amount / (hRate * 1.30);
                            formula = `${rdHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.30`;
                          } else if (a.label === 'Rest Day OT Pay') {
                            const rdotHours = a.amount / (hRate * 1.69);
                            formula = `${rdotHours.toFixed(2)}h × ₱${hRate.toFixed(2)} × 1.69`;
                          }

                          return (
                            <div key={i} className="flex justify-between items-start pt-1 border-t border-gray-100/50">
                              <div className="flex flex-col">
                                <span className="text-gray-500">{a.label}</span>
                                {formula && <span className="text-[9px] text-gray-400 italic">{formula}</span>}
                              </div>
                              <span className="text-brand-600 font-medium">+ ₱{Number(a.amount).toLocaleString()}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>

                    <div className="bg-white/50 p-3 rounded-lg border border-brand-100/30">
                      <p className="text-[10px] text-gray-400 font-bold uppercase mb-2">Deductions Logic</p>
                      <div className="space-y-2 text-xs">
                        {Object.entries(selectedPayroll.deductions || {}).map(([rawKey, rawVal]) => {
                          const val = typeof rawVal === 'object' ? rawVal.amount : rawVal;
                          const key = typeof rawVal === 'object' ? rawVal.label : rawKey;
                          
                          let formula = '';
                          const hRate = Number(selectedPayroll.daily_rate) / 8;
                          const dRate = Number(selectedPayroll.daily_rate);
                          
                          if (key === 'Late') {
                            formula = `${selectedPayroll.late_minutes}m / 60 × ₱${hRate.toFixed(2)}`;
                          } else if (key === 'Undertime') {
                            formula = `${selectedPayroll.undertime_minutes}m / 60 × ₱${hRate.toFixed(2)}`;
                          } else if (key === 'Absent') {
                            const days = dRate > 0 ? Number(val) / dRate : 0;
                            formula = `${Math.round(days)}d × ₱${dRate.toLocaleString()}`;
                          } else if (key === 'Half Day') {
                            const count = dRate > 0 ? Number(val) / (dRate / 2) : 0;
                            formula = `${Math.round(count)} sessions × (₱${dRate.toLocaleString()} / 2)`;
                          } else if (key.includes('EE Contribution')) {
                            formula = 'Statutory Fixed';
                          }

                          return (
                            <div key={key} className="flex justify-between items-start pt-1 first:pt-0 border-t first:border-0 border-gray-100/50">
                              <div className="flex flex-col">
                                <span className="text-gray-500">{key}</span>
                                {formula && <span className="text-[9px] text-gray-400 italic">{formula}</span>}
                              </div>
                              <span className="text-red-500 font-medium">- ₱{Number(val).toLocaleString()}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}

          </div>
        )}
      </Modal>
    </div>
  )
}
