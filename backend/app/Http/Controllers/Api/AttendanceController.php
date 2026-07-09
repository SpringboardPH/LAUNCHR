<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\SystemClock;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\ScheduleTemplate;
use App\Models\CalendarEvent;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    private const AUTO_CLOCK_OUT_NOTE = '[System] Automatically clocked out due to missed departure window.';

    // Work hours configuration
    private const WORK_START_TIME = '09:00:00';
    private const WORK_END_TIME = '18:00:00';
    private const EARLY_CLOCK_IN = '08:45:00';
    private const LATE_CLOCK_OUT = '18:15:00';
    private const REQUIRED_HOURS = 9;

    private function getScheduleForDate(int|string|null $employeeId, Carbon $date)
    {
        return EmployeeSchedule::getForEmployeeOnDate($employeeId, $date);
    }

    private function parseTimeToMinutes(?string $time)
    {
        if (!$time) return 0;
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hour * 60 + $minute;
    }

    private function minutesToTime(int $minutes)
    {
        $normalized = ($minutes % 1440 + 1440) % 1440;
        $hours = intdiv($normalized, 60);
        $remainingMinutes = $normalized % 60;

        return sprintf('%02d:%02d:00', $hours, $remainingMinutes);
    }

    private function getDayRuleForDate(?EmployeeSchedule $schedule, Carbon $date)
    {
        if (!$schedule || !$schedule->template || !is_array($schedule->template->day_rules)) {
            return null;
        }

        foreach ($schedule->template->day_rules as $rule) {
            if ((int) ($rule['day'] ?? -1) === $date->dayOfWeek) {
                return $rule;
            }
        }

        return null;
    }

    private function applyGraceWindow(string $targetTime, string $graceType, int $graceMinutes = 15)
    {
        $targetMinutes = $this->parseTimeToMinutes($targetTime);
        $startMinutes = $targetMinutes;
        $endMinutes = $targetMinutes;

        if ($graceType === '-' || $graceType === '-/+') {
            $startMinutes -= (int) $graceMinutes;
        }

        if ($graceType === '+' || $graceType === '-/+') {
            $endMinutes += (int) $graceMinutes;
        }

        return [
            'start' => $this->minutesToTime($startMinutes),
            'end' => $this->minutesToTime($endMinutes),
        ];
    }

    private function calculateExpectedHoursFromRule(?string $clockIn, ?string $clockOut)
    {
        if (!$clockIn || !$clockOut) return self::REQUIRED_HOURS;
        $inMinutes = $this->parseTimeToMinutes($clockIn);
        $outMinutes = $this->parseTimeToMinutes($clockOut);
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }

        return max(0.5, round(($outMinutes - $inMinutes) / 60, 1));
    }

    private function resolveTemplateName(?AttendanceLog $log, ?EmployeeSchedule $schedule): ?string
    {
        return $log?->schedule_template_name
            ?? $schedule?->template?->name
            ?? null;
    }

    private function resolveTemplateContextForDate(?EmployeeSchedule $schedule, string $weekKey, array $weeklyTemplateHints, array &$templateCache): array
    {
        if ($schedule && $schedule->template) {
            return [$schedule->template, $schedule->template->name];
        }

        $hint = $weeklyTemplateHints[$weekKey] ?? null;
        if (!$hint) {
            return [null, $hint['name'] ?? null];
        }

        $template = null;
        if (!empty($hint['id'])) {
            $templateId = (int) $hint['id'];
            if (!array_key_exists($templateId, $templateCache)) {
                $templateCache[$templateId] = ScheduleTemplate::find($templateId);
            }
            $template = $templateCache[$templateId];
        }

        return [$template, $hint['name'] ?? $template?->name];
    }

    private function getScheduledEndTimeForDate(?EmployeeSchedule $schedule, ?array $dayRule, Carbon $date): ?Carbon
    {
        if ($dayRule && !empty($dayRule['clock_out'])) {
            $clockOut = Carbon::parse($dayRule['clock_out']);
            return $date->copy()->setTime($clockOut->hour, $clockOut->minute, 0);
        }

        $templateEnd = $schedule?->template?->clock_out_start
            ?? $schedule?->template?->work_end_time
            ?? $schedule?->template?->end_time;

        if (!$templateEnd) {
            return null;
        }

        $end = Carbon::parse($templateEnd);
        return $date->copy()->setTime($end->hour, $end->minute, 0);
    }

    private function maybeReopenAutoClockedOutLog(?AttendanceLog $log, ?EmployeeSchedule $schedule, ?array $dayRule, Carbon $today): void
    {
        if (!$log || !$log->clock_out_time || !$log->clock_out_notes) {
            return;
        }

        if (!str_contains($log->clock_out_notes ?? '', self::AUTO_CLOCK_OUT_NOTE)) {
            return;
        }

        $scheduledEnd = $this->getScheduledEndTimeForDate($schedule, $dayRule, $today);
        if (!$scheduledEnd) {
            return;
        }

        // If admin rewinds system time before shift end, restore "working" state.
        if (SystemClock::now()->lt($scheduledEnd)) {
            $log->clock_out_time = null;
            $log->status = 'working';
            $log->clock_out_notes = trim(str_replace(self::AUTO_CLOCK_OUT_NOTE, '', $log->clock_out_notes ?? ''));
            $log->save();
        }
    }

    private function getCalendarEventsForRange(Carbon $startDate, Carbon $endDate)
    {
        return CalendarEvent::whereBetween('event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with('type')
            ->get()
            ->keyBy(function($item) {
                return $item->event_date->format('Y-m-d');
            });
    }

    /**
     * Calculate work status based on clock times and template rules
     */
    private function calculateStatus(?string $clockInTime, ?string $clockOutTime, int|float|null $expectedHours = null, ?string $workStartTime = null, int $lateThreshold = 0, ?array $dayRule = null)
    {
        // Special case: Currently working
        if ($clockInTime && !$clockOutTime) {
            return 'working';
        }

        return AttendanceService::calculateStatus(
            $clockInTime,
            $clockOutTime,
            $expectedHours ?? self::REQUIRED_HOURS,
            $workStartTime ?? self::WORK_START_TIME,
            $dayRule
        );
    }

    /**
     * Clock in - record employee arrival
     */
    public function clockIn(Request $request)
    {
        $request->validate([
            'notes' => 'nullable|string|max:255',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $user = $request->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Only HR/Admin can clock in other employees',
            ], 403);
        }
        
        // Use provided employee_id or authenticated user's employee record
        $employee = $employeeId 
            ? Employee::findOrFail($employeeId)
            : $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found',
            ], 404);
        }

        $today = SystemClock::today();
        $schedule = $this->getScheduleForDate($employee->id, $today);
        $dayRule = $this->getDayRuleForDate($schedule, $today);
        $templateType = $schedule?->template?->type ?? 'fixed';

        // Check if employee is on leave today
        $onLeave = \App\Models\LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->exists();

        if ($onLeave) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clock in: Employee is on approved leave for today',
            ], 400);
        }

        // Flexi employees may clock in on a rest day (disabled day_rule) — it's paid
        // as rest-day work (1.30x) by payroll instead of being blocked outright.
        // Fixed employees cannot: a day not assigned to their schedule is simply not
        // scheduled — "rest day" pay for fixed only happens when HR manually sets that
        // status on a log they create/edit themselves.
        $isRestDay = false;
        if ($dayRule !== null) {
            $isRestDay = empty($dayRule['enabled']);
        } elseif ($schedule && $schedule->template) {
            $workDays = $schedule->template->work_days ?? [];
            $isRestDay = !in_array($today->dayOfWeek, $workDays, true);
        } else {
            $isRestDay = in_array($today->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true);
        }

        if ($templateType !== 'flexi' && $isRestDay) {
            return response()->json([
                'success' => false,
                'message' => 'Employee is not scheduled to work today',
            ], 400);
        }

        // Check if already clocked in today
        $existingLog = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->first();

        if ($existingLog && $existingLog->clock_in_time) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has already clocked in today',
            ], 400);
        }

        $clockInTime = SystemClock::timeString();

        // Flexi: no window restrictions — employee can clock in any time, including rest days
        if ($templateType === 'flexi') {
            $log = AttendanceLog::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $today->toDateString()],
                [
                    'clock_in_time'          => $clockInTime,
                    'clock_in_notes'         => $request->notes,
                    'status'                 => 'working',
                    'schedule_template_id'   => $schedule?->schedule_template_id,
                    'schedule_template_name' => $schedule?->template?->name,
                    'schedule_type'          => 'flexi',
                ]
            );

            \App\Models\AuditLog::log(
                'CLOCK_IN',
                "Employee {$employee->first_name} {$employee->last_name} clocked in at {$clockInTime}",
                $log,
                null,
                [
                    'employee_id'   => (int) $employee->id,
                    'employee_name' => (string) ($employee->first_name . ' ' . $employee->last_name),
                    'clock_in_time' => (string) $clockInTime,
                    'status'        => 'working',
                    'date'          => (string) $today->toDateString(),
                ]
            );

            return response()->json(['success' => true, 'data' => $log, 'message' => 'Clocked in successfully'], 201);
        }

        $clockInMinutes = $this->parseTimeToMinutes($clockInTime);
        $initialStatus = 'working';

        // Rest day: no scheduled window to enforce — clock in any time, like flexi.
        if (!$isRestDay) {
            $earlyAllowedMinutes = $this->parseTimeToMinutes(self::EARLY_CLOCK_IN);
            $clockInGraceEndMinutes = $this->parseTimeToMinutes(self::WORK_START_TIME);
            $latestClockInMinutes = $this->parseTimeToMinutes(self::LATE_CLOCK_OUT);

            if ($dayRule && !empty($dayRule['clock_in'])) {
                $targetInMinutes = $this->parseTimeToMinutes($dayRule['clock_in']);
                $earlyAllowedMinutes = $targetInMinutes - 60; // Allow 1 hour before scheduled time

                $graceEnabled = (bool) ($dayRule['grace_enabled'] ?? false);
                if ($graceEnabled) {
                    $clockInWindow = $this->applyGraceWindow(
                        $dayRule['clock_in'],
                        $dayRule['grace_type'] ?? '-/+',
                        $dayRule['grace_minutes'] ?? 15
                    );
                    $clockOutWindow = !empty($dayRule['clock_out'])
                        ? $this->applyGraceWindow(
                            $dayRule['clock_out'],
                            $dayRule['grace_type'] ?? '-/+',
                            $dayRule['grace_minutes'] ?? 15
                        )
                        : ['end' => self::LATE_CLOCK_OUT];

                    $clockInGraceEndMinutes = $this->parseTimeToMinutes($clockInWindow['end']);
                    $latestClockInMinutes = $this->parseTimeToMinutes($clockOutWindow['end']);
                } else {
                    $exactTime = $targetInMinutes;
                    $clockInGraceEndMinutes = $exactTime;
                    $latestClockInMinutes = !empty($dayRule['clock_out'])
                        ? $this->parseTimeToMinutes($dayRule['clock_out'])
                        : $this->parseTimeToMinutes(self::LATE_CLOCK_OUT);
                }
            } elseif ($schedule && $schedule->template) {
                $template = $schedule->template;
                $targetInMinutes = $this->parseTimeToMinutes($template->work_start_time ?? self::WORK_START_TIME);
                $earlyAllowedMinutes = $targetInMinutes - 60; // Allow 1 hour before scheduled time

                $clockInGraceEndMinutes = $this->parseTimeToMinutes($template->clock_in_end ?? $template->work_start_time ?? self::WORK_START_TIME);
                $latestClockInMinutes = $this->parseTimeToMinutes($template->clock_out_end ?? $template->work_end_time ?? self::LATE_CLOCK_OUT);
            }

            if ($clockInMinutes < $earlyAllowedMinutes) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clock in is earlier than the allowed schedule window',
                ], 400);
            }

            if ($clockInMinutes > $latestClockInMinutes) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clock in window has already closed for today',
                ], 400);
            }

            $initialStatus = $clockInMinutes > $clockInGraceEndMinutes ? 'late' : 'working';
        }

        // Create or update attendance log
        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today->toDateString()],
            [
                'clock_in_time' => $clockInTime,
                'clock_in_notes' => $request->notes,
                'status' => $initialStatus,
                // Snapshot schedule context so historical logs stay stable.
                'schedule_template_id'   => $schedule?->schedule_template_id,
                'schedule_template_name' => $schedule?->template?->name,
                'schedule_type'          => 'fixed',
            ]
        );

        // Log audit event for clock in
        \App\Models\AuditLog::log(
            'CLOCK_IN',
            "Employee {$employee->first_name} {$employee->last_name} clocked in at {$clockInTime}",
            $log,
            null,
            [
                'employee_id' => (int) $employee->id,
                'employee_name' => (string) ($employee->first_name . ' ' . $employee->last_name),
                'clock_in_time' => (string) $clockInTime,
                'status' => (string) $initialStatus,
                'date' => (string) $today->toDateString(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Clocked in successfully',
        ], 201);
    }

    /**
     * Clock out - record employee departure
     */
    public function clockOut(Request $request)
    {
        $request->validate([
            'notes' => 'nullable|string|max:255',
            'employee_id' => 'nullable|exists:employees,id',
            'confirm_early_clock_out' => 'nullable|boolean',
            'is_overtime' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $employeeId = $request->input('employee_id');
        
        // If employee_id provided, user must be HR/Admin
        if ($employeeId && !$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Only HR/Admin can clock out other employees',
            ], 403);
        }
        
        // Use provided employee_id or authenticated user's employee record
        $employee = $employeeId 
            ? Employee::findOrFail($employeeId)
            : $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found',
            ], 404);
        }

        // Get today's attendance log
        $today = SystemClock::today();
        $schedule = $this->getScheduleForDate($employee->id, $today);
        $dayRule = $this->getDayRuleForDate($schedule, $today);
        $log = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->first();

        if (!$log) {
            // Overnight shift: log was created yesterday, employee clocking out today
            $log = AttendanceLog::where('employee_id', $employee->id)
                ->whereDate('date', $today->copy()->subDay()->toDateString())
                ->whereNull('clock_out_time')
                ->first();
        }

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'No clock in record found for today',
            ], 400);
        }

        if ($log->clock_out_time) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has already clocked out today',
            ], 400);
        }

        $clockOutTime = SystemClock::timeString();

        // Flexi: no clock-out window restrictions
        $scheduleType = $log->schedule_type ?? $schedule?->template?->type ?? 'fixed';
        if ($scheduleType === 'flexi') {
            $requiredHours = $schedule?->template?->required_hours_per_day ?? 8;

            $inMin  = $this->parseTimeToMinutes($log->clock_in_time);
            $outMin = $this->parseTimeToMinutes($clockOutTime);
            if ($outMin < $inMin) $outMin += 1440;
            $hoursWorked   = ($outMin - $inMin) / 60;
            $overtimeHours = max(0, round($hoursWorked - $requiredHours, 2));
            $isRestDay     = !($dayRule['enabled'] ?? false);

            // OT requires HR approval before it's paid — record as completed and
            // auto-submit a request, whether it's a normal work day or a rest day.
            $flexiStatus = $overtimeHours > 0
                ? 'completed'
                : AttendanceService::calculateFlexiStatus($log->clock_in_time, $clockOutTime, $requiredHours);

            $log->clock_out_time  = $clockOutTime;
            $log->clock_out_notes = $request->notes;
            $log->status          = $flexiStatus;
            $log->save();

            if ($overtimeHours > 0) {
                \App\Models\EmployeeRequest::create([
                    'employee_id'  => $employee->id,
                    'request_type' => 'overtime',
                    'subject'      => ($isRestDay ? 'Rest Day Overtime on ' : 'Overtime on ') . $log->date->format('M d, Y'),
                    'details'      => "Clocked out at {$clockOutTime} after working " . round($hoursWorked, 2) . "h (required: {$requiredHours}h). Overtime: {$overtimeHours}h.",
                    'meta'         => [
                        'attendance_log_id' => $log->id,
                        'overtime_hours'    => $overtimeHours,
                        'hours_worked'      => round($hoursWorked, 2),
                        'required_hours'    => $requiredHours,
                        'date'              => $log->date->toDateString(),
                        'is_rest_day'       => $isRestDay,
                    ],
                    'status' => 'pending',
                ]);
            }

            \App\Models\AuditLog::log(
                'CLOCK_OUT',
                "Employee {$employee->first_name} {$employee->last_name} clocked out at {$clockOutTime}",
                $log,
                ['clock_out_time' => null, 'status' => $log->getOriginal('status')],
                [
                    'employee_id'    => (int) $employee->id,
                    'employee_name'  => (string) ($employee->first_name . ' ' . $employee->last_name),
                    'clock_in_time'  => (string) $log->clock_in_time,
                    'clock_out_time' => (string) $clockOutTime,
                    'status'         => (string) $log->status,
                    'date'           => (string) $log->date->toDateString(),
                ]
            );

            return response()->json(['success' => true, 'data' => $log, 'message' => 'Clocked out successfully']);
        }

        $clockOutMinutes = $this->parseTimeToMinutes($clockOutTime);
        $workEndTime = self::WORK_END_TIME;
        $earlyThresholdMinutes = $this->parseTimeToMinutes(self::WORK_END_TIME);
        $lateAllowedMinutes = $this->parseTimeToMinutes(self::LATE_CLOCK_OUT);

        // Rest day: no scheduled window to enforce — clock out any time, like flexi.
        $isRestDay = $dayRule !== null
            ? empty($dayRule['enabled'])
            : ($schedule && $schedule->template
                ? !in_array($today->dayOfWeek, $schedule->template->work_days ?? [], true)
                : false);

        if (!$isRestDay) {
            if ($dayRule && !empty($dayRule['clock_out'])) {
                $workEndTime = $dayRule['clock_out'];
                $targetOutMinutes = $this->parseTimeToMinutes($dayRule['clock_out']);
                $graceEnabled = (bool) ($dayRule['grace_enabled'] ?? false);

                if ($graceEnabled) {
                    $window = $this->applyGraceWindow(
                        $dayRule['clock_out'],
                        $dayRule['grace_type'] ?? '-/+',
                        $dayRule['grace_minutes'] ?? 15
                    );
                    $earlyThresholdMinutes = $this->parseTimeToMinutes($window['start']);
                    $lateAllowedMinutes = $this->parseTimeToMinutes($window['end']);
                } else {
                    $earlyThresholdMinutes = $targetOutMinutes;
                    $lateAllowedMinutes = $targetOutMinutes;
                }
            } elseif ($schedule && $schedule->template) {
                $template = $schedule->template;
                $workEndTime = $template->work_end_time ?? $template->end_time ?? $template->clock_out_start ?? self::WORK_END_TIME;

                // For templates, we use clock_out_start as the threshold if it exists,
                // otherwise use work_end_time.
                $earlyThresholdMinutes = $this->parseTimeToMinutes($template->clock_out_start ?? $workEndTime);
                $lateAllowedMinutes = $this->parseTimeToMinutes($template->clock_out_end ?? self::LATE_CLOCK_OUT);
            }
        }

        $confirmEarlyClockOut = (bool) $request->boolean('confirm_early_clock_out');

        if (!$isRestDay && $clockOutMinutes < $earlyThresholdMinutes && !$confirmEarlyClockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Clocking out now will count as incomplete hours. Confirm if you want to proceed.',
                'confirm_required' => true,
            ], 422);
        }

        // Update with clock out time and calculate status
        $log->clock_out_time = $clockOutTime;
        $log->clock_out_notes = $request->notes;

        // Rest day is a manual status HR sets on the log — don't let the automatic
        // status calculation below clobber it when HR pre-creates a log with only a
        // clock-in time and the employee later closes it out via the normal flow.
        if ($log->status !== 'rest_day') {
            $log->status = $this->calculateStatus(
                $log->clock_in_time,
                $clockOutTime,
                $dayRule && !empty($dayRule['clock_in']) && !empty($dayRule['clock_out'])
                    ? $this->calculateExpectedHoursFromRule($dayRule['clock_in'], $dayRule['clock_out'])
                    : ($schedule && $schedule->template ? $schedule->template->required_hours_per_day ?? $schedule->template->expected_hours_per_day : null),
                $dayRule && !empty($dayRule['clock_in'])
                    ? $dayRule['clock_in']
                    : ($schedule && $schedule->template ? $schedule->template->work_start_time ?? $schedule->template->start_time : null),
                $dayRule && isset($dayRule['late_threshold_minutes'])
                    ? $dayRule['late_threshold_minutes']
                    : ($schedule && $schedule->template ? $schedule->template->late_threshold_minutes : 0),
                $dayRule
            );
        }

        // HR/Admin can explicitly override the OT classification at clock-out
        if ($request->has('is_overtime')) {
            if ($request->boolean('is_overtime') && $log->status !== 'overtime') {
                $log->status = 'overtime';
            } elseif (!$request->boolean('is_overtime') && $log->status === 'overtime') {
                $log->status = 'completed';
            }
        }

        $log->save();

        // Log audit event for clock out
        \App\Models\AuditLog::log(
            'CLOCK_OUT',
            "Employee {$employee->first_name} {$employee->last_name} clocked out at {$clockOutTime}",
            $log,
            ['clock_out_time' => null, 'status' => $log->getOriginal('status')],
            [
                'employee_id' => (int) $employee->id,
                'employee_name' => (string) ($employee->first_name . ' ' . $employee->last_name),
                'clock_in_time' => (string) $log->clock_in_time,
                'clock_out_time' => (string) $clockOutTime,
                'status' => (string) $log->status,
                'date' => (string) $log->date->toDateString(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Clocked out successfully',
        ]);
    }

    /**
     * Get employee's attendance records
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isPersonal = $request->query('personal') === 'true';
        $includeAbsentees = $request->query('include_absentees') === 'true';
        $monthStr = $request->query('month');
        $employeeIdFilter = $request->query('employee_id');
        $employeeSearch = trim((string) $request->query('employee_search', ''));
        $statusFilter = $request->query('status');
        $dateFilter = $request->query('date');
        $groupFilter = $request->query('group');

        $query = AttendanceLog::with('employee');

        // Employees only see their own records, or if 'personal' flag is set
        if (!$user->isAdminOrHr() || $isPersonal) {
            $employee = $user->employee;
            if (!$employee) {
                return response()->json(['success' => true, 'data' => [], 'pagination' => [], 'message' => 'No employee record linked'], 200);
            }
            $query->where('employee_id', $employee->id);
            // If personal, we can just use the monthly method's logic if it's a report
            if ($monthStr && $includeAbsentees) {
                return $this->monthly($request, $employee->id);
            }
        }

        // Filter by date range or month
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->query('start_date'), $request->query('end_date')]);
        } elseif ($monthStr) {
            [$year, $monthNum] = explode('-', $monthStr);
            $query->whereYear('date', (int)$year)
                  ->whereMonth('date', (int)$monthNum);
        }

        if ($employeeIdFilter) {
            $query->where('employee_id', $employeeIdFilter);
        }

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($employeeSearch !== '') {
            $query->whereHas('employee', function ($q) use ($employeeSearch) {
                $q->where('first_name', 'like', '%' . $employeeSearch . '%')
                    ->orWhere('last_name', 'like', '%' . $employeeSearch . '%')
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%' . $employeeSearch . '%']);
            });
        }

        if ($groupFilter) {
            $query->whereHas('employee', fn($q) => $q->where('group', $groupFilter));
        }

        if ($dateFilter) {
            $query->whereDate('date', '=', $dateFilter);
        }

        // If HR/Admin wants a full report with absentees
        if ($user->isAdminOrHr() && !$isPersonal && ($monthStr || ($request->has('start_date') && $request->has('end_date'))) && $includeAbsentees) {
            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->query('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->query('end_date'))->endOfDay();
            } else {
                [$year, $monthNum] = explode('-', $monthStr);
                $startDate = Carbon::createFromDate($year, $monthNum, 1)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
            }
            $generationEndDate = $endDate->lt(SystemClock::today()) ? $endDate : SystemClock::today();

            $empQuery = Employee::where('status', 'active');
            if ($groupFilter) {
                $empQuery->where('group', $groupFilter);
            }
            $employees = $empQuery->get();
            $logs = $query->orderBy('date', 'desc')->get()->groupBy('employee_id');
            $events = $this->getCalendarEventsForRange($startDate, $endDate);

            // Get all approved leaves for these employees in this month
            $leaves = LeaveRequest::whereIn('employee_id', $employees->pluck('id'))
                ->where('status', 'approved')
                ->where(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate])
                      ->orWhere(function($q2) use ($startDate, $endDate) {
                          $q2->where('start_date', '<=', $startDate)
                             ->where('end_date', '>=', $endDate);
                      });
                })
                ->get()
                ->groupBy('employee_id');

            $allRecords = [];
            foreach ($employees as $employee) {
                $employeeLogs = $logs->get($employee->id, collect())->keyBy(function($item) {
                    return Carbon::parse($item->date)->format('Y-m-d');
                });
                $weeklyTemplateHints = [];
                foreach ($employeeLogs as $existingLog) {
                    $templateId = $existingLog->schedule_template_id;
                    $templateName = $existingLog->schedule_template_name;
                    if ($templateId || $templateName) {
                        $weekKey = Carbon::parse($existingLog->date)->format('o-W');
                        $weeklyTemplateHints[$weekKey] = [
                            'id' => $templateId,
                            'name' => $templateName,
                        ];
                    }
                }
                $templateCache = [];
                
                $employeeLeaves = $leaves->get($employee->id, collect());

                $employeeRecords = [];
                for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                    $dateStr = $date->format('Y-m-d');
                    $schedule = EmployeeSchedule::getForEmployeeOnDate($employee->id, $date);
                    $weekKey = $date->format('o-W');
                    [$resolvedTemplate, $templateName] = $this->resolveTemplateContextForDate($schedule, $weekKey, $weeklyTemplateHints, $templateCache);

                    if ($employeeLogs->has($dateStr)) {
                        $log = $employeeLogs->get($dateStr);
                        $log->template_name = $this->resolveTemplateName($log, $schedule);

                        // If it's a holiday, mark it in the metadata
                        if ($events->has($dateStr) && !$events->get($dateStr)->shouldCountAsAbsence()) {
                            $log->holiday_name = $events->get($dateStr)->title;
                        }

                        // Reconcile absent/on_leave with the current approved-leave state.
                        // mark-absent may have run before a leave was approved, or an old
                        // code path may have written on_leave directly to the DB.
                        if ($log->status === 'absent' || $log->status === 'on_leave') {
                            $coveredByLeave = $employeeLeaves->some(function ($leave) use ($date) {
                                return $date->between(
                                    Carbon::parse($leave->start_date)->startOfDay(),
                                    Carbon::parse($leave->end_date)->endOfDay()
                                );
                            });
                            if ($log->status === 'absent' && $coveredByLeave) {
                                $log->status = 'on_leave';
                            } elseif ($log->status === 'on_leave' && !$coveredByLeave) {
                                $log->status = 'absent';
                            }
                        }

                        $employeeRecords[] = $log;
                    } elseif ($date->lte($generationEndDate)) {
                        // Check for holiday first
                        if ($events->has($dateStr)) {
                            $event = $events->get($dateStr);
                            if (!$event->shouldCountAsAbsence()) {
                                $employeeRecords[] = [
                                    'employee_id' => $employee->id,
                                    'employee' => $employee,
                                    'date' => $dateStr,
                                    'clock_in_time' => null,
                                    'clock_out_time' => null,
                                    'status' => 'holiday',
                                    'template_name' => $templateName,
                                    'holiday_name' => $event->title,
                                ];
                                continue;
                            }
                        }

                        // Check for approved leave
                        $isOnLeave = $employeeLeaves->some(function($leave) use ($date) {
                            $lStart = Carbon::parse($leave->start_date)->startOfDay();
                            $lEnd = Carbon::parse($leave->end_date)->endOfDay();
                            return $date->between($lStart, $lEnd);
                        });

                        if ($isOnLeave) {
                            $employeeRecords[] = [
                                'employee_id' => $employee->id,
                                'employee' => $employee,
                                'date' => $dateStr,
                                'clock_in_time' => null,
                                'clock_out_time' => null,
                                'status' => 'on_leave',
                                'template_name' => $templateName,
                            ];
                            continue;
                        }

                        if ($resolvedTemplate) {
                            $template = $resolvedTemplate;
                            $dayOfWeek = $date->dayOfWeek;
                            $isWorkingDay = false;
                            if ($template->day_rules) {
                                foreach ($template->day_rules as $rule) {
                                    if ($rule['day'] == $dayOfWeek && $rule['enabled']) {
                                        $isWorkingDay = true;
                                        break;
                                    }
                                }
                            } else {
                                $workDays = $template->work_days ?? [];
                                if (in_array($dayOfWeek, $workDays)) { $isWorkingDay = true; }
                            }

                            if ($isWorkingDay) {
                                $employeeRecords[] = [
                                    'employee_id' => $employee->id,
                                    'employee' => $employee,
                                    'date' => $dateStr,
                                    'clock_in_time' => null,
                                    'clock_out_time' => null,
                                    'status' => 'absent',
                                    'template_name' => $templateName,
                                ];
                            } elseif ($template->type === 'flexi') {
                                $employeeRecords[] = [
                                    'employee_id' => $employee->id,
                                    'employee' => $employee,
                                    'date' => $dateStr,
                                    'clock_in_time' => null,
                                    'clock_out_time' => null,
                                    'status' => 'rest_day',
                                    'template_name' => $templateName,
                                ];
                            }
                        }
                    }
                }
                $this->applyHolidayAbsenceRule($employeeRecords);
                $allRecords = array_merge($allRecords, $employeeRecords);
            }

            // Sort by date desc
            usort($allRecords, function($a, $b) {
                return strcmp($b['date'] instanceof Carbon ? $b['date']->format('Y-m-d') : $b['date'], 
                              $a['date'] instanceof Carbon ? $a['date']->format('Y-m-d') : $a['date']);
            });

            // Apply optional filters for HR/Admin monthly report
            if ($employeeIdFilter || $employeeSearch !== '' || $statusFilter || $dateFilter) {
                $allRecords = array_values(array_filter($allRecords, function ($record) use ($employeeIdFilter, $employeeSearch, $statusFilter, $dateFilter) {
                    $recordEmployeeId = (int) data_get($record, 'employee_id');
                    $recordStatus = data_get($record, 'status');
                    $recordDate = data_get($record, 'date');
                    $recordDateStr = $recordDate instanceof Carbon ? $recordDate->format('Y-m-d') : (string) $recordDate;
                    $recordFirstName = (string) data_get($record, 'employee.first_name', '');
                    $recordLastName = (string) data_get($record, 'employee.last_name', '');
                    $recordFullName = trim($recordFirstName . ' ' . $recordLastName);
                    $searchNeedle = mb_strtolower($employeeSearch);
                    $searchHaystack = mb_strtolower($recordFullName);

                    if ($employeeIdFilter && $recordEmployeeId !== (int) $employeeIdFilter) {
                        return false;
                    }

                    if ($employeeSearch !== '' && !str_contains($searchHaystack, $searchNeedle)) {
                        return false;
                    }

                    if ($statusFilter && $recordStatus !== $statusFilter) {
                        return false;
                    }

                    if ($dateFilter && $recordDateStr !== $dateFilter) {
                        return false;
                    }

                    return true;
                }));
            }

            return response()->json([
                'success' => true,
                'data' => $allRecords,
                'message' => 'Attendance report retrieved',
            ]);
        }

        $records = $query->orderBy('date', 'desc')->paginate(15);

        // Populate template_name for each record
        foreach ($records->items() as $log) {
            $schedule = EmployeeSchedule::getForEmployeeOnDate($log->employee_id, $log->date);
            $log->template_name = $this->resolveTemplateName($log, $schedule);
        }

        return response()->json([
            'success' => true,
            'data' => $records->items(),
            'pagination' => [
                'total' => $records->total(),
                'count' => $records->count(),
                'per_page' => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ],
            'message' => 'Attendance records retrieved',
        ]);
    }

    /**
     * Get single attendance record
     */
    public function show(Request $request, int|string $id)
    {
        $employee = $request->user()->employee;
        $record = AttendanceLog::where('employee_id', $employee->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $record,
            'message' => 'Attendance record retrieved',
        ]);
    }

    /**
     * Get today's clock status - for employee or all employees for HR/Admin
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $today = SystemClock::today();
        $isPersonal = $request->query('personal') === 'true';

        if (!$user->isAdminOrHr() || $isPersonal) {
            $employee = $user->employee;

            if (!$employee) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'attendance' => null,
                        'schedule' => null,
                        'clocked_in' => false,
                        'clocked_out' => false,
                        'clock_in_time' => null,
                        'clock_out_time' => null,
                    ],
                    'message' => 'No employee record found for user',
                ]);
            }

            // Employees get their own today's record
            $schedule = $this->getScheduleForDate($employee->id, $today);
            $dayRule = $this->getDayRuleForDate($schedule, $today);
            $record = AttendanceLog::where('employee_id', $employee->id)
                ->whereDate('date', $today->toDateString())
                ->first();

            $this->maybeReopenAutoClockedOutLog($record, $schedule, $dayRule, $today);
            $record?->refresh();

            $onLeaveToday = \App\Models\LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today->toDateString())
                ->whereDate('end_date', '>=', $today->toDateString())
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $record,
                    'schedule' => $schedule ? [
                        'id' => $schedule->id,
                        'employee_id' => $schedule->employee_id,
                        'schedule_template_id' => $schedule->schedule_template_id,
                        'start_date' => $schedule->start_date?->format('Y-m-d'),
                        'end_date' => $schedule->end_date?->format('Y-m-d'),
                        'status' => $schedule->status,
                        'template' => $schedule->template,
                        'compliance' => $schedule->template ? [
                            'clock_in_start' => $schedule->template->clock_in_start ?? self::EARLY_CLOCK_IN,
                            'clock_in_end' => $schedule->template->clock_in_end ?? self::LATE_CLOCK_OUT,
                            'clock_out_start' => $schedule->template->clock_out_start ?? self::WORK_END_TIME,
                            'clock_out_end' => $schedule->template->clock_out_end ?? self::LATE_CLOCK_OUT,
                            'work_start_time' => $schedule->template->work_start_time ?? self::WORK_START_TIME,
                            'work_end_time' => $schedule->template->work_end_time ?? self::WORK_END_TIME,
                            'late_threshold_minutes' => $schedule->template->late_threshold_minutes ?? 0,
                            'required_hours_per_day' => $schedule->template->required_hours_per_day ?? self::REQUIRED_HOURS,
                            'overtime_threshold_hours' => $schedule->template->overtime_threshold_hours ?? self::REQUIRED_HOURS,
                        ] : null,
                    ] : null,
                    'clocked_in' => $record ? (bool) $record->clock_in_time : false,
                    'clocked_out' => $record ? (bool) $record->clock_out_time : false,
                    'clock_in_time' => $record?->clock_in_time,
                    'clock_out_time' => $record?->clock_out_time,
                    'on_leave' => $onLeaveToday,
                ],
                'message' => 'Today\'s attendance retrieved',
            ]);
        } else {
            // HR/Admin get all employees' today records
            $records = AttendanceLog::with('employee')
                ->where('date', $today)
                ->orderBy('employee_id')
                ->get();

            // Append synthetic on_leave entries for employees on approved leave with no log today.
            $loggedEmployeeIds = $records->pluck('employee_id')->all();
            \App\Models\LeaveRequest::with('employee')
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today->toDateString())
                ->whereDate('end_date', '>=', $today->toDateString())
                ->whereNotIn('employee_id', $loggedEmployeeIds)
                ->get()
                ->each(function ($leave) use ($records, $today) {
                    $records->push([
                        'id'               => null,
                        'employee_id'      => $leave->employee_id,
                        'employee'         => $leave->employee,
                        'date'             => $today->toDateString(),
                        'status'           => 'on_leave',
                        'clock_in_time'    => null,
                        'clock_out_time'   => null,
                        'clock_in_notes'   => null,
                        'clock_out_notes'  => null,
                    ]);
                });

            return response()->json([
                'success' => true,
                'data' => $records,
                'message' => 'Today\'s attendance for all employees retrieved',
            ]);
        }
    }
    /**
     * Get monthly attendance records for an employee
     */
    public function monthly(Request $request, int|string $employeeId)
    {
        $user = $request->user();
        
        // Security check: only own records unless Admin/HR
        if (!$user->isAdminOrHr() && $user->employee->id != $employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to attendance records',
            ], 403);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->query('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->query('end_date'))->endOfDay();
        } else {
            $monthStr = $request->query('month', SystemClock::now()->format('Y-m'));
            [$year, $monthNum] = explode('-', $monthStr);
            $startDate = Carbon::createFromDate($year, $monthNum, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        }
        
        // Don't generate absent records for future days
        $today = SystemClock::today();
        $generationEndDate = $endDate->lt($today) ? $endDate : $today;

        // Get actual logs
        $logs = AttendanceLog::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->keyBy(function($item) {
                return Carbon::parse($item->date)->format('Y-m-d');
            });
        $events = $this->getCalendarEventsForRange($startDate, $endDate);
        $weeklyTemplateHints = [];
        foreach ($logs as $existingLog) {
            $templateId = $existingLog->schedule_template_id;
            $templateName = $existingLog->schedule_template_name;
            if ($templateId || $templateName) {
                $weekKey = Carbon::parse($existingLog->date)->format('o-W');
                $weeklyTemplateHints[$weekKey] = [
                    'id' => $templateId,
                    'name' => $templateName,
                ];
            }
        }
        $templateCache = [];

        // Get approved leaves for the month
        $leaves = LeaveRequest::where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where(function($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            })
            ->get();

        $allDays = [];
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');
            $schedule = EmployeeSchedule::getForEmployeeOnDate($employeeId, $date);
            $weekKey = $date->format('o-W');
            [$resolvedTemplate, $templateName] = $this->resolveTemplateContextForDate($schedule, $weekKey, $weeklyTemplateHints, $templateCache);
            
            if ($logs->has($dateStr)) {
                $log = $logs->get($dateStr);
                $log->template_name = $this->resolveTemplateName($log, $schedule);

                if ($events->has($dateStr) && !$events->get($dateStr)->shouldCountAsAbsence()) {
                    $log->holiday_name = $events->get($dateStr)->title;
                }

                // Reconcile absent/on_leave with the current approved-leave state.
                if ($log->status === 'absent' || $log->status === 'on_leave') {
                    $coveredByLeave = $leaves->some(function ($leave) use ($date) {
                        return $date->between(
                            Carbon::parse($leave->start_date)->startOfDay(),
                            Carbon::parse($leave->end_date)->endOfDay()
                        );
                    });
                    if ($log->status === 'absent' && $coveredByLeave) {
                        $log->status = 'on_leave';
                    } elseif ($log->status === 'on_leave' && !$coveredByLeave) {
                        $log->status = 'absent';
                    }
                }

                $allDays[] = $log;
            } elseif ($date->lte($generationEndDate)) {
                // Check for holiday first
                if ($events->has($dateStr)) {
                    $event = $events->get($dateStr);
                    if (!$event->shouldCountAsAbsence()) {
                        $allDays[] = [
                            'employee_id' => $employeeId,
                            'date' => $dateStr,
                            'clock_in_time' => null,
                            'clock_out_time' => null,
                            'status' => 'holiday',
                            'template_name' => $templateName,
                            'holiday_name' => $event->title,
                        ];
                        continue;
                    }
                }

                // Check for approved leave first
                $isOnLeave = $leaves->some(function($leave) use ($date) {
                    $lStart = Carbon::parse($leave->start_date)->startOfDay();
                    $lEnd = Carbon::parse($leave->end_date)->endOfDay();
                    return $date->between($lStart, $lEnd);
                });

                if ($isOnLeave) {
                    $allDays[] = [
                        'employee_id' => $employeeId,
                        'date' => $dateStr,
                        'clock_in_time' => null,
                        'clock_out_time' => null,
                        'status' => 'on_leave',
                        'template_name' => $templateName,
                    ];
                    continue;
                }

                // Check if they were scheduled
                if ($resolvedTemplate) {
                     $template = $resolvedTemplate;
                     $dayOfWeek = $date->dayOfWeek; // 0 (Sun) to 6 (Sat)
                     
                     $isWorkingDay = false;
                     if ($template->day_rules) {
                         foreach ($template->day_rules as $rule) {
                             if ($rule['day'] == $dayOfWeek && $rule['enabled']) {
                                 $isWorkingDay = true;
                                 break;
                             }
                         }
                     } else {
                         $workDays = $template->work_days ?? [];
                         if (in_array($dayOfWeek, $workDays)) {
                             $isWorkingDay = true;
                         }
                     }

                     if ($isWorkingDay) {
                         // Only mark as absent if it's NOT today, or if it IS today but the shift end has passed
                         $shiftEnd = null;
                         $systemNow = SystemClock::now();
                         if ($date->isSameDay($today)) {
                             $shiftEndStr = $template->end_time ?? '18:00:00';
                             $shiftEnd = $date->copy()->setTimeFrom(Carbon::parse($shiftEndStr));
                         }

                         if ($date->lt($today) && (!$date->isSameDay($today) || (isset($shiftEnd) && $systemNow->isAfter($shiftEnd)))) {
                             $allDays[] = [
                                 'employee_id' => $employeeId,
                                 'date' => $dateStr,
                                 'clock_in_time' => null,
                                 'clock_out_time' => null,
                                 'status' => 'absent',
                                 'template_name' => $templateName,
                             ];
                         }
                     } elseif ($template->type === 'flexi') {
                         $allDays[] = [
                             'employee_id' => $employeeId,
                             'date' => $dateStr,
                             'clock_in_time' => null,
                             'clock_out_time' => null,
                             'status' => 'rest_day',
                             'template_name' => $templateName,
                         ];
                     }
                }
            }
        }

        $this->applyHolidayAbsenceRule($allDays);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $allDays
            ],
            'message' => 'Attendance records retrieved',
        ]);
    }

    public function update(Request $request, int|string $id)
    {
        Log::info("Attendance update request received for ID: " . $id, $request->all());
        $log = AttendanceLog::findOrFail($id);
        $oldValues = $log->toArray();

        $validated = $request->validate([
            'clock_in_time' => 'nullable|date_format:H:i:s',
            'clock_out_time' => 'nullable|date_format:H:i:s',
            'status' => 'nullable|string',
            'clock_in_notes' => 'nullable|string',
            'clock_out_notes' => 'nullable|string',
        ]);

        $log->update($validated);
        
        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Attendance log updated successfully',
        ]);
    }

    /**
     * Create a new attendance log (admin only)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only HR/Admin can create attendance logs.',
            ], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'clock_in_time' => 'nullable|date_format:H:i:s',
            'clock_out_time' => 'nullable|date_format:H:i:s',
            'status' => 'nullable|string',
            'clock_in_notes' => 'nullable|string',
            'clock_out_notes' => 'nullable|string',
        ]);

        // Check if log already exists for this employee on this date
        $existing = AttendanceLog::where('employee_id', $validated['employee_id'])
            ->whereDate('date', $validated['date'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'An attendance log already exists for this employee on this date.',
            ], 422);
        }

        $log = AttendanceLog::create($validated);

        // Populate template_name
        $schedule = EmployeeSchedule::getForEmployeeOnDate($log->employee_id, $log->date);
        $log->template_name = $this->resolveTemplateName($log, $schedule);

        return response()->json([
            'success' => true,
            'data' => $log,
            'message' => 'Attendance log created successfully',
        ]);
    }

    /**
     * Delete an attendance log
     */
    public function destroy(Request $request, int|string $id)
    {
        $log = AttendanceLog::findOrFail($id);
        
        $log->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Attendance log deleted successfully',
        ]);
    }

    /**
     * Manually trigger the mark-absent command for a specific date
     */
    public function bulkMarkAbsent(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdminOrHr()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only HR/Admin can trigger this action.',
            ], 403);
        }

        $request->validate([
            'start_date'  => 'required|date|before_or_equal:today',
            'end_date'    => 'required|date|before_or_equal:today|after_or_equal:start_date',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        $start      = $request->input('start_date');
        $end        = $request->input('end_date');
        $employeeId = $request->input('employee_id');

        try {
            $args = ['date' => $start, '--to' => $end];
            if ($employeeId) {
                $args['--employee'] = $employeeId;
            }
            Artisan::call('attendance:mark-absent', $args);

            $range = $start === $end ? $start : "{$start} to {$end}";
            $who   = $employeeId ? "employee #{$employeeId}" : 'all employees';
            return response()->json([
                'success' => true,
                'message' => "Successfully processed absentee marking for {$who} ({$range}).",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error running mark-absent command: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * If an employee is absent the day before or after a holiday,
     * that holiday also becomes absent for them (PH labor rule).
     * Operates on a flat array of day records for a single employee.
     */
    private function applyHolidayAbsenceRule(array &$days): void
    {
        $statusByDate = [];
        foreach ($days as $day) {
            $date = is_array($day) ? $day['date'] : Carbon::parse($day->date)->format('Y-m-d');
            $statusByDate[$date] = is_array($day) ? $day['status'] : $day->status;
        }

        foreach ($days as &$day) {
            $status = is_array($day) ? $day['status'] : $day->status;
            if ($status !== 'holiday') continue;

            $dateStr = is_array($day) ? $day['date'] : Carbon::parse($day->date)->format('Y-m-d');
            $prevDate = Carbon::parse($dateStr)->subDay()->format('Y-m-d');
            $nextDate = Carbon::parse($dateStr)->addDay()->format('Y-m-d');

            if (($statusByDate[$prevDate] ?? null) === 'absent' || ($statusByDate[$nextDate] ?? null) === 'absent') {
                if (is_array($day)) {
                    $day['status'] = 'absent';
                } else {
                    $day->status = 'absent';
                }
            }
        }
        unset($day);
    }

}
