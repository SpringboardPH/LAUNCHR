<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function compute(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Payroll computed',
        ], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Payroll records retrieved',
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Payroll record retrieved',
        ]);
    }

    public function export($id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Payroll exported',
        ]);
    }
}
