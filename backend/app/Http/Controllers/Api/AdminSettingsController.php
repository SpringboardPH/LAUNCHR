<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSettings;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index()
    {
        $settings = SystemSettings::all();
        
        return response()->json([
            'success' => true,
            'data' => $settings,
            'message' => 'System settings retrieved',
        ]);
    }

    public function show($key)
    {
        $setting = SystemSettings::where('key', $key)->first();
        
        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }

    public function update(Request $request, $key)
    {
        $request->validate([
            'value' => 'required',
            'description' => 'nullable|string',
            'type' => 'nullable|in:string,integer,decimal,boolean,array,json',
        ]);

        $type = $request->input('type', 'string');
        $setting = SystemSettings::set(
            $key,
            $request->input('value'),
            $request->input('description'),
            $type
        );

        return response()->json([
            'success' => true,
            'data' => $setting,
            'message' => 'Setting updated successfully',
        ]);
    }

    public function getDefaults()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'clock_in_start' => '08:45:00',
                'clock_in_end' => '18:15:00',
                'clock_out_start' => '18:00:00',
                'clock_out_end' => '18:15:00',
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 9,
                'work_days' => json_encode([1, 2, 3, 4, 5]), // Monday to Friday
                'overtime_threshold_hours' => 9,
                'leave_include_weekends' => false,
            ],
            'message' => 'Default work hour settings',
        ]);
    }

    public function initializeDefaults()
    {
        $defaults = [
            [
                'key' => 'clock_in_start',
                'value' => '08:45:00',
                'description' => 'Time when employees can start clocking in',
                'type' => 'string',
            ],
            [
                'key' => 'clock_in_end',
                'value' => '18:15:00',
                'description' => 'Time when clock in window closes',
                'type' => 'string',
            ],
            [
                'key' => 'clock_out_start',
                'value' => '18:00:00',
                'description' => 'Time when employees can start clocking out',
                'type' => 'string',
            ],
            [
                'key' => 'clock_out_end',
                'value' => '18:15:00',
                'description' => 'Time when clock out window closes',
                'type' => 'string',
            ],
            [
                'key' => 'work_start_time',
                'value' => '09:00:00',
                'description' => 'Official work day start time (used for late calculation)',
                'type' => 'string',
            ],
            [
                'key' => 'work_end_time',
                'value' => '18:00:00',
                'description' => 'Official work day end time',
                'type' => 'string',
            ],
            [
                'key' => 'late_threshold_minutes',
                'value' => '0',
                'description' => 'Minutes after work start time that marks an arrival as late',
                'type' => 'integer',
            ],
            [
                'key' => 'required_hours_per_day',
                'value' => '9',
                'description' => 'Minimum hours required per day',
                'type' => 'integer',
            ],
            [
                'key' => 'work_days',
                'value' => json_encode([1, 2, 3, 4, 5]),
                'description' => 'Work days (1=Mon, 2=Tue, ..., 7=Sun)',
                'type' => 'json',
            ],
            [
                'key' => 'overtime_threshold_hours',
                'value' => '9',
                'description' => 'Hours worked that counts as overtime',
                'type' => 'integer',
            ],
            [
                'key' => 'leave_include_weekends',
                'value' => 'false',
                'description' => 'Whether leave date ranges count Saturdays and Sundays',
                'type' => 'boolean',
            ],
        ];

        foreach ($defaults as $default) {
            SystemSettings::set(
                $default['key'],
                $default['value'],
                $default['description'],
                $default['type']
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Default settings initialized',
        ]);
    }
}
