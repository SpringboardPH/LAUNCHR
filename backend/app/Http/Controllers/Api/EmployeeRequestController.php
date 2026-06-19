<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeRequest;
use Illuminate\Http\Request;

class EmployeeRequestController extends Controller
{
    /**
     * Create a new employee request (any authenticated user).
     */
    public function store(Request $request)
    {
        $request->validate([
            'request_type' => 'required|in:overtime,half_day,undertime,concern,schedule_change,coe,other',
            'subject'      => 'required|string|max:255',
            'details'      => 'nullable|string',
            'meta'         => 'nullable|array',
        ]);

        $user     = $request->user();
        $employee = $user?->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $employeeRequest = EmployeeRequest::create([
            'employee_id'  => $employee->id,
            'request_type' => $request->request_type,
            'subject'      => $request->subject,
            'details'      => $request->details,
            'meta'         => $request->meta,
            'status'       => 'pending',
        ]);

        \App\Models\AuditLog::log(
            'REQUEST_CREATED',
            "Employee request created by {$employee->first_name} {$employee->last_name} ({$request->request_type}): {$request->subject}",
            $employeeRequest,
            null,
            [
                'employee_id'  => (int) $employee->id,
                'employee_name' => (string) ($employee->first_name . ' ' . $employee->last_name),
                'request_type' => (string) $request->request_type,
                'subject'      => (string) $request->subject,
                'status'       => 'pending',
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $employeeRequest,
            'message' => 'Request submitted successfully',
        ], 201);
    }

    /**
     * List employee requests with optional filters.
     */
    public function index(Request $request)
    {
        $query = EmployeeRequest::query();

        $isPersonal = $request->query('personal') === 'true';
        if (!$request->user()->isAdminOrHr() || $isPersonal) {
            $employee = $request->user()->employee;
            if ($employee) {
                $query->where('employee_id', $employee->id);
            } elseif ($isPersonal) {
                $query->whereRaw('1 = 0');
            }
        }

        // HR/Admin can filter by employee
        if ($employeeId = $request->query('employee_id')) {
            if ($request->user()->isAdminOrHr()) {
                $query->where('employee_id', $employeeId);
            }
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by request_type
        if ($requestType = $request->query('request_type')) {
            $query->where('request_type', $requestType);
        }

        $requests = $query->with('employee', 'approver')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success'    => true,
            'data'       => $requests->items(),
            'pagination' => [
                'total'        => $requests->total(),
                'count'        => $requests->count(),
                'per_page'     => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
            ],
            'message' => 'Requests retrieved',
        ]);
    }

    /**
     * Get a single employee request.
     */
    public function show(int $id)
    {
        $employeeRequest = EmployeeRequest::with('employee', 'approver')->findOrFail($id);

        if (!request()->user()->isAdminOrHr()) {
            $employee = request()->user()->employee;
            if (!$employee || $employeeRequest->employee_id !== $employee->id) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Unauthorized',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $employeeRequest,
            'message' => 'Request retrieved',
        ]);
    }

    /**
     * Approve an employee request (HR/Admin only).
     */
    public function approve(Request $request, int $id)
    {
        $request->validate([
            'response_notes' => 'nullable|string|max:1000',
        ]);

        $employeeRequest = EmployeeRequest::with('employee')->findOrFail($id);

        if ($employeeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Only pending requests can be approved',
            ], 422);
        }

        $employeeRequest->update([
            'status'         => 'approved',
            'approver_id'    => $request->user()->id,
            'response_notes' => $request->response_notes,
        ]);

        \App\Models\AuditLog::log(
            'REQUEST_APPROVED',
            "Employee request approved for {$employeeRequest->employee->first_name} {$employeeRequest->employee->last_name} ({$employeeRequest->request_type}): {$employeeRequest->subject}",
            $employeeRequest,
            ['status' => 'pending', 'approver_id' => null, 'response_notes' => null],
            [
                'status'         => 'approved',
                'approver_id'    => (int) $request->user()->id,
                'approver_name'  => (string) $request->user()->name,
                'employee_id'    => (int) $employeeRequest->employee_id,
                'employee_name'  => $employeeRequest->employee->first_name . ' ' . $employeeRequest->employee->last_name,
                'request_type'   => (string) $employeeRequest->request_type,
                'response_notes' => (string) ($request->response_notes ?? ''),
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $employeeRequest,
            'message' => 'Request approved',
        ]);
    }

    /**
     * Reject an employee request (HR/Admin only).
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'response_notes' => 'required|string|max:1000',
        ]);

        $employeeRequest = EmployeeRequest::with('employee')->findOrFail($id);

        if ($employeeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Only pending requests can be rejected',
            ], 422);
        }

        $employeeRequest->update([
            'status'         => 'rejected',
            'approver_id'    => $request->user()->id,
            'response_notes' => $request->response_notes,
        ]);

        \App\Models\AuditLog::log(
            'REQUEST_REJECTED',
            "Employee request rejected for {$employeeRequest->employee->first_name} {$employeeRequest->employee->last_name} ({$employeeRequest->request_type}): {$employeeRequest->subject}",
            $employeeRequest,
            ['status' => 'pending', 'approver_id' => null, 'response_notes' => null],
            [
                'status'         => 'rejected',
                'approver_id'    => (int) $request->user()->id,
                'approver_name'  => (string) $request->user()->name,
                'response_notes' => (string) $request->response_notes,
                'employee_id'    => (int) $employeeRequest->employee_id,
                'employee_name'  => $employeeRequest->employee->first_name . ' ' . $employeeRequest->employee->last_name,
                'request_type'   => (string) $employeeRequest->request_type,
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $employeeRequest,
            'message' => 'Request rejected',
        ]);
    }
}
