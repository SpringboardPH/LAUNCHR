<?php

namespace Database\Seeders;

use App\Models\SystemSettings;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
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
            [
                'key' => 'sss_contribution_table',
                'value' => json_encode([
                    ['min' => 0, 'max' => 4249.99, 'ee' => 180, 'er' => 400],
                    ['min' => 4250, 'max' => 4749.99, 'ee' => 202.50, 'er' => 450],
                    ['min' => 4750, 'max' => 5249.99, 'ee' => 225, 'er' => 500],
                    ['min' => 5250, 'max' => 5749.99, 'ee' => 247.50, 'er' => 550],
                    ['min' => 29750, 'max' => null, 'ee' => 1350, 'er' => 3000],
                ]),
                'description' => 'SSS Employee and Employer Contribution Table',
                'type' => 'json',
            ],
            [
                'key' => 'auto_clock_out_enabled',
                'value' => 'false',
                'description' => 'Whether automatic clock-out is enabled',
                'type' => 'boolean',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSettings::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'description' => $setting['description'],
                    'type' => $setting['type'],
                ]
            );
        }
    }
}
