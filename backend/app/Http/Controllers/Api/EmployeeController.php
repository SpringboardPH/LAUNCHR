<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Employees retrieved successfully',
        ]);
    }

    public function store(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Employee created successfully',
        ], 201);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Employee retrieved successfully',
        ]);
    }

    public function update(Request $request, $id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Employee updated successfully',
        ]);
    }

    public function destroy($id)
    {
        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully',
        ]);
    }
}
