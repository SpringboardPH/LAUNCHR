<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'headcount' => 0,
                'attendance_rate' => 0,
                'pending_leaves' => 0,
                'payroll_status' => 'pending',
            ],
            'message' => 'Dashboard summary retrieved',
        ]);
    }
}
