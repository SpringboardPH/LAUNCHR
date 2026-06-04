<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\SystemClock;
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
        $today = SystemClock::today();
        $todayString = $today->toDateString();
        $now = SystemClock::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Fetch basic counts in parallel/efficiently
        $totalEmployees = Employee::where('status', 'active')->count();
        $newHires = Employee::where('status', 'active')
            ->where('hire_date', '>=', $thirtyDaysAgo)
            ->count();
        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();
        $lastPayroll = PayrollRun::orderBy('created_at', 'desc')->first();

        // Today's attendance summary in one query
        $todayStats = AttendanceLog::where('date', $todayString)
            ->selectRaw("
                COUNT(DISTINCT employee_id) as present_count,
                SUM(CASE WHEN clock_in_time > '09:00:00' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN status IN ('undertime', 'half_day') THEN 1 ELSE 0 END) as short_hours_count
            ")
            ->first();

        $presentToday = (int) ($todayStats->present_count ?? 0);
        $lateToday = (int) ($todayStats->late_count ?? 0);
        $shortHoursToday = (int) ($todayStats->short_hours_count ?? 0);
        $absentToday = max(0, $totalEmployees - $presentToday);

        // Employees on leave today
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $todayString)
            ->where('end_date', '>=', $todayString)
            ->count();

        // Monthly stats in one query
        $monthlyStats = AttendanceLog::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw("
                COUNT(*) as total_logs,
                SUM(CASE WHEN status != 'late' AND clock_in_time IS NOT NULL THEN 1 ELSE 0 END) as on_time_logs,
                COUNT(CASE WHEN clock_in_time IS NOT NULL THEN 1 END) as present_logs
            ")
            ->first();

        $businessDays = $this->getBusinessDaysInMonth($today->month, $today->year);
        $expectedWorkDays = $totalEmployees * $businessDays;
        $actualAttendance = (int) ($monthlyStats->present_logs ?? 0);
        $attendanceRate = $expectedWorkDays > 0 ? round(($actualAttendance / $expectedWorkDays) * 100, 1) : 0;
        
        $presentLogsCount = (int) ($monthlyStats->present_logs ?? 0);
        $onTimePercent = $presentLogsCount > 0 
            ? round(((int)($monthlyStats->on_time_logs ?? 0) / $presentLogsCount) * 100, 1) 
            : 0;

        // Employees by department
        $byDepartment = Employee::where('status', 'active')
            ->groupBy('department')
            ->select('department', DB::raw('count(*) as count'))
            ->orderBy('count', 'desc')
            ->get();

        // Leave breakdown (combined queries)
        $leaveByType = LeaveRequest::where('status', 'pending')
            ->groupBy('leave_type')
            ->select('leave_type', DB::raw('count(*) as count'))
            ->get()
            ->map(fn($item) => [
                'type' => str_replace('_', ' ', ucfirst($item->leave_type)),
                'count' => $item->count,
            ]);

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

        $leaveStatusBreakdown = LeaveRequest::groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->map(fn($item) => [
                'status' => ucfirst($item->status),
                'count' => $item->count,
            ]);

        // Optimized Department Attendance Rates (using JOIN)
        $departmentAttendance = DB::table('employees')
            ->leftJoin('attendance_logs', function($join) use ($monthStart, $monthEnd) {
                $join->on('employees.id', '=', 'attendance_logs.employee_id')
                    ->whereBetween('attendance_logs.date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->whereNotNull('attendance_logs.clock_in_time');
            })
            ->where('employees.status', 'active')
            ->select('employees.department', DB::raw('COUNT(attendance_logs.id) as present_count'), DB::raw('COUNT(DISTINCT employees.id) as emp_count'))
            ->groupBy('employees.department')
            ->get()
            ->map(function($item) use ($businessDays) {
                $expectedDays = $item->emp_count * $businessDays;
                return [
                    'department' => $item->department ?? 'Unassigned',
                    'rate' => $expectedDays > 0 ? round(($item->present_count / $expectedDays) * 100, 1) : 0,
                ];
            })
            ->sortBy('department')
            ->values();

        // Optimized Weekly Trend (Single Query with grouping)
        // Note: strftime is SQLite-specific. If moving to MySQL/PostgreSQL, update this to YEARWEEK or similar.
        $weeklyTrend = [];
        $startOfTrend = $now->copy()->subWeeks(3)->startOfWeek();
        
        $weeklyData = AttendanceLog::where('date', '>=', $startOfTrend->toDateString())
            ->whereNotNull('clock_in_time')
            ->selectRaw("strftime('%Y-%W', date) as week_key, COUNT(DISTINCT employee_id) as present_count, MIN(date) as week_start")
            ->groupBy('week_key')
            ->orderBy('week_key')
            ->get()
            ->keyBy('week_key');

        for ($i = 0; $i < 4; $i++) {
            $weekStart = $startOfTrend->copy()->addWeeks($i);
            $weekKey = $weekStart->format('Y-W');
            $weekLabel = $weekStart->format('M d');
            
            // Still need to count business days for the specific week accurately
            $businessDaysInWeek = 5; // Simplified assumption or could be calculated
            $presentCount = $weeklyData->has($weekKey) ? $weeklyData[$weekKey]->present_count : 0;
            
            $weeklyAttendanceRate = ($businessDaysInWeek > 0 && $totalEmployees > 0)
                ? round(($presentCount / ($businessDaysInWeek * $totalEmployees)) * 100, 1)
                : 0;
            
            $weeklyTrend[] = [
                'week' => $weekLabel,
                'rate' => $weeklyAttendanceRate,
                'count' => (int) $presentCount,
            ];
        }

        $monthlyStatusDist = AttendanceLog::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->map(fn($item) => [
                'status' => ucfirst(str_replace('_', ' ', $item->status)),
                'count' => $item->count,
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
                    'short_hours_today' => $shortHoursToday,
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
                'leave_status_breakdown' => $leaveStatusBreakdown->toArray(),
                'recent_leaves' => $recentLeaves->toArray(),
                'department_attendance_rates' => $departmentAttendance->toArray(),
                'weekly_attendance_trend' => $weeklyTrend,
                'monthly_status_distribution' => $monthlyStatusDist->toArray(),
            ],
            'message' => 'Dashboard summary retrieved',
        ]);
    }

    /**
     * Get number of business days in a month
     */
    private function getBusinessDaysInMonth(int $month, int $year): int
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
