<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\SystemClock;
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

    /**
     * Return the virtual system clock (date + time) so the frontend
     * can display and validate against the admin-configured time.
     *
     * We intentionally return datetime WITHOUT a timezone offset so the
     * browser treats it as local time — matching what the admin typed in.
     * (If we returned an ISO string with +00:00, the browser would shift it
     * by the user's UTC offset, showing the wrong time.)
     */
    public function systemClock()
    {
        $now = SystemClock::now();
        return response()->json([
            'success' => true,
            'data' => [
                // No timezone suffix — browser will parse as local time
                'datetime'  => $now->format('Y-m-d\TH:i:s'),
                'date'      => $now->toDateString(),
                'time'      => $now->format('H:i:s'),
                'time_hhmm' => $now->format('H:i'),
                'day_of_week' => $now->dayOfWeek,  // 0 = Sunday
                'minutes_since_midnight' => $now->hour * 60 + $now->minute,
            ],
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
            [
                'key' => 'theme_color',
                'value' => 'green',
                'description' => 'System theme color preset (green, blue, purple, indigo, rose)',
                'type' => 'string',
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

    public function getThemeColor()
    {
        $themeColor = SystemSettings::get('theme_color', 'green');
        
        return response()->json([
            'success' => true,
            'data' => [
                'theme_color' => $themeColor,
            ],
            'message' => 'System theme color retrieved',
        ]);
    }
}
