<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // Work hours configuration
    private const WORK_START_TIME = '09:00:00';  // 9 AM
    private const WORK_END_TIME = '18:00:00';    // 6 PM
    private const EARLY_CLOCK_IN = '08:45:00';   // Can clock in 15 min before
    private const LATE_CLOCK_OUT = '18:15:00';   // Can clock out 15 min after
    private const REQUIRED_HOURS = 9;            // 9 hours per day

    /**
     * Calculate work status based on clock times
     */
    private function calculateStatus($clockInTime, $clockOutTime)
    {
        if (!$clockInTime) {
            return 'absent';
        }

        // Parse times
        [$inH, $inM, $inS] = sscanf($clockInTime, '%d:%d:%d');
        [$outH, $outM, $outS] = sscanf($clockOutTime, '%d:%d:%d');
        
        $inMinutes = $inH * 60 + $inM;
        $outMinutes = $outH * 60 + $outM;
        
        // Work start time is 9 AM (540 minutes)
        $workStartMinutes = 9 * 60;
        
        // Check if late (after 9:00 AM)
        $isLate = $inMinutes > $workStartMinutes;
        
        // Calculate hours worked
        $diffMinutes = $outMinutes - $inMinutes;
        $hoursWorked = $diffMinutes / 60;
        
        // Determine status
        if ($isLate) {
            return 'late';
        } elseif ($hoursWorked >= self::REQUIRED_HOURS) {
            return 'completed';
        } else {
            return 'incomplete';
        }
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

        $user = auth()->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !in_array($user->role, ['admin', 'hr'])) {
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
        
        // Check if today is a weekday (Monday = 1, Friday = 5)
        $dayOfWeek = $today->dayOfWeek;
        if ($dayOfWeek === Carbon::SATURDAY || $dayOfWeek === Carbon::SUNDAY) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clock in on weekends',
            ], 400);
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
        
        // Validate clock in time (8:45 AM to 6:15 PM)
        [$hour, $minute, $second] = sscanf($clockInTime, '%d:%d:%d');
        $clockInMinutes = $hour * 60 + $minute;
        $earlyAllowedMinutes = 8 * 60 + 45;  // 8:45 AM
        $lateAllowedMinutes = 18 * 60 + 15;   // 6:15 PM
        
        if ($clockInMinutes < $earlyAllowedMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Clock in is only allowed from 8:45 AM',
            ], 400);
        }
        
        if ($clockInMinutes > $lateAllowedMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Clock in only allowed until 6:15 PM',
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

        $user = auth()->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !in_array($user->role, ['admin', 'hr'])) {
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
        
        // Validate clock out time (must be after 6 PM, but allowed until 6:15 PM)
        [$hour, $minute, $second] = sscanf($clockOutTime, '%d:%d:%d');
        $clockOutMinutes = $hour * 60 + $minute;
        $workEndMinutes = 18 * 60;             // 6:00 PM
        $lateAllowedMinutes = 18 * 60 + 15;   // 6:15 PM
        
        if ($clockOutMinutes < $workEndMinutes) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clock out before 6:00 PM',
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
        $log->status = $this->calculateStatus($log->clock_in_time, $clockOutTime);
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
        $user = auth()->user();
        $query = AttendanceLog::with('employee');

        // Employees only see their own records
        if ($user->role === 'employee') {
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
    public function show($id)
    {
        $employee = auth()->user()->employee;
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
    public function today()
    {
        $user = auth()->user();
        $today = Carbon::today();

        if ($user->role === 'employee') {
            // Employees get their own today's record
            $record = AttendanceLog::where('employee_id', $user->employee->id)
                ->where('date', $today)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $record ?? [
                    'clocked_in' => false,
                    'clocked_out' => false,
                    'clock_in_time' => null,
                    'clock_out_time' => null,
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

