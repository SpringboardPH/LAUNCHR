<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Clock in recorded',
        ], 201);
    }

    public function clockOut(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Clock out recorded',
        ], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Attendance records retrieved',
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Attendance record retrieved',
        ]);
    }
}
