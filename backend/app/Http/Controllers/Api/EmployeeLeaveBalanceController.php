<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class EmployeeLeaveBalanceController extends Controller
{
    private function calculateCycle(Employee $employee): array
    {
        $hireDate = Carbon::parse($employee->hire_date ?? now());
        $currentYear = now()->year;

        $cycleStart = $hireDate->copy()->year($currentYear);
        if ($cycleStart->isFuture()) {
            $cycleStart->subYear();
        }

        return [
            'start' => $cycleStart->copy()->startOfDay(),
            'end' => $cycleStart->copy()->addYear()->subDay()->endOfDay(),
        ];
    }

    private function usedDays(Employee $employee, LeaveType $leaveType, Carbon $cycleStart, Carbon $cycleEnd): int
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type', $leaveType->code)
            ->whereBetween('start_date', [$cycleStart->toDateString(), $cycleEnd->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->get()
            ->sum(fn (LeaveRequest $leave) => $leave->calculateDaysRequested());
    }

    public function show(Employee $employee)
    {
        $cycle = $this->calculateCycle($employee);
        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $overrides = EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->get()
            ->keyBy('leave_type_id');

        $balances = $types->map(function (LeaveType $leaveType) use ($employee, $cycle, $overrides) {
            $override = $overrides->get($leaveType->id);
            $effectiveDays = $override ? (int) $override->allocated_days : (int) $leaveType->default_days;
            $usedDays = $this->usedDays($employee, $leaveType, $cycle['start'], $cycle['end']);

            return [
                'leave_type' => [
                    'id' => $leaveType->id,
                    'code' => $leaveType->code,
                    'name' => $leaveType->name,
                    'description' => $leaveType->description,
                    'default_days' => (int) $leaveType->default_days,
                    'requires_balance' => (bool) $leaveType->requires_balance,
                    'is_active' => (bool) $leaveType->is_active,
                ],
                'override' => $override ? [
                    'id' => $override->id,
                    'allocated_days' => (int) $override->allocated_days,
                    'is_active' => (bool) $override->is_active,
                    'notes' => $override->notes,
                ] : null,
                'total' => $effectiveDays,
                'used' => $usedDays,
                'remaining' => max(0, $effectiveDays - $usedDays),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                ],
                'balances' => $balances,
                'cycle' => [
                    'start' => $cycle['start']->toDateString(),
                    'end' => $cycle['end']->toDateString(),
                ],
                'leave_types' => $types,
            ],
            'message' => 'Employee leave balances retrieved',
        ]);
    }

    public function upsert(Request $request, Employee $employee, LeaveType $leaveType)
    {
        $validated = $request->validate([
            'allocated_days' => 'required|integer|min:0|max:365',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $balance = EmployeeLeaveBalance::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
            ],
            [
                'allocated_days' => $validated['allocated_days'],
                'is_active' => $request->boolean('is_active', true),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $balance->load('leaveType'),
            'message' => 'Employee leave balance updated successfully',
        ]);
    }

    public function destroy(Employee $employee, LeaveType $leaveType)
    {
        $balance = EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->firstOrFail();

        $balance->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Employee leave balance reverted to default successfully',
        ]);
    }
}