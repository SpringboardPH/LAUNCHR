<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function summary()
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Total employees (active only)
        $totalEmployees = Employee::where('status', 'active')->count();

        // Employees present today (clocked in)
        $presentToday = AttendanceLog::where('date', $today)
            ->whereNotNull('clock_in_time')
            ->distinct('employee_id')
            ->count('employee_id');

        // Employees absent today (no clock in)
        $absentToday = $totalEmployees - $presentToday;

        // Late arrivals today (clocked in after 9 AM)
        $lateToday = AttendanceLog::where('date', $today)
            ->whereNotNull('clock_in_time')
            ->get()
            ->filter(function($log) {
                [$hour, $minute] = sscanf($log->clock_in_time, '%d:%d');
                return ($hour * 60 + $minute) > (9 * 60);
            })
            ->count();

        // Employees on leave today
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        // Employees with incomplete hours today (worked < 9 hours)
        $incompleteHours = AttendanceLog::where('date', $today)
            ->whereNotNull('clock_in_time')
            ->whereNotNull('clock_out_time')
            ->get()
            ->filter(function($log) {
                [$inH, $inM] = sscanf($log->clock_in_time, '%d:%d');
                [$outH, $outM] = sscanf($log->clock_out_time, '%d:%d');
                $inMinutes = $inH * 60 + $inM;
                $outMinutes = $outH * 60 + $outM;
                $hoursWorked = ($outMinutes - $inMinutes) / 60;
                return $hoursWorked < 9;
            })
            ->count();

        // Attendance rate this month
        $businessDays = $this->getBusinessDaysInMonth($today->month, $today->year);
        $expectedWorkDays = $totalEmployees * $businessDays;
        
        $actualAttendance = AttendanceLog::whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('clock_in_time')
            ->count();

        $attendanceRate = $expectedWorkDays > 0 
            ? round(($actualAttendance / $expectedWorkDays) * 100, 1)
            : 0;

        // On-time vs late arrivals this month
        $allLogs = AttendanceLog::whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('clock_in_time')
            ->get();
        
        $onTimeCount = 0;
        foreach ($allLogs as $log) {
            [$hour, $minute] = sscanf($log->clock_in_time, '%d:%d');
            if (($hour * 60 + $minute) <= (9 * 60)) {
                $onTimeCount++;
            }
        }
        $onTimePercent = ($allLogs->count() > 0) ? round(($onTimeCount / $allLogs->count()) * 100, 1) : 0;

        // New hires (last 30 days)
        $newHires = Employee::where('status', 'active')
            ->where('hire_date', '>=', $thirtyDaysAgo)
            ->count();

        // Pending leave requests
        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();

        // Last payroll run
        $lastPayroll = PayrollRun::orderBy('created_at', 'desc')->first();

        // Employees by department
        $byDepartment = Employee::where('status', 'active')
            ->groupBy('department')
            ->select('department', DB::raw('count(*) as count'))
            ->orderBy('count', 'desc')
            ->get();

        // Leave breakdown by type (pending)
        $leaveByType = LeaveRequest::where('status', 'pending')
            ->groupBy('leave_type')
            ->select('leave_type', DB::raw('count(*) as count'))
            ->get()
            ->map(fn($item) => [
                'type' => str_replace('_', ' ', ucfirst($item->leave_type)),
                'count' => $item->count,
            ]);

        // Recent pending leaves with employee data
        $recentLeaves = LeaveRequest::where('status', 'pending')
            ->with('employee:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($leave) => [
                'id' => $leave->id,
                'employee' => [
                    'first_name' => $leave->employee->first_name,
                    'last_name' => $leave->employee->last_name,
                ],
                'leave_type' => $leave->leave_type,
                'days_requested' => $leave->days_requested,
                'status' => $leave->status,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_employees' => $totalEmployees,
                    'present_today' => $presentToday,
                    'absent_today' => $absentToday,
                    'late_today' => $lateToday,
                    'on_leave_today' => $onLeaveToday,
                    'incomplete_hours_today' => $incompleteHours,
                    'new_hires_30_days' => $newHires,
                    'pending_leaves' => $pendingLeaves,
                    'attendance_rate' => $attendanceRate,
                    'on_time_percent' => $onTimePercent,
                    'last_payroll' => $lastPayroll?->created_at 
                        ? Carbon::parse($lastPayroll->created_at)->format('M d, Y')
                        : null,
                ],
                'by_department' => $byDepartment->toArray(),
                'leave_by_type' => $leaveByType->toArray(),
                'recent_leaves' => $recentLeaves->toArray(),
            ],
            'message' => 'Dashboard summary retrieved',
        ]);
    }

    /**
     * Get number of business days in a month
     */
    private function getBusinessDaysInMonth($month, $year)
    {
        $count = 0;
        $date = mktime(0, 0, 0, $month, 1, $year);
        
        while (date('m', $date) == $month) {
            $dayOfWeek = date('w', $date);
            // Count Monday-Friday (1-5)
            if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                $count++;
            }
            $date = strtotime('+1 day', $date);
        }
        
        return $count;
    }
}
