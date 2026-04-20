<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;

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

        // Auto-generate employee_id if not provided
        if (empty($data['employee_id'])) {
            $lastEmployee = Employee::latest('id')->first();
            // Extract numeric part from last employee_id (e.g., "EMP001" -> 1)
            $lastId = 0;
            if ($lastEmployee && preg_match('/\d+/', $lastEmployee->employee_id, $matches)) {
                $lastId = (int) $matches[0];
            }
            $nextId = $lastId + 1;
            // Use 3-digit padding to match seeder format (EMP001, EMP002, etc.)
            $data['employee_id'] = 'EMP' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
        }

        // Map basic_salary to salary if provided
        if (isset($data['basic_salary']) && !isset($data['salary'])) {
            $data['salary'] = $data['basic_salary'];
            unset($data['basic_salary']);
        }

        $employee = Employee::create($data);

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee created successfully',
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

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee updated successfully',
        ]);
    }

    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);
        $employee->delete(); // soft delete

        return response()->json([
            'success' => true,
            'message' => 'Employee deactivated successfully',
        ]);
    }

    public function deactivate($id)
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['status' => 'inactive']);
        $employee->delete(); // soft delete

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
            'message' => 'Employee deactivated successfully',
        ]);
    }
}

