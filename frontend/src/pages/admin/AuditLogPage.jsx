import React, { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { getAuditLogs, auditLogKeys } from '../../api/queries'
import { PageHeader, PageSpinner, PagePagination } from '../../components/ui/index.jsx'
import { History, User, Globe, Monitor, Info, ChevronDown, ChevronUp, RefreshCw } from 'lucide-react'
import clsx from 'clsx'

const MODELS = ['Employee', 'EmployeeRequest', 'AttendanceLog', 'Payroll', 'CalendarEvent', 'LeaveRequest']

export default function AuditLogPage() {
  const [page, setPage] = useState(1)
  const [expandedId, setExpandedId] = useState(null)
  const [action, setAction] = useState('')
  const [model, setModel] = useState('')
  const [actor, setActor] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const resetPage = () => setPage(1)

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: auditLogKeys.list({ page, action, model, actor, dateFrom, dateTo }),
    queryFn: () => getAuditLogs({ page, action, model, actor, date_from: dateFrom, date_to: dateTo }),
    refetchInterval: 10000, // Auto-refresh every 10s
  })

  const logs = data?.data || []
  const pagination = data?.pagination

  const toggleExpand = (id) => {
    setExpandedId(expandedId === id ? null : id)
  }

  const renderJson = (val) => {
    if (!val) return 'None'
    try {
      return JSON.stringify(val, null, 2)
    } catch (e) {
      return String(val)
    }
  }

  return (
    <div>
      <PageHeader
        title="Audit Logs"
        description="Track all system activities and database changes."
        icon={<History className="text-brand-600" size={24} />}
        action={
          <button
            onClick={() => refetch()}
            disabled={isFetching}
            className="btn-secondary flex items-center gap-2"
          >
            <RefreshCw size={14} className={clsx(isFetching && "animate-spin")} />
            {isFetching ? 'Refreshing...' : 'Refresh'}
          </button>
        }
        help={[
          { heading: 'Filtering', items: [
            'Type into the actor search bar to filter by the name of the user who performed the action.',
            'Use the Action dropdown to show only Created, Updated, or Deleted events.',
            'Use the Resource dropdown to show changes to a specific record type (e.g. Employee, Payroll).',
            'Use the date fields to narrow the log to a specific date range.',
          ]},
          { heading: 'Reading the Log', items: [
            'Each row shows the timestamp, the user who performed the action (Actor), the event type, and a description.',
            'Event types are color-coded: green = Create, yellow = Update, red = Delete.',
          ]},
          { heading: 'Expanding a Row', items: [
            'Click any row to expand it and see detailed metadata: IP address, user agent, and the resource that was changed.',
            'For Update events, old and new values are shown side-by-side in JSON format.',
            'Click the row again to collapse it.',
          ]},
          { heading: 'Refresh', items: [
            'The log auto-refreshes every 10 seconds. Click the Refresh button to force an immediate reload.',
          ]},
        ]}
      />

      <div className="flex flex-col sm:flex-row gap-3 mb-5">
        <div className="relative flex-1 sm:max-w-xs">
          <User size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            className="input pl-9" placeholder="Search by actor name..."
            value={actor} onChange={e => { setActor(e.target.value); resetPage() }}
          />
        </div>
        <select className="input sm:w-40" value={action} onChange={e => { setAction(e.target.value); resetPage() }}>
          <option value="">All actions</option>
          <option value="created">Created</option>
          <option value="updated">Updated</option>
          <option value="deleted">Deleted</option>
        </select>
        <select className="input sm:w-48" value={model} onChange={e => { setModel(e.target.value); resetPage() }}>
          <option value="">All resources</option>
          {MODELS.map(m => <option key={m} value={m}>{m}</option>)}
        </select>
        <input type="date" className="input sm:w-40" value={dateFrom}
          onChange={e => { setDateFrom(e.target.value); resetPage() }} />
        <input type="date" className="input sm:w-40" value={dateTo}
          onChange={e => { setDateTo(e.target.value); resetPage() }} />
      </div>

      <div className="card overflow-hidden">
        {isLoading ? (
          <PageSpinner />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-48">Date & Time</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-32">Actor</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-40">Event</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Details</th>
                  <th className="px-6 py-4 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider w-24">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 bg-white">
                {logs.map((log) => (
                  <React.Fragment key={log.id}>
                    <tr className={clsx("hover:bg-gray-50 transition-colors", expandedId === log.id && "bg-brand-50/30")}>
                      <td className="px-6 py-4 whitespace-nowrap text-gray-600 font-medium">
                        {format(parseISO(log.created_at), 'MMM dd, yyyy HH:mm:ss')}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center gap-2">
                          <div className="w-6 h-6 rounded-full bg-brand-100 flex items-center justify-center text-[10px] text-brand-700 font-bold">
                            {log.user?.name?.charAt(0) || 'S'}
                          </div>
                          <span className="text-gray-700 font-medium">{log.user?.name || 'System'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={clsx(
                          "px-2 py-1 rounded-md text-[10px] font-bold uppercase",
                          log.event?.includes('create') ? "bg-green-100 text-green-700" :
                          log.event?.includes('update') ? "bg-blue-100 text-blue-700" :
                          log.event?.includes('delete') ? "bg-red-100 text-red-700" :
                          "bg-gray-100 text-gray-700"
                        )}>
                          {log.event?.replace('_', ' ') || 'SYSTEM EVENT'}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-gray-600 max-w-md truncate">
                        {log.description || 'No description provided'}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button 
                          onClick={() => toggleExpand(log.id)}
                          className="text-gray-400 hover:text-brand-600 transition-colors p-1"
                        >
                          {expandedId === log.id ? <ChevronUp size={18} /> : <ChevronDown size={18} />}
                        </button>
                      </td>
                    </tr>
                    {expandedId === log.id && (
                      <tr>
                        <td colSpan={5} className="px-6 py-6 bg-brand-50/30">
                          <div className="grid md:grid-cols-2 gap-6">
                            <div className="space-y-4">
                              <div className="bg-white p-4 rounded-lg border border-brand-100 shadow-sm">
                                <h4 className="text-xs font-bold text-gray-400 uppercase mb-3 flex items-center gap-2">
                                  <Info size={14} /> Metadata
                                </h4>
                                <div className="space-y-2 text-xs">
                                  <div className="flex justify-between border-b border-gray-50 pb-1">
                                    <span className="text-gray-500 flex items-center gap-1"><Globe size={12}/> IP Address</span>
                                    <span className="text-gray-700 font-mono">{log.ip_address || '—'}</span>
                                  </div>
                                  <div className="pt-1">
                                    <span className="text-gray-500 flex items-center gap-1 mb-1"><Monitor size={12}/> User Agent</span>
                                    <p className="text-gray-600 font-mono break-all leading-relaxed bg-gray-50 p-2 rounded">
                                      {log.user_agent || '—'}
                                    </p>
                                  </div>
                                  <div className="flex justify-between border-t border-gray-50 pt-2 mt-2">
                                    <span className="text-gray-500">Resource</span>
                                    <span className="text-gray-700">{log.auditable_type || 'N/A'} (ID: {log.auditable_id || '—'})</span>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <div className="space-y-4">
                              <div className="bg-white p-4 rounded-lg border border-brand-100 shadow-sm">
                                <h4 className="text-xs font-bold text-gray-400 uppercase mb-3">Value Changes</h4>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                  <div>
                                    <p className="text-[10px] font-bold text-red-400 uppercase mb-1">Old Values</p>
                                    <pre className="text-[10px] bg-red-50/50 p-3 rounded border border-red-100 max-h-48 overflow-y-auto font-mono text-gray-700">
                                      {renderJson(log.old_values)}
                                    </pre>
                                  </div>
                                  <div>
                                    <p className="text-[10px] font-bold text-green-400 uppercase mb-1">New Values</p>
                                    <pre className="text-[10px] bg-green-50/50 p-3 rounded border border-green-100 max-h-48 overflow-y-auto font-mono text-gray-700">
                                      {renderJson(log.new_values)}
                                    </pre>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
                {logs.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-6 py-12 text-center text-gray-400 italic">
                      No audit logs found.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
        {pagination && pagination.last_page > 1 && (
          <div className="px-6 py-4 border-t border-gray-100 bg-gray-50">
            <PagePagination 
              pagination={pagination} 
              onPageChange={setPage} 
            />
          </div>
        )}
      </div>
    </div>
  )
}
