<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
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

        // Check if already clocked in today
        $today = Carbon::today();
        $existingLog = AttendanceLog::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if ($existingLog && $existingLog->clock_in_time) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has already clocked in today',
            ], 400);
        }

        // Create or update attendance log
        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            ['clock_in_time' => now()->toTimeString(), 'notes' => $request->notes]
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

        $log->update(['clock_out_time' => now()->toTimeString()]);

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

        // Filter by month if provided
        if ($month = $request->query('month')) {
            $query->whereYear('date', Carbon::now()->year)
                  ->whereMonth('date', $month);
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

