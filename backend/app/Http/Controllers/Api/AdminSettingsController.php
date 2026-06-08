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

    public function show(string $key)
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

    public function update(Request $request, string $key)
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
                'key' => 'absent_marking_time',
                'value' => '00:00',
                'description' => 'Time when the system automatically marks employees as absent (HH:MM)',
                'type' => 'string',
            ],
            [
                'key' => 'leave_include_weekends',
                'value' => 'false',
                'description' => 'Whether leave date ranges count Saturdays and Sundays',
                'type' => 'boolean',
            ],
            [
                'key' => 'theme_color',
                'value' => 'sienna',
                'description' => 'System theme color preset (green, blue, purple, sienna, rose)',
                'type' => 'string',
            ],
            [
                'key' => 'system_name',
                'value' => 'LAUNCHR',
                'description' => 'The name of the system displayed in the sidebar',
                'type' => 'string',
            ],
            [
                'key' => 'system_logo',
                'value' => 'launchr_black.svg',
                'description' => 'The logo used by the system',
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

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $image = $request->file('logo');
            $name = 'system_logo_' . time() . '.' . $image->getClientOriginalExtension();
            $destinationPath = public_path('/');
            $image->move($destinationPath, $name);

            SystemSettings::set('system_logo', $name, 'The logo used by the system', 'string');

            return response()->json([
                'success' => true,
                'data' => $name,
                'message' => 'Logo uploaded successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No logo file provided',
        ], 400);
    }

    public function uploadPayrollTemplate(Request $request)
    {
        $request->validate([
            'template' => 'required|file|mimes:xlsx,xls|max:5120',
        ]);

        if ($request->hasFile('template')) {
            $file = $request->file('template');
            $name = 'payroll_template_' . time() . '.' . $file->getClientOriginalExtension();
            $destinationPath = public_path('/');
            $file->move($destinationPath, $name);

            SystemSettings::set('payroll_template', $name, 'The Excel template used for payroll generation', 'string');

            return response()->json([
                'success' => true,
                'data' => $name,
                'message' => 'Payroll template uploaded successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No template file provided',
        ], 400);
    }

    public function getTemplate()
    {
        $filename = SystemSettings::get('payroll_template', 'payrolltemplate.xlsx');
        $path = public_path($filename);
        if (!file_exists($path)) {
            abort(404, 'Template not found');
        }

        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if (str_ends_with($filename, '.xls')) {
            $mimeType = 'application/vnd.ms-excel';
        }

        return response()->download($path, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function listLogos()
    {
        $directory = public_path('/');
        $files = scandir($directory);
        
        $logos = array_filter($files, function($file) {
            return str_starts_with($file, 'system_logo_') || 
                   in_array($file, ['launchr_black.svg', 'launchr_logo.svg', 'launchr_white.svg', 'synctalents.png', 'sblogo.svg', 'stlogo.svg', 'springboard-logo.svg']);
        });

        return response()->json([
            'success' => true,
            'data' => array_values($logos),
            'message' => 'Available logos retrieved',
        ]);
    }

    public function getSystemConfig()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'theme_color' => SystemSettings::get('theme_color', 'sienna'),
                'system_name' => SystemSettings::get('system_name', 'LAUNCHR'),
                'system_logo' => SystemSettings::get('system_logo', 'launchr_black.svg'),
            ],
            'message' => 'System configuration retrieved',
        ]);
    }

    public function getThemeColor()
    {
        $themeColor = SystemSettings::get('theme_color', 'sienna');
        
        return response()->json([
            'success' => true,
            'data' => [
                'theme_color' => $themeColor,
            ],
            'message' => 'System theme color retrieved',
        ]);
    }
}
