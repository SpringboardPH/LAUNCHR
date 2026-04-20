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

        // Total employees (active only)
        $totalEmployees = Employee::where('status', 'active')->count();

        // Employees present today
        $presentToday = AttendanceLog::where('date', $today)
            ->whereNotNull('clock_in_time')
            ->distinct('employee_id')
            ->count('employee_id');

        // Employees absent today
        $absentToday = $totalEmployees - $presentToday;

        // Attendance rate this month
        $businessDays = $this->getBusinessDaysInMonth($today->month, $today->year);
        $expectedWorkDays = $totalEmployees * $businessDays;
        
        $actualAttendance = AttendanceLog::whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('clock_in_time')
            ->count();

        $attendanceRate = $expectedWorkDays > 0 
            ? round(($actualAttendance / $expectedWorkDays) * 100, 1)
            : 0;

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
                'days_requested' => $leave->start_date->diffInDays($leave->end_date) + 1,
                'status' => $leave->status,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_employees' => $totalEmployees,
                    'present_today' => $presentToday,
                    'absent_today' => $absentToday,
                    'pending_leaves' => $pendingLeaves,
                    'attendance_rate' => $attendanceRate,
                    'last_payroll' => $lastPayroll?->created_at 
                        ? Carbon::parse($lastPayroll->created_at)->format('M d, Y')
                        : null,
                ],
                'by_department' => $byDepartment->toArray(),
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
