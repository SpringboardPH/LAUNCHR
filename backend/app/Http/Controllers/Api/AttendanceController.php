<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // Work hours configuration
    private const WORK_START_TIME = '09:00:00';
    private const WORK_END_TIME = '18:00:00';
    private const EARLY_CLOCK_IN = '08:45:00';
    private const LATE_CLOCK_OUT = '18:15:00';
    private const REQUIRED_HOURS = 9;

    private function getScheduleForDate($employeeId, Carbon $date)
    {
        return EmployeeSchedule::getForEmployeeOnDate($employeeId, $date);
    }

    private function parseTimeToMinutes($time)
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hour * 60 + $minute;
    }

    private function minutesToTime($minutes)
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%02d:%02d:00', $hours, $remainingMinutes);
    }

    /**
     * Calculate work status based on clock times
     */
    private function calculateStatus($clockInTime, $clockOutTime, $expectedHours = null, $workStartTime = null)
    {
        if (!$clockInTime) {
            return 'absent';
        }

        $clockInMinutes = $this->parseTimeToMinutes($clockInTime);
        $clockOutMinutes = $this->parseTimeToMinutes($clockOutTime);
        $workStartMinutes = $this->parseTimeToMinutes($workStartTime ?? self::WORK_START_TIME);
        $requiredHours = $expectedHours ?? self::REQUIRED_HOURS;
        $isLate = $clockInMinutes > $workStartMinutes;
        $hoursWorked = max(0, ($clockOutMinutes - $clockInMinutes) / 60);

        if ($isLate) {
            return 'late';
        }

        return $hoursWorked >= $requiredHours ? 'completed' : 'incomplete';
    }

    /**
     * Clock in - record employee arrival
     */
    public function clockIn(Request $request)
    {
        $request->validate([
            'notes' => 'nullable|string|max:255',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $user = $request->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Only HR/Admin can clock in other employees',
            ], 403);
        }
        
        // Use provided employee_id or authenticated user's employee record
        $employee = $employeeId 
            ? Employee::findOrFail($employeeId)
            : $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found',
            ], 404);
        }

        $today = Carbon::today();
        $schedule = $this->getScheduleForDate($employee->id, $today);

        if ($schedule && $schedule->template) {
            $workDays = $schedule->template->work_days ?? [];
            if (!in_array($today->dayOfWeek, $workDays, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee is not scheduled to work today',
                ], 400);
            }
        } else {
            // Check if today is a weekday (Monday = 1, Friday = 5)
            $dayOfWeek = $today->dayOfWeek;
            if ($dayOfWeek === Carbon::SATURDAY || $dayOfWeek === Carbon::SUNDAY) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot clock in on weekends',
                ], 400);
            }
        }

        // Check if already clocked in today
        $existingLog = AttendanceLog::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if ($existingLog && $existingLog->clock_in_time) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has already clocked in today',
            ], 400);
        }

        $clockInTime = now()->toTimeString();
        $clockInMinutes = $this->parseTimeToMinutes($clockInTime);
        $earlyAllowedMinutes = $this->parseTimeToMinutes(self::EARLY_CLOCK_IN);
        $lateAllowedMinutes = $this->parseTimeToMinutes(self::LATE_CLOCK_OUT);

        if ($schedule && $schedule->template) {
            $template = $schedule->template;
            $earlyAllowedMinutes = $this->parseTimeToMinutes($template->clock_in_start ?? $template->work_start_time ?? self::EARLY_CLOCK_IN);
            $lateAllowedMinutes = $this->parseTimeToMinutes($template->clock_in_end ?? $template->clock_out_end ?? self::LATE_CLOCK_OUT);
        }
        
        if ($clockInMinutes < $earlyAllowedMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Clock in is earlier than the allowed schedule window',
            ], 400);
        }
        
        if ($clockInMinutes > $lateAllowedMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Clock in is later than the allowed schedule window',
            ], 400);
        }

        // Create or update attendance log
        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            ['clock_in_time' => $clockInTime, 'notes' => $request->notes]
        );

        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Clocked in successfully',
        ], 201);
    }

    /**
     * Clock out - record employee departure
     */
    public function clockOut(Request $request)
    {
        $request->validate([
            'notes' => 'nullable|string|max:255',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $user = $request->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Only HR/Admin can clock out other employees',
            ], 403);
        }
        
        // Use provided employee_id or authenticated user's employee record
        $employee = $employeeId 
            ? Employee::findOrFail($employeeId)
            : $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found',
            ], 404);
        }

        // Get today's attendance log
        $today = Carbon::today();
        $schedule = $this->getScheduleForDate($employee->id, $today);
        $log = AttendanceLog::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'No clock in record found for today',
            ], 400);
        }

        if ($log->clock_out_time) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has already clocked out today',
            ], 400);
        }

        $clockOutTime = now()->toTimeString();
        $clockOutMinutes = $this->parseTimeToMinutes($clockOutTime);
        $workEndTime = self::WORK_END_TIME;
        $lateAllowedMinutes = $this->parseTimeToMinutes(self::LATE_CLOCK_OUT);

        if ($schedule && $schedule->template) {
            $template = $schedule->template;
            $workEndTime = $template->work_end_time ?? $template->end_time ?? self::WORK_END_TIME;
            $lateAllowedMinutes = $this->parseTimeToMinutes($template->clock_out_end ?? self::LATE_CLOCK_OUT);
        }
        $workEndMinutes = $this->parseTimeToMinutes($workEndTime);
        
        if ($clockOutMinutes < $workEndMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clock out before the scheduled end time',
            ], 400);
        }
        
        if ($clockOutMinutes > $lateAllowedMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Clock out only allowed until 6:15 PM',
            ], 400);
        }

        // Update with clock out time and calculate status
        $log->clock_out_time = $clockOutTime;
        $log->status = $this->calculateStatus(
            $log->clock_in_time,
            $clockOutTime,
            $schedule && $schedule->template ? $schedule->template->required_hours_per_day ?? $schedule->template->expected_hours_per_day : null,
            $schedule && $schedule->template ? $schedule->template->work_start_time ?? $schedule->template->start_time : null
        );
        $log->save();

        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Clocked out successfully',
        ]);
    }

    /**
     * Get employee's attendance records
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = AttendanceLog::with('employee');

        // Employees only see their own records
        if (!$user->isAdminOrHr()) {
            $query->where('employee_id', $user->employee->id);
        }
        // HR/Admin can see all records

        // Filter by month if provided (expects format: YYYY-MM)
        if ($month = $request->query('month')) {
            [$year, $monthNum] = explode('-', $month);
            $query->whereYear('date', (int)$year)
                  ->whereMonth('date', (int)$monthNum);
        }

        $records = $query->orderBy('date', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $records->items(),
            'pagination' => [
                'total' => $records->total(),
                'count' => $records->count(),
                'per_page' => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ],
            'message' => 'Attendance records retrieved',
        ]);
    }

    /**
     * Get single attendance record
     */
    public function show(Request $request, $id)
    {
        $employee = $request->user()->employee;
        $record = AttendanceLog::where('employee_id', $employee->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $record,
            'message' => 'Attendance record retrieved',
        ]);
    }

    /**
     * Get today's clock status - for employee or all employees for HR/Admin
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today();

        if (!$user->isAdminOrHr()) {
            // Employees get their own today's record
            $schedule = $this->getScheduleForDate($user->employee->id, $today);
            $record = AttendanceLog::where('employee_id', $user->employee->id)
                ->where('date', $today)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $record,
                    'schedule' => $schedule ? [
                        'id' => $schedule->id,
                        'employee_id' => $schedule->employee_id,
                        'schedule_template_id' => $schedule->schedule_template_id,
                        'start_date' => $schedule->start_date?->format('Y-m-d'),
                        'end_date' => $schedule->end_date?->format('Y-m-d'),
                        'status' => $schedule->status,
                        'template' => $schedule->template,
                        'compliance' => $schedule->template ? [
                            'clock_in_start' => $schedule->template->clock_in_start ?? self::EARLY_CLOCK_IN,
                            'clock_in_end' => $schedule->template->clock_in_end ?? self::LATE_CLOCK_OUT,
                            'clock_out_start' => $schedule->template->clock_out_start ?? self::WORK_END_TIME,
                            'clock_out_end' => $schedule->template->clock_out_end ?? self::LATE_CLOCK_OUT,
                            'work_start_time' => $schedule->template->work_start_time ?? self::WORK_START_TIME,
                            'work_end_time' => $schedule->template->work_end_time ?? self::WORK_END_TIME,
                            'late_threshold_minutes' => $schedule->template->late_threshold_minutes ?? 0,
                            'required_hours_per_day' => $schedule->template->required_hours_per_day ?? self::REQUIRED_HOURS,
                            'overtime_threshold_hours' => $schedule->template->overtime_threshold_hours ?? self::REQUIRED_HOURS,
                        ] : null,
                    ] : null,
                    'clocked_in' => $record ? (bool) $record->clock_in_time : false,
                    'clocked_out' => $record ? (bool) $record->clock_out_time : false,
                    'clock_in_time' => $record?->clock_in_time,
                    'clock_out_time' => $record?->clock_out_time,
                ],
                'message' => 'Today\'s attendance retrieved',
            ]);
        } else {
            // HR/Admin get all employees' today records
            $records = AttendanceLog::with('employee')
                ->where('date', $today)
                ->orderBy('employee_id')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $records,
                'message' => 'Today\'s attendance for all employees retrieved',
            ]);
        }
    }
}

