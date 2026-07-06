<?php

namespace App\Services;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SystemSettings;
use Carbon\Carbon;

/**
 * Leave balance/cycle logic, extracted from LeaveController so it can be reused by
 * both the leave endpoints and LaunchAssist's get_my_leave_balances tool.
 * Pure move — no behavior change.
 */
class LeaveService
{
    public static function cycle(Employee $employee): array
    {
        $hireDate = Carbon::parse($employee->hire_date ?? SystemClock::today());
        $virtualNow = SystemClock::now();
        $currentYear = $virtualNow->year;

        $cycleStart = $hireDate->copy()->year($currentYear);
        if ($cycleStart->gt($virtualNow)) {
            $cycleStart->subYear();
        }

        return [
            'start' => $cycleStart->copy()->startOfDay(),
            'end' => $cycleStart->copy()->addYear()->subDay()->endOfDay(),
        ];
    }

    public static function requestedDays(string $startDate, string $endDate): int
    {
        $leave = new LeaveRequest();
        $leave->start_date = $startDate;
        $leave->end_date = $endDate;

        return $leave->calculateDaysRequested();
    }

    public static function balanceSummary(Employee $employee): array
    {
        $cycle = self::cycle($employee);
        $leaveTypes = LeaveType::where('is_active', true)->orderBy('name')->get();
        $overrides = EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->get()
            ->keyBy('leave_type_id');

        // Filter out leave types that have an explicitly inactive override for this employee
        $leaveTypes = $leaveTypes->filter(function ($type) use ($overrides) {
            $override = $overrides->get($type->id);
            return !$override || $override->is_active;
        });

        $balances = [];

        foreach ($leaveTypes as $leaveType) {
            $override = $overrides->get($leaveType->id);
            $carryover = $override ? (int) $override->carryover_days : 0;
            $total = ($override ? (int) $override->allocated_days : (int) $leaveType->default_days) + $carryover;

            $used = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type', $leaveType->code)
                ->whereIn('status', ['approved', 'pending'])
                ->get()
                ->sum(function (LeaveRequest $leave) use ($cycle) {
                    $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
                    $leaveEnd = Carbon::parse($leave->end_date)->startOfDay();

                    if ($leaveEnd->lt($cycle['start']) || $leaveStart->gt($cycle['end'])) {
                        return 0;
                    }

                    $effectiveStart = $leaveStart->copy()->max($cycle['start']);
                    $effectiveEnd = $leaveEnd->copy()->min($cycle['end']);

                    if ($effectiveEnd->lt($effectiveStart)) {
                        return 0;
                    }

                    $clippedLeave = new LeaveRequest();
                    $clippedLeave->start_date = $effectiveStart->toDateString();
                    $clippedLeave->end_date = $effectiveEnd->toDateString();

                    return $clippedLeave->calculateDaysRequested();
                });

            $balances[$leaveType->code] = [
                'id' => $leaveType->id,
                'code' => $leaveType->code,
                'name' => $leaveType->name,
                'description' => $leaveType->description,
                'default_days' => (int) $leaveType->default_days,
                'requires_balance' => (bool) $leaveType->requires_balance,
                'total' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
                'override' => $override ? [
                    'id' => $override->id,
                    'allocated_days' => (int) $override->allocated_days,
                    'is_active' => (bool) $override->is_active,
                ] : null,
            ];
        }

        return [
            'balances' => $balances,
            'cycle' => [
                'start' => $cycle['start']->format('Y-m-d'),
                'end' => $cycle['end']->format('Y-m-d'),
            ],
            'policy' => [
                'include_weekends' => SystemSettings::get('leave_include_weekends', false),
            ],
            'leave_types' => $leaveTypes->values(),
        ];
    }
}
