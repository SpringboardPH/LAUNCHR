<?php

namespace App\Http\Controllers\Api;

use App\Models\ScheduleTemplate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ScheduleTemplateController extends Controller
{
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
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'work_start_time' => 'required|date_format:H:i:s',
            'work_end_time' => 'required|date_format:H:i:s',
            'late_threshold_minutes' => 'required|integer|min:0|max:180',
            'required_hours_per_day' => 'required|integer|min:1|max:24',
            'overtime_threshold_hours' => 'required|integer|min:1|max:24',
            'expected_hours_per_day' => 'required|integer|min:1|max:24',
        ]);

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
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'work_start_time' => 'required|date_format:H:i:s',
            'work_end_time' => 'required|date_format:H:i:s',
            'late_threshold_minutes' => 'required|integer|min:0|max:180',
            'required_hours_per_day' => 'required|integer|min:1|max:24',
            'overtime_threshold_hours' => 'required|integer|min:1|max:24',
            'expected_hours_per_day' => 'required|integer|min:1|max:24',
        ]);

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
