<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeaveTypeController extends Controller
{
    private function makeUniqueCode(string $name, ?int $ignoreId = null): string
    {
        $baseCode = Str::slug($name, '_') ?: 'leave_type';
        $code = $baseCode;
        $suffix = 2;

        while (LeaveType::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('code', $code)
            ->exists()) {
            $code = $baseCode . '_' . $suffix;
            $suffix++;
        }

        return $code;
    }

    public function index(Request $request)
    {
        $query = LeaveType::query();

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $types = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $types,
            'message' => 'Leave types retrieved',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:leave_types,name',
            'description' => 'nullable|string|max:1000',
            'default_days' => 'required|integer|min:0|max:365',
            'requires_balance' => 'required|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $type = LeaveType::create([
            'code' => $this->makeUniqueCode($validated['name']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'default_days' => $validated['default_days'],
            'requires_balance' => $validated['requires_balance'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'data' => $type,
            'message' => 'Leave type created successfully',
        ], 201);
    }

    public function show(LeaveType $leaveType)
    {
        return response()->json([
            'success' => true,
            'data' => $leaveType,
            'message' => 'Leave type retrieved',
        ]);
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:leave_types,name,' . $leaveType->id,
            'description' => 'nullable|string|max:1000',
            'default_days' => 'required|integer|min:0|max:365',
            'requires_balance' => 'required|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $leaveType->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'default_days' => $validated['default_days'],
            'requires_balance' => $validated['requires_balance'],
            'is_active' => $request->boolean('is_active', $leaveType->is_active),
        ]);

        return response()->json([
            'success' => true,
            'data' => $leaveType->fresh(),
            'message' => 'Leave type updated successfully',
        ]);
    }

    public function destroy(LeaveType $leaveType)
    {
        if ($leaveType->leaveRequests()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Leave type cannot be deleted because it is already used in leave requests.',
            ], 409);
        }

        $leaveType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave type deleted successfully',
        ]);
    }
}