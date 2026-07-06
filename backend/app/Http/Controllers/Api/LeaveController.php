<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class LeaveController extends Controller
{
    /**
     * Create leave request (employee)
     */
    public function store(Request $request)
    {
        $request->validate([
            'leave_type' => [
                'required',
                Rule::exists('leave_types', 'code')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'nullable|string|max:1000',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $user = $request->user();
        $employee = $user?->employee;

        if ($user->isAdminOrHr() && $request->filled('employee_id')) {
            $employee = \App\Models\Employee::findOrFail($request->employee_id);
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $systemToday = SystemClock::today()->startOfDay();
        if (Carbon::parse($request->start_date)->startOfDay()->lt($systemToday)) {
            return response()->json([
                'success' => false,
                'message' => 'Start date must be today or a future date based on system time.',
                'errors' => [
                    'start_date' => ['Start date must be today or a future date.'],
                ],
            ], 422);
        }

        $daysRequested = LeaveService::requestedDays($request->start_date, $request->end_date);

        if ($daysRequested < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Selected leave range does not include any countable work days.',
            ], 422);
        }

        $balanceSummary = LeaveService::balanceSummary($employee);
        $balances = $balanceSummary['balances'];
        $leaveType = $request->leave_type;
        $selectedType = $balances[$leaveType] ?? null;
        $availableBalance = $selectedType['remaining'] ?? null;

        if ($selectedType && $selectedType['requires_balance'] && $availableBalance !== null && $daysRequested > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request exceeds your available balance.',
                'errors' => [
                    'days_requested' => [
                        "Requested {$daysRequested} day(s), but only {$availableBalance} day(s) are available.",
                    ],
                ],
            ], 422);
        }

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => $request->leave_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'days_requested' => $daysRequested,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        // Log audit event for leave request creation
        \App\Models\AuditLog::log(
            'LEAVE_REQUEST_CREATED',
            "Leave request created by {$employee->first_name} {$employee->last_name} for {$daysRequested} day(s) ({$request->leave_type})",
            $leave,
            null,
            [
                'employee_id' => (int) $employee->id,
                'employee_name' => (string) ($employee->first_name . ' ' . $employee->last_name),
                'leave_type' => (string) $request->leave_type,
                'start_date' => (string) $request->start_date,
                'end_date' => (string) $request->end_date,
                'days_requested' => (int) $daysRequested,
                'reason' => (string) ($request->reason ?? ''),
                'status' => 'pending',
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Leave request created successfully',
        ], 201);
    }

    /**
     * Get employee's leave requests
     */
    public function index(Request $request)
    {
        $query = LeaveRequest::query();

        // Employee can only see their own leaves, or if 'personal' flag is set
        $isPersonal = $request->query('personal') === 'true';
        if (!$request->user()->isAdminOrHr() || $isPersonal) {
            $employee = $request->user()->employee;
            if ($employee) {
                $query->where('employee_id', $employee->id);
            } else if ($isPersonal) {
                // If personal requested but no employee record, return empty
                $query->whereRaw('1 = 0');
            }
        }

        // HR/Admin can filter by employee
        if ($employeeId = $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $leaves = $query->with('employee', 'leaveType')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $leaves->items(),
            'pagination' => [
                'total' => $leaves->total(),
                'count' => $leaves->count(),
                'per_page' => $leaves->perPage(),
                'current_page' => $leaves->currentPage(),
                'last_page' => $leaves->lastPage(),
            ],
            'message' => 'Leave requests retrieved',
        ]);
    }

    /**
     * Get single leave request
     */
    public function show(int $id)
    {
        $leave = LeaveRequest::findOrFail($id);

        // Employee can only view their own
        if (!request()->user()->isAdminOrHr()) {
            $employee = request()->user()->employee;
            if ($leave->employee_id !== $employee->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $leave->load('employee', 'approver'),
            'message' => 'Leave request retrieved',
        ]);
    }

    /**
     * Approve leave request (HR only)
     */
    public function approve(Request $request, int $id)
    {
        $leave = LeaveRequest::with('employee')->findOrFail($id);

        if ($leave->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be approved',
            ], 400);
        }

        // 3-day approval window check
        $submissionDate = $leave->created_at;
        $virtualNow = SystemClock::now();
        if ($submissionDate->diffInDays($virtualNow, false) > 3) {
            return response()->json([
                'success' => false,
                'message' => 'The 3-day approval window for this request has expired (Submitted: ' . $submissionDate->format('Y-m-d') . ').',
            ], 400);
        }

        // Re-validate balance at approval time to prevent concurrent over-approval
        $leaveTypeModel = LeaveType::where('code', $leave->leave_type)->first();
        if ($leaveTypeModel?->requires_balance) {
            $override = EmployeeLeaveBalance::where('employee_id', $leave->employee_id)
                ->where('leave_type_id', $leaveTypeModel->id)
                ->where('is_active', true)->first();
            $carryover = $override ? (int) $override->carryover_days : 0;
            $total = ($override ? (int) $override->allocated_days : (int) $leaveTypeModel->default_days) + $carryover;
            $alreadyApproved = LeaveRequest::where('employee_id', $leave->employee_id)
                ->where('leave_type', $leave->leave_type)
                ->where('status', 'approved')
                ->sum('days_requested');
            if ($alreadyApproved + $leave->days_requested > $total) {
                return response()->json([
                    'success' => false,
                    'message' => "Approving this leave would exceed the employee's remaining balance.",
                ], 422);
            }
        }

        $leave->update([
            'status' => 'approved',
            'approver_id' => $request->user()->id,
        ]);

        // Log audit event for leave request approval
        $leaveTypeCode = $leave->leave_type ?? 'N/A';
        \App\Models\AuditLog::log(
            'LEAVE_REQUEST_APPROVED',
            "Leave request approved for {$leave->employee->first_name} {$leave->employee->last_name} for {$leave->days_requested} day(s) ({$leaveTypeCode})",
            $leave,
            ['status' => 'pending', 'approver_id' => null],
            [
                'status' => 'approved',
                'approver_id' => (int) $request->user()->id,
                'approver_name' => (string) $request->user()->name,
                'employee_id' => (int) $leave->employee_id,
                'employee_name' => $leave->employee->first_name . ' ' . $leave->employee->last_name,
                'leave_type' => (string) $leaveTypeCode,
                'days_requested' => (int) $leave->days_requested,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Leave request approved',
        ]);
    }

    /**
     * Reject leave request (HR only)
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $leave = LeaveRequest::with('employee')->findOrFail($id);

        if ($leave->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be rejected',
            ], 400);
        }

        // 3-day approval window check
        $submissionDate = $leave->created_at;
        $virtualNow = SystemClock::now();
        if ($submissionDate->diffInDays($virtualNow, false) > 3) {
            return response()->json([
                'success' => false,
                'message' => 'The 3-day approval window for this request has expired (Submitted: ' . $submissionDate->format('Y-m-d') . ').',
            ], 400);
        }

        $leave->update([
            'status' => 'rejected',
            'approver_id' => $request->user()->id,
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Log audit event for leave request rejection
        $leaveTypeCode = $leave->leave_type ?? 'N/A';
        \App\Models\AuditLog::log(
            'LEAVE_REQUEST_REJECTED',
            "Leave request rejected for {$leave->employee->first_name} {$leave->employee->last_name} for {$leave->days_requested} day(s) ({$leaveTypeCode})",
            $leave,
            ['status' => 'pending', 'approver_id' => null, 'rejection_reason' => null],
            [
                'status' => 'rejected',
                'approver_id' => (int) $request->user()->id,
                'approver_name' => (string) $request->user()->name,
                'rejection_reason' => (string) ($request->rejection_reason ?? ''),
                'employee_id' => (int) $leave->employee_id,
                'employee_name' => $leave->employee->first_name . ' ' . $leave->employee->last_name,
                'leave_type' => (string) $leaveTypeCode,
                'days_requested' => (int) $leave->days_requested,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Leave request rejected',
        ]);
    }

    public function balance(Request $request)
    {
        $user = $request->user();
        $employee = $user?->employee;

        if ($user->isAdminOrHr() && $request->has('employee_id')) {
            $employee = \App\Models\Employee::findOrFail($request->employee_id);
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $balanceData = LeaveService::balanceSummary($employee);

        return response()->json([
            'success' => true,
            'data' => [
                'balances' => $balanceData['balances'],
                'cycle' => $balanceData['cycle'],
                'policy' => $balanceData['policy'],
                'leave_types' => $balanceData['leave_types'],
            ],
            'message' => 'Leave balance retrieved',
        ]);
    }
}

