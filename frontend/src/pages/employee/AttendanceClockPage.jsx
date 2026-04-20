import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { clockIn, clockOut, getAttendanceToday, attendanceKeys } from '../../api/queries'
import { PageHeader, PageSpinner } from '../../components/ui/index.jsx'
import { Clock, LogOut, AlertCircle } from 'lucide-react'
import { format } from 'date-fns'

export default function AttendanceClockPage() {
  const [notes, setNotes] = useState('')
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: todayAttendance, isLoading, refetch } = useQuery({
    queryKey: attendanceKeys.today(),
    queryFn: getAttendanceToday,
    refetchOnWindowFocus: true,
    refetchOnMount: 'stale',
  })

  const inMutation = useMutation({
    mutationFn: () => clockIn(notes),
    onSuccess: (response) => {
      setNotes('')
      // Update cache with the returned attendance record
      qc.setQueryData(attendanceKeys.today(), response)
    },
    onError: () => {
      setNotes('')
      refetch()
    },
  })

  const outMutation = useMutation({
    mutationFn: () => clockOut(notes),
    onSuccess: (response) => {
      setNotes('')
      // Update cache with the returned attendance record
      qc.setQueryData(attendanceKeys.today(), response)
    },
    onError: () => {
      setNotes('')
      refetch()
    },
  })

  if (isLoading) return <PageSpinner />

  const isClockedIn = todayAttendance?.clock_in_time
  const isClockedOut = todayAttendance?.clock_out_time

  return (
    <div>
      <PageHeader
        title="Clock In / Out"
        description={format(new Date(), 'EEEE, MMMM d, yyyy')}
        action={
          <button onClick={() => navigate('/employee')} className="btn-secondary">
            ← Back
          </button>
        }
      />

      <div className="max-w-md mx-auto">
        <div className="card p-8">
          {/* Current Time Display */}
          <div className="text-center mb-8">
            <div className="inline-block bg-gradient-to-br from-brand-100 to-brand-50 p-8 rounded-full mb-4">
              <Clock size={40} className="text-brand-600" />
            </div>
            <div className="text-5xl font-bold text-gray-900 mb-2" id="currentTime">
              {format(new Date(), 'HH:mm:ss')}
            </div>
            <p className="text-sm text-gray-500">{format(new Date(), 'EEEE, MMMM d')}</p>
          </div>

          {/* Status Display */}
          {isClockedIn && (
            <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
              <p className="text-xs text-green-600 font-medium mb-1">✓ Clocked In</p>
              <p className="text-lg font-semibold text-green-900">{todayAttendance.clock_in_time}</p>
            </div>
          )}

          {isClockedOut && (
            <div className="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
              <p className="text-xs text-gray-600 font-medium mb-1">✓ Clocked Out</p>
              <p className="text-lg font-semibold text-gray-900">{todayAttendance.clock_out_time}</p>
              <p className="text-xs text-gray-500 mt-2">Your work day is complete</p>
            </div>
          )}

          {/* Error Messages */}
          {inMutation.error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
              <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-medium text-red-900">{inMutation.error.response?.data?.message}</p>
              </div>
            </div>
          )}

          {outMutation.error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex gap-2">
              <AlertCircle size={16} className="text-red-600 shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-medium text-red-900">{outMutation.error.response?.data?.message}</p>
              </div>
            </div>
          )}

          {/* Notes Field */}
          <textarea
            className="input mb-4 resize-none"
            rows="3"
            placeholder="Add notes (optional)"
            value={notes}
            onChange={e => setNotes(e.target.value)}
            disabled={isClockedOut}
          />

          {/* Action Button */}
          {!isClockedIn ? (
            <button
              onClick={() => inMutation.mutate()}
              disabled={inMutation.isPending}
              className="btn btn-primary w-full"
            >
              <Clock size={16} />
              {inMutation.isPending ? 'Clocking in...' : 'Clock In'}
            </button>
          ) : !isClockedOut ? (
            <button
              onClick={() => outMutation.mutate()}
              disabled={outMutation.isPending}
              className="btn btn-secondary w-full"
            >
              <LogOut size={16} />
              {outMutation.isPending ? 'Clocking out...' : 'Clock Out'}
            </button>
          ) : (
            <div className="p-3 bg-gray-50 rounded-lg text-center">
              <p className="text-sm text-gray-600">You've already clocked out today</p>
            </div>
          )}
        </div>
      </div>

      {/* Update current time every second */}
      {typeof window !== 'undefined' && (
        <script>
          {`
            setInterval(() => {
              document.getElementById('currentTime').textContent = new Date().toLocaleTimeString('en-US', { hour12: false })
            }, 1000)
          `}
        </script>
      )}
    </div>
  )
}
