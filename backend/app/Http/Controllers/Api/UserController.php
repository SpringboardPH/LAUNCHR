<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        $query = User::with(['employee' => function ($employeeQuery) {
            $employeeQuery->withTrashed();
        }]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('name')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'total' => $users->total(),
                'count' => $users->count(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
            'message' => 'Users retrieved successfully',
        ]);
    }

    /**
     * Display the soft-deleted users.
     */
    public function trashed(Request $request)
    {
        $query = User::onlyTrashed()->with(['employee' => function ($employeeQuery) {
            $employeeQuery->withTrashed();
        }]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('deleted_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'total' => $users->total(),
                'count' => $users->count(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
            'message' => 'Deactivated users retrieved successfully',
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,hr,employee',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => 'User created successfully',
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with('employee')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => 'User retrieved successfully',
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'role' => 'sometimes|in:admin,hr,employee',
        ]);

        $dataToUpdate = [
            'name' => $validated['name'] ?? $user->name,
            'email' => $validated['email'] ?? $user->email,
            'role' => $validated['role'] ?? $user->role,
        ];

        if (!empty($validated['password'])) {
            $dataToUpdate['password'] = Hash::make($validated['password']);
        }

        $user->update($dataToUpdate);

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => 'User updated successfully',
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $currentUser = request()->user();
        
        // Prevent users from deleting themselves
        if ($currentUser && $currentUser->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        DB::transaction(function () use ($user) {
            if ($user->employee) {
                EmployeeSchedule::where('employee_id', $user->employee->id)
                    ->where('status', 'active')
                    ->update(['status' => 'archived']);

                $user->employee->update(['status' => 'inactive']);
                $user->employee->delete();
            }

            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'User soft deleted successfully',
        ]);
    }

    /**
     * Permanently remove the specified user.
     */
    public function hardDelete($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $currentUser = request()->user();

        if ($currentUser && $currentUser->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        DB::transaction(function () use ($user) {
            $employee = Employee::withTrashed()->where('user_id', $user->id)->first();

            if ($employee) {
                $employee->forceDelete();
            }

            $user->forceDelete();
        });

        return response()->json([
            'success' => true,
            'message' => 'User permanently deleted',
        ]);
    }

    /**
     * Restore a soft-deleted user and linked employee record.
     */
    public function restore($id)
    {
        $user = User::withTrashed()->findOrFail($id);

        DB::transaction(function () use ($user) {
            $employee = Employee::withTrashed()->where('user_id', $user->id)->first();

            if (
                $employee &&
                str_ends_with((string) $user->email, '@archived.local') &&
                !User::withTrashed()
                    ->where('email', $employee->email)
                    ->where('id', '!=', $user->id)
                    ->exists()
            ) {
                $user->email = $employee->email;
                $user->save();
            }

            if ($employee) {
                $employee->restore();
                $employee->update(['status' => 'active']);
            }

            $user->restore();
        });

        return response()->json([
            'success' => true,
            'message' => 'User restored successfully',
        ]);
    }
}
