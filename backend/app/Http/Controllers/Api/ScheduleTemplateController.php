<?php

namespace App\Http\Controllers\Api;

use App\Models\ScheduleTemplate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ScheduleTemplateController extends Controller
{
    private const DEFAULT_GRACE_MINUTES = 15;

    private function parseTimeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hour * 60 + $minute;
    }

    private function minutesToTime(int $minutes): string
    {
        $normalized = ($minutes % 1440 + 1440) % 1440;
        $hours = intdiv($normalized, 60);
        $mins = $normalized % 60;

        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    private function applyGraceWindow(string $targetTime, string $graceType, int $graceMinutes, bool $graceEnabled = true): array
    {
        $targetMinutes = $this->parseTimeToMinutes($targetTime);
        $startMinutes = $targetMinutes;
        $endMinutes = $targetMinutes;

        if (!$graceEnabled) {
            return [
                'start' => $this->minutesToTime($startMinutes),
                'end' => $this->minutesToTime($endMinutes),
            ];
        }

        if ($graceType === '-' || $graceType === '-/+') {
            $startMinutes -= $graceMinutes;
        }

        if ($graceType === '+' || $graceType === '-/+') {
            $endMinutes += $graceMinutes;
        }

        return [
            'start' => $this->minutesToTime($startMinutes),
            'end' => $this->minutesToTime($endMinutes),
        ];
    }

    private function calculateHoursPerDay(string $clockIn, string $clockOut): int
    {
        $inMinutes = $this->parseTimeToMinutes($clockIn);
        $outMinutes = $this->parseTimeToMinutes($clockOut);
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }

        $hours = max(1, (int) round(($outMinutes - $inMinutes) / 60));

        return min(24, $hours);
    }

    private function derivePayloadFromDayRules(array $validated): array
    {
        $enabledRules = collect($validated['day_rules'])
            ->filter(fn ($rule) => !empty($rule['enabled']))
            ->values();

        if ($enabledRules->isEmpty()) {
            abort(response()->json([
                'success' => false,
                'message' => 'At least one active day is required.',
            ], 422));
        }

        foreach ($enabledRules as $rule) {
            if (empty($rule['clock_in']) || empty($rule['clock_out'])) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Active days require clock in and clock out times.',
                ], 422));
            }
        }

        $first = $enabledRules->first();
        $graceType = $first['grace_type'] ?? '-/+';
        $graceMinutes = (int) ($first['grace_minutes'] ?? self::DEFAULT_GRACE_MINUTES);
        $graceEnabled = (bool) ($first['grace_enabled'] ?? false);
        $clockInWindow = $this->applyGraceWindow($first['clock_in'], $graceType, $graceMinutes, $graceEnabled);
        $clockOutWindow = $this->applyGraceWindow($first['clock_out'], $graceType, $graceMinutes, $graceEnabled);
        $hoursPerDay = $this->calculateHoursPerDay($first['clock_in'], $first['clock_out']);

        $enabledDays = $enabledRules
            ->pluck('day')
            ->map(fn ($day) => (int) $day)
            ->sort()
            ->values()
            ->all();

        return [
            ...$validated,
            'work_days' => $enabledDays,
            'clock_in_start' => $clockInWindow['start'],
            'clock_in_end' => $clockInWindow['end'],
            'clock_out_start' => $clockOutWindow['start'],
            'clock_out_end' => $clockOutWindow['end'],
            'start_time' => $first['clock_in'],
            'end_time' => $first['clock_out'],
            'work_start_time' => $first['clock_in'],
            'work_end_time' => $first['clock_out'],
            'late_threshold_minutes' => 0,
            'required_hours_per_day' => $hoursPerDay,
            'overtime_threshold_hours' => $hoursPerDay,
            'expected_hours_per_day' => $hoursPerDay,
        ];
    }

    private function validateDayRulePayload(Request $request, ?int $templateId = null): array
    {
        return $request->validate([
            'name' => 'required|string|unique:schedule_templates,name' . ($templateId ? ',' . $templateId : ''),
            'description' => 'nullable|string',
            'day_rules' => 'required|array|size:7',
            'day_rules.*.day' => 'required|integer|between:0,6',
            'day_rules.*.enabled' => 'required|boolean',
            'day_rules.*.clock_in' => 'nullable|date_format:H:i:s',
            'day_rules.*.clock_out' => 'nullable|date_format:H:i:s',
            'day_rules.*.grace_enabled' => 'nullable|boolean',
            'day_rules.*.grace_type' => 'nullable|in:-,+,-/+',
            'day_rules.*.grace_minutes' => 'nullable|integer|min:0|max:180',
        ]);
    }

    /**
     * Get all schedule templates
     */
    public function index()
    {
        $templates = ScheduleTemplate::orderBy('name')->get();
        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Create a new schedule template
     */
    public function store(Request $request)
    {
        if ($request->has('day_rules')) {
            $validated = $this->validateDayRulePayload($request);
            $validated = $this->derivePayloadFromDayRules($validated);
        } else {
            $validated = $request->validate([
                'name' => 'required|string|unique:schedule_templates,name',
                'description' => 'nullable|string',
                'work_days' => 'required|array|min:1',
                'work_days.*' => 'integer|between:0,6',
                'clock_in_start' => 'nullable|date_format:H:i:s',
                'clock_in_end' => 'nullable|date_format:H:i:s',
                'clock_out_start' => 'nullable|date_format:H:i:s',
                'clock_out_end' => 'nullable|date_format:H:i:s',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
                'work_start_time' => 'required|date_format:H:i:s',
                'work_end_time' => 'required|date_format:H:i:s',
                'late_threshold_minutes' => 'required|integer|min:0|max:180',
                'required_hours_per_day' => 'required|integer|min:1|max:24',
                'overtime_threshold_hours' => 'required|integer|min:1|max:24',
                'expected_hours_per_day' => 'required|integer|min:1|max:24',
            ]);
        }

        $template = ScheduleTemplate::create($validated);

        return response()->json([
            'success' => true,
            'data' => $template,
            'message' => 'Schedule template created successfully',
        ], 201);
    }

    /**
     * Get a specific schedule template
     */
    public function show(ScheduleTemplate $scheduleTemplate)
    {
        return response()->json([
            'success' => true,
            'data' => $scheduleTemplate,
        ]);
    }

    /**
     * Update a schedule template
     */
    public function update(Request $request, ScheduleTemplate $scheduleTemplate)
    {
        if ($request->has('day_rules')) {
            $validated = $this->validateDayRulePayload($request, $scheduleTemplate->id);
            $validated = $this->derivePayloadFromDayRules($validated);
        } else {
            $validated = $request->validate([
                'name' => 'required|string|unique:schedule_templates,name,' . $scheduleTemplate->id,
                'description' => 'nullable|string',
                'work_days' => 'required|array|min:1',
                'work_days.*' => 'integer|between:0,6',
                'clock_in_start' => 'nullable|date_format:H:i:s',
                'clock_in_end' => 'nullable|date_format:H:i:s',
                'clock_out_start' => 'nullable|date_format:H:i:s',
                'clock_out_end' => 'nullable|date_format:H:i:s',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
                'work_start_time' => 'required|date_format:H:i:s',
                'work_end_time' => 'required|date_format:H:i:s',
                'late_threshold_minutes' => 'required|integer|min:0|max:180',
                'required_hours_per_day' => 'required|integer|min:1|max:24',
                'overtime_threshold_hours' => 'required|integer|min:1|max:24',
                'expected_hours_per_day' => 'required|integer|min:1|max:24',
            ]);
        }

        $scheduleTemplate->update($validated);

        return response()->json([
            'success' => true,
            'data' => $scheduleTemplate,
            'message' => 'Schedule template updated successfully',
        ]);
    }

    /**
     * Delete a schedule template
     */
    public function destroy(ScheduleTemplate $scheduleTemplate)
    {
        // Check if template is in use
        if ($scheduleTemplate->employeeSchedules()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete template in use. Please reassign employees first.',
            ], 409);
        }

        $scheduleTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Schedule template deleted successfully',
        ]);
    }
}
