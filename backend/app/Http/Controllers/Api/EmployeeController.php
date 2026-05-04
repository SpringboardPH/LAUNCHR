<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query();

        // Search by name, ID, or email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw('CONCAT(first_name, " ", last_name) LIKE ?', ["%{$search}%"])
                  ->orWhere('employee_id', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by department
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }

        $employees = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees->items()),
            'pagination' => [
                'total' => $employees->total(),
                'count' => $employees->count(),
                'per_page' => $employees->perPage(),
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
            ],
            'message' => 'Employees retrieved successfully',
        ]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $data = $request->validated();

        $needsGeneratedEmployeeId = empty($data['employee_id']);
        if ($needsGeneratedEmployeeId) {
            $data['employee_id'] = 'TEMP-' . Str::uuid();
        }

        // Map basic_salary to salary if provided
        if (isset($data['basic_salary']) && !isset($data['salary'])) {
            $data['salary'] = $data['basic_salary'];
            unset($data['basic_salary']);
        }

        $employee = DB::transaction(function () use ($data, $request, $needsGeneratedEmployeeId) {
            $currentUser = $request->user();
            $isAdmin = $currentUser && $currentUser->role === 'admin';

            // 1. Create the system user first
            $user = User::create([
                'name' => $data['first_name'] . ' ' . $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($isAdmin && !empty($data['password']) ? $data['password'] : 'password123'),
                'role' => ($isAdmin && !empty($data['role'])) ? $data['role'] : 'employee',
            ]);

            // 2. Link the user to the employee data
            $data['user_id'] = $user->id;
            $employee = Employee::create($data);

            if ($needsGeneratedEmployeeId) {
                $employee->update([
                    'employee_id' => 'EMP' . str_pad((string) $employee->id, 3, '0', STR_PAD_LEFT),
                ]);
            }

            return $employee;
        });

        $message = 'Employee and user account created successfully.';
        if (!($request->user() && $request->user()->role === 'admin' && !empty($data['password']))) {
            $message .= ' Temporary password: password123';
        }

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => $message,
        ], 201);
    }

    public function show($id)
    {
        $employee = Employee::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee retrieved successfully',
        ]);
    }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $data = $request->validated();

        // Map basic_salary to salary if provided
        if (isset($data['basic_salary']) && !isset($data['salary'])) {
            $data['salary'] = $data['basic_salary'];
            unset($data['basic_salary']);
        }

        $employee->update($data);

        // Update linked user if exists
        if ($employee->user) {
            $currentUser = $request->user();
            $isAdmin = $currentUser && $currentUser->role === 'admin';

            $userUpdateData = [
                'name' => $employee->first_name . ' ' . $employee->last_name,
                'email' => $employee->email,
            ];

            // Only admin can change role or password
            if ($isAdmin) {
                if (!empty($data['role'])) {
                    $userUpdateData['role'] = $data['role'];
                }
                if (!empty($data['password'])) {
                    $userUpdateData['password'] = Hash::make($data['password']);
                }
            }

            $employee->user->update($userUpdateData);
        }

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee updated successfully',
        ]);
    }

    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);

        $currentUser = request()->user();
        if ($currentUser && $employee->user_id && $currentUser->id === $employee->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 403);
        }

        DB::transaction(function () use ($employee) {
            EmployeeSchedule::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            $employee->update(['status' => 'inactive']);
            $employee->delete(); // soft delete employee

            if ($employee->user_id) {
                $user = User::find($employee->user_id);
                if ($user) {
                    $user->delete(); // soft delete linked user
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Employee deactivated successfully',
        ]);
    }

    public function deactivate($id)
    {
        $employee = Employee::findOrFail($id);

        $currentUser = request()->user();
        if ($currentUser && $employee->user_id && $currentUser->id === $employee->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 403);
        }

        DB::transaction(function () use ($employee) {
            EmployeeSchedule::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            $employee->update(['status' => 'inactive']);
            $employee->delete(); // soft delete employee

            if ($employee->user_id) {
                $user = User::find($employee->user_id);
                if ($user) {
                    $user->delete(); // soft delete linked user
                }
            }
        });

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee deactivated successfully',
        ]);
    }

    public function hardDelete($id)
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        DB::transaction(function () use ($employee) {
            if ($employee->user_id) {
                $user = User::withTrashed()->find($employee->user_id);
                if ($user) {
                    $user->forceDelete();
                }
            }

            $employee->forceDelete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Employee permanently deleted',
        ]);
    }

    public function restore($id)
    {
        $employee = Employee::withTrashed()->findOrFail($id);
        DB::transaction(function () use ($employee) {
            $employee->restore();
            $employee->update(['status' => 'active']);

            if ($employee->user_id) {
                $user = User::withTrashed()->find($employee->user_id);
                if ($user && $user->trashed()) {
                    $user->restore();
                }
            }
        });
        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee restored successfully',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'bank_account_number' => 'nullable|string|min:10|max:12',
        ]);

        $employee->update($validated);

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Profile updated successfully',
        ]);
    }
}

