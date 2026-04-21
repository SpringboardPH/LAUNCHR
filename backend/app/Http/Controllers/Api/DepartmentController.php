<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::withTrashed()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $departments,
            'message' => 'Departments retrieved',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:departments,name',
            'description' => 'nullable|string',
        ]);

        $department = Department::create($validated);

        return response()->json([
            'success' => true,
            'data' => $department,
            'message' => 'Department created successfully',
        ], 201);
    }

    public function show($id)
    {
        $department = Department::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $department,
        ]);
    }

    public function update(Request $request, $id)
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|unique:departments,name,'.$id,
            'description' => 'nullable|string',
        ]);

        $department->update($validated);

        return response()->json([
            'success' => true,
            'data' => $department,
            'message' => 'Department updated successfully',
        ]);
    }

    public function destroy($id)
    {
        $department = Department::findOrFail($id);
        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully',
        ]);
    }

    public function hardDelete($id)
    {
        $department = Department::withTrashed()->findOrFail($id);
        $department->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Department permanently deleted',
        ]);
    }

    public function restore($id)
    {
        $department = Department::withTrashed()->findOrFail($id);
        $department->restore();

        return response()->json([
            'success' => true,
            'data' => $department,
            'message' => 'Department restored successfully',
        ]);
    }
}
