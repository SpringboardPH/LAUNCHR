<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function store(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Leave request created',
        ], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Leave requests retrieved',
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Leave request retrieved',
        ]);
    }

    public function approve(Request $request, $id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Leave request approved',
        ]);
    }

    public function reject(Request $request, $id)
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Leave request rejected',
        ]);
    }
}
