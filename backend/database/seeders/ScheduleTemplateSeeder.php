<?php

namespace Database\Seeders;

use App\Models\ScheduleTemplate;
use Illuminate\Database\Seeder;

class ScheduleTemplateSeeder extends Seeder
{
    private function buildDayRules(array $activeDays, string $clockIn, string $clockOut, bool $graceEnabled = false, string $graceType = '-/+', int $graceMinutes = 15): array
    {
        $allDays = [1, 2, 3, 4, 5, 6, 0]; // Monday to Sunday

        return array_map(function ($day) use ($activeDays, $clockIn, $clockOut, $graceType, $graceMinutes) {
            $enabled = in_array($day, $activeDays, true);

            return [
                'day' => $day,
                'enabled' => $enabled,
                'clock_in' => $enabled ? $clockIn : null,
                'clock_out' => $enabled ? $clockOut : null,
                'grace_enabled' => $graceEnabled,
                'grace_type' => $graceType,
                'grace_minutes' => $graceMinutes,
            ];
        }, $allDays);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Standard 9-6 (Mon-Fri)',
                'description' => 'Full-time standard schedule: Monday to Friday, 9 AM to 6 PM',
                'work_days' => [1, 2, 3, 4, 5], // Mon-Fri
                'day_rules' => $this->buildDayRules([1, 2, 3, 4, 5], '09:00:00', '18:00:00', '-/+', 15),
                'clock_in_start' => '08:45:00',
                'clock_in_end' => '18:15:00',
                'clock_out_start' => '18:00:00',
                'clock_out_end' => '18:15:00',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 9,
                'overtime_threshold_hours' => 9,
                'expected_hours_per_day' => 9,
            ],
            [
                'name' => 'Morning Shift 6-3',
                'description' => 'Early morning shift: 6 AM to 3 PM, Monday to Friday',
                'work_days' => [1, 2, 3, 4, 5], // Mon-Fri
                'day_rules' => $this->buildDayRules([1, 2, 3, 4, 5], '06:00:00', '15:00:00', '-/+', 15),
                'clock_in_start' => '05:45:00',
                'clock_in_end' => '15:15:00',
                'clock_out_start' => '15:00:00',
                'clock_out_end' => '15:15:00',
                'start_time' => '06:00:00',
                'end_time' => '15:00:00',
                'work_start_time' => '06:00:00',
                'work_end_time' => '15:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 9,
                'overtime_threshold_hours' => 9,
                'expected_hours_per_day' => 9,
            ],
            [
                'name' => 'Evening Shift 3-12',
                'description' => 'Evening shift: 3 PM to 12 AM, Monday to Friday',
                'work_days' => [1, 2, 3, 4, 5], // Mon-Fri
                'day_rules' => $this->buildDayRules([1, 2, 3, 4, 5], '15:00:00', '00:00:00', '-/+', 15),
                'clock_in_start' => '14:45:00',
                'clock_in_end' => '00:15:00',
                'clock_out_start' => '00:00:00',
                'clock_out_end' => '00:15:00',
                'start_time' => '15:00:00',
                'end_time' => '00:00:00',
                'work_start_time' => '15:00:00',
                'work_end_time' => '00:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 9,
                'overtime_threshold_hours' => 9,
                'expected_hours_per_day' => 9,
            ],
            [
                'name' => 'Compressed Week 4/10',
                'description' => 'Four 10-hour days instead of five 9-hour days',
                'work_days' => [1, 2, 3, 4], // Mon-Thu
                'day_rules' => $this->buildDayRules([1, 2, 3, 4], '08:00:00', '18:00:00', '-/+', 15),
                'clock_in_start' => '07:45:00',
                'clock_in_end' => '18:15:00',
                'clock_out_start' => '18:00:00',
                'clock_out_end' => '18:15:00',
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
                'work_start_time' => '08:00:00',
                'work_end_time' => '18:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 10,
                'overtime_threshold_hours' => 10,
                'expected_hours_per_day' => 10,
            ],
            [
                'name' => 'Half Days 9-2',
                'description' => 'Half-day schedule: 9 AM to 2 PM, Monday to Friday',
                'work_days' => [1, 2, 3, 4, 5], // Mon-Fri
                'day_rules' => $this->buildDayRules([1, 2, 3, 4, 5], '09:00:00', '14:00:00', '-/+', 15),
                'clock_in_start' => '08:45:00',
                'clock_in_end' => '14:15:00',
                'clock_out_start' => '14:00:00',
                'clock_out_end' => '14:15:00',
                'start_time' => '09:00:00',
                'end_time' => '14:00:00',
                'work_start_time' => '09:00:00',
                'work_end_time' => '14:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 5,
                'overtime_threshold_hours' => 5,
                'expected_hours_per_day' => 5,
            ],
            [
                'name' => 'Flexible Hours 8-8',
                'description' => 'Flexible schedule with 8-hour window flexibility',
                'work_days' => [1, 2, 3, 4, 5], // Mon-Fri
                'day_rules' => $this->buildDayRules([1, 2, 3, 4, 5], '08:00:00', '20:00:00', '-/+', 15),
                'clock_in_start' => '08:00:00',
                'clock_in_end' => '20:00:00',
                'clock_out_start' => '20:00:00',
                'clock_out_end' => '20:15:00',
                'start_time' => '08:00:00',
                'end_time' => '20:00:00',
                'work_start_time' => '08:00:00',
                'work_end_time' => '20:00:00',
                'late_threshold_minutes' => 0,
                'required_hours_per_day' => 8,
                'overtime_threshold_hours' => 8,
                'expected_hours_per_day' => 8,
            ],
        ];

        foreach ($templates as $template) {
            ScheduleTemplate::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}
