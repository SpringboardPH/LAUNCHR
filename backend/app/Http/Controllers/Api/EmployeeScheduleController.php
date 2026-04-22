<?php

namespace App\Http\Controllers\Api;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EmployeeScheduleController extends Controller
{
    /**
     * Get all employee schedules (with filters)
     */
    public function index(Request $request)
    {
        $query = EmployeeSchedule::with(['employee', 'template']);

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('week_start')) {
            $query->where('start_date', '>=', $request->week_start);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $schedules = $query->orderBy('start_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    /**
     * Get current schedule for a specific employee
     */
    public function getCurrentForEmployee($employeeId)
    {
        $user = request()->user();
        // Security check: only own records unless Admin/HR
        if (!$user->isAdminOrHr() && (!$user->employee || $user->employee->id != $employeeId)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to schedule records',
            ], 403);
        }

        $schedule = EmployeeSchedule::getCurrentForEmployee($employeeId);

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    /**
     * Assign a schedule template to an employee for a specific week
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'schedule_template_id' => 'required|exists:schedule_templates,id',
            'start_date' => 'required|date|date_format:Y-m-d',
            'end_date' => 'required|date|date_format:Y-m-d|after:start_date',
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);

        if ((int) $startDate->diffInDays($endDate) !== 6) {
            return response()->json([
                'success' => false,
                'message' => 'Schedules must cover exactly seven days.',
            ], 422);
        }

        $existingSchedule = EmployeeSchedule::where('employee_id', $validated['employee_id'])
            ->whereDate('start_date', $validated['start_date'])
            ->whereDate('end_date', $validated['end_date'])
            ->first();

        if ($existingSchedule) {
            $existingSchedule->update([
                'schedule_template_id' => $validated['schedule_template_id'],
                'status' => 'active',
            ]);

            $existingSchedule->load(['employee', 'template']);

            return response()->json([
                'success' => true,
                'data' => $existingSchedule,
                'message' => 'Schedule updated successfully',
            ]);
        }

        // Check for overlapping schedules
        $overlap = EmployeeSchedule::where('employee_id', $validated['employee_id'])
            ->where('status', 'active')
            ->where(function ($query) use ($validated) {
                $query->whereDate('start_date', '<=', $validated['end_date'])
                    ->whereDate('end_date', '>=', $validated['start_date']);
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'success' => false,
                'message' => 'Employee already has a schedule for this period.',
            ], 409);
        }

        $schedule = EmployeeSchedule::create([
            ...$validated,
            'status' => 'active',
        ]);
        $schedule->load(['employee', 'template']);

        return response()->json([
            'success' => true,
            'data' => $schedule,
            'message' => 'Schedule assigned successfully',
        ], 201);
    }

    /**
     * Get a specific employee schedule
     */
    public function show(EmployeeSchedule $employeeSchedule)
    {
        $employeeSchedule->load(['employee', 'template']);

        return response()->json([
            'success' => true,
            'data' => $employeeSchedule,
        ]);
    }

    /**
     * Update an employee schedule assignment
     */
    public function update(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $validated = $request->validate([
            'schedule_template_id' => 'required|exists:schedule_templates,id',
            'start_date' => 'required|date|date_format:Y-m-d',
            'end_date' => 'required|date|date_format:Y-m-d|after:start_date',
            'status' => 'required|in:active,inactive,archived',
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);

        if ((int) $startDate->diffInDays($endDate) !== 6) {
            return response()->json([
                'success' => false,
                'message' => 'Schedules must cover exactly seven days.',
            ], 422);
        }

        // Check for overlapping schedules (excluding current one)
        $overlap = EmployeeSchedule::where('employee_id', $employeeSchedule->employee_id)
            ->where('id', '!=', $employeeSchedule->id)
            ->where('status', 'active')
            ->where(function ($query) use ($validated) {
                $query->whereDate('start_date', '<=', $validated['end_date'])
                    ->whereDate('end_date', '>=', $validated['start_date']);
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'success' => false,
                'message' => 'Employee already has a schedule for this period.',
            ], 409);
        }

        $dateRangeChanged = $employeeSchedule->start_date->format('Y-m-d') !== $validated['start_date']
            || $employeeSchedule->end_date->format('Y-m-d') !== $validated['end_date'];
        $isHistoricalSchedule = $employeeSchedule->end_date->lt(SystemClock::today());

        // Preserve historical schedule context only for past weeks.
        // Current/future week edits should still update in-place.
        if ($dateRangeChanged && $isHistoricalSchedule) {
            $newSchedule = EmployeeSchedule::create([
                'employee_id' => $employeeSchedule->employee_id,
                'schedule_template_id' => $validated['schedule_template_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => $validated['status'],
            ]);
            $newSchedule->load(['employee', 'template']);

            return response()->json([
                'success' => true,
                'data' => $newSchedule,
                'message' => 'New schedule week created to preserve historical records.',
            ]);
        }

        $employeeSchedule->update($validated);
        $employeeSchedule->load(['employee', 'template']);

        return response()->json([
            'success' => true,
            'data' => $employeeSchedule,
            'message' => 'Schedule updated successfully',
        ]);
    }

    /**
     * Delete/deactivate an employee schedule
     */
    public function destroy(EmployeeSchedule $employeeSchedule)
    {
        $employeeSchedule->update(['status' => 'archived']);

        return response()->json([
            'success' => true,
            'message' => 'Schedule deactivated successfully',
        ]);
    }
}
