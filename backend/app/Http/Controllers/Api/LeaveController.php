<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    /**
     * Create leave request (employee)
     */
    public function store(Request $request)
    {
        $request->validate([
            'leave_type' => 'required|in:vacation,sick,unpaid,maternity',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);

        $employee = auth()->user()->employee;

        // Calculate days requested (inclusive)
        $startDate = \Carbon\Carbon::parse($request->start_date);
        $endDate = \Carbon\Carbon::parse($request->end_date);
        $daysRequested = $endDate->diffInDays($startDate) + 1;

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
        if (!auth()->user()->isAdminOrHr()) {
            $employee = auth()->user()->employee;
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

        $leaves = $query->with('employee')
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
        if (!auth()->user()->isAdminOrHr()) {
            $employee = auth()->user()->employee;
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
            'approver_id' => auth()->id(),
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
            'approver_id' => auth()->id(),
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
        $employee = auth()->user()->employee;

        if (auth()->user()->isAdminOrHr() && $request->has('employee_id')) {
            $employee = \App\Models\Employee::findOrFail($request->employee_id);
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $hireDate = \Carbon\Carbon::parse($employee->hire_date ?? now());
        $currentYear = now()->year;

        // Calculate the current anniversary cycle start date
        $cycleStart = $hireDate->copy()->year($currentYear);
        if ($cycleStart->isFuture()) {
            $cycleStart->subYear();
        }
        $cycleEnd = $cycleStart->copy()->addYear()->subDay();

        // Define leave allocations (can be made configurable later)
        $allocations = [
            'vacation' => 15,
            'sick' => 10,
            'unpaid' => 0, // Unlimited
            'maternity' => 60,
        ];

        $balances = [];
        foreach ($allocations as $type => $total) {
            $used = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type', $type)
                ->whereBetween('start_date', [$cycleStart->format('Y-m-d'), $cycleEnd->format('Y-m-d')])
                ->whereIn('status', ['approved', 'pending'])
                ->get()
                ->sum(function ($leave) {
                    return $leave->start_date->diffInDays($leave->end_date) + 1;
                });

            $balances[$type] = [
                'total' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'balances' => $balances,
                'cycle' => [
                    'start' => $cycleStart->format('Y-m-d'),
                    'end' => $cycleEnd->format('Y-m-d'),
                ]
            ],
            'message' => 'Leave balance retrieved',
        ]);
    }
}

