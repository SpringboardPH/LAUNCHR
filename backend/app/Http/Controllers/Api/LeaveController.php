<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class LeaveController extends Controller
{
    private function calculateCycle(Employee $employee): array
    {
        $hireDate = Carbon::parse($employee->hire_date ?? now());
        $currentYear = now()->year;

        $cycleStart = $hireDate->copy()->year($currentYear);
        if ($cycleStart->isFuture()) {
            $cycleStart->subYear();
        }

        return [
            'start' => $cycleStart->copy()->startOfDay(),
            'end' => $cycleStart->copy()->addYear()->subDay()->endOfDay(),
        ];
    }

    private function calculateRequestedDays(string $startDate, string $endDate): int
    {
        $leave = new LeaveRequest();
        $leave->start_date = $startDate;
        $leave->end_date = $endDate;

        return $leave->calculateDaysRequested();
    }

    private function calculateBalanceSummary(Employee $employee): array
    {
        $cycle = $this->calculateCycle($employee);
        $leaveTypes = LeaveType::where('is_active', true)->orderBy('name')->get();
        $overrides = EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->get()
            ->keyBy('leave_type_id');

        // Filter out leave types that have an explicitly inactive override for this employee
        $leaveTypes = $leaveTypes->filter(function ($type) use ($overrides) {
            $override = $overrides->get($type->id);
            return !$override || $override->is_active;
        });

        $balances = [];

        foreach ($leaveTypes as $leaveType) {
            $override = $overrides->get($leaveType->id);
            $total = $override ? (int) $override->allocated_days : (int) $leaveType->default_days;

            $used = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type', $leaveType->code)
                ->whereBetween('start_date', [$cycle['start']->toDateString(), $cycle['end']->toDateString()])
                ->whereIn('status', ['approved', 'pending'])
                ->get()
                ->sum(fn (LeaveRequest $leave) => $leave->calculateDaysRequested());

            $balances[$leaveType->code] = [
                'id' => $leaveType->id,
                'code' => $leaveType->code,
                'name' => $leaveType->name,
                'description' => $leaveType->description,
                'default_days' => (int) $leaveType->default_days,
                'requires_balance' => (bool) $leaveType->requires_balance,
                'total' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
                'override' => $override ? [
                    'id' => $override->id,
                    'allocated_days' => (int) $override->allocated_days,
                    'is_active' => (bool) $override->is_active,
                ] : null,
            ];
        }

        return [
            'balances' => $balances,
            'cycle' => [
                'start' => $cycle['start']->format('Y-m-d'),
                'end' => $cycle['end']->format('Y-m-d'),
            ],
            'policy' => [
                'include_weekends' => SystemSettings::get('leave_include_weekends', false),
            ],
            'leave_types' => $leaveTypes,
        ];
    }

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
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $employee = $user?->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $daysRequested = $this->calculateRequestedDays($request->start_date, $request->end_date);

        if ($daysRequested < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Selected leave range does not include any countable work days.',
            ], 422);
        }

        $balanceSummary = $this->calculateBalanceSummary($employee);
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

        // Employee can only see their own leaves
        if (!$request->user()->isAdminOrHr()) {
            $employee = $request->user()->employee;
            $query->where('employee_id', $employee->id);
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
    public function show($id)
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
    public function approve(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);

        if ($leave->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be approved',
            ], 400);
        }

        $leave->update([
            'status' => 'approved',
            'approver_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Leave request approved',
        ]);
    }

    /**
     * Reject leave request (HR only)
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $leave = LeaveRequest::findOrFail($id);

        if ($leave->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be rejected',
            ], 400);
        }

        $leave->update([
            'status' => 'rejected',
            'approver_id' => $request->user()->id,
            'rejection_reason' => $request->rejection_reason,
        ]);

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

        $balanceData = $this->calculateBalanceSummary($employee);

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

