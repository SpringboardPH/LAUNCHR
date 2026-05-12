<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Helpers\SystemClock;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;

class AutoClockOut extends Command
{
    protected $signature = 'attendance:auto-clock-out';
    protected $description = 'Automatically clock out employees who missed their departure window';

    public function handle()
    {
        $enabled = \App\Models\SystemSettings::get('auto_clock_out_enabled', false);
        if (!$enabled) {
            $this->info('Auto clock-out is disabled in settings.');
            return;
        }

        // Check if we are at the "absent marking time" (e.g., 23:59) to force clock-out
        $absentMarkingTime = \App\Models\SystemSettings::get('absent_marking_time', '23:59');
        $isEndOfDay = SystemClock::now()->format('H:i') === substr($absentMarkingTime, 0, 5);

        $employees = Employee::all();
        foreach ($employees as $employee) {
            $this->performAutoClockOut($employee->id, $isEndOfDay);
        }
        $this->info('Auto clock-out completed.');
    }

    private function calculateExpectedHours(string $clockIn, string $clockOut): int
    {
        $inMinutes  = AttendanceService::parseTimeToMinutes($clockIn);
        $outMinutes = AttendanceService::parseTimeToMinutes($clockOut);
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }
        return max(1, (int) round(($outMinutes - $inMinutes) / 60));
    }

    private function calculateStatus(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart): string
    {
        return AttendanceService::calculateStatus($clockIn, $clockOut, $expectedHours, $workStart);
    }

    private function performAutoClockOut($employeeId, $forceEndOfDay = false)
    {
        // If not the end of the day, we don't do anything because the user 
        // requested ONLY auto clock-out at the end-of-day time.
        if (!$forceEndOfDay) return;

        $openLogs = AttendanceLog::where('employee_id', $employeeId)
            ->whereNotNull('clock_in_time')
            ->whereNull('clock_out_time')
            ->get();

        foreach ($openLogs as $log) {
            $date = Carbon::parse($log->date);
            $schedule = EmployeeSchedule::getForEmployeeOnDate($employeeId, $date);
            if (!$schedule || !$schedule->template) continue;

            $template = $schedule->template;
            $dayOfWeek = $date->dayOfWeek;
            
            $dayRule = null;
            if ($template->day_rules) {
                foreach ($template->day_rules as $rule) {
                    if ($rule['day'] == $dayOfWeek && $rule['enabled']) {
                        $dayRule = $rule;
                        break;
                    }
                }
            }

            // Always use the absent marking time (e.g., 23:59) for the clock-out time
            $finalClockOutTime = \App\Models\SystemSettings::get('absent_marking_time', '23:59') . ':00';

            // Derive work start and expected hours for accurate status
            $workStartTime = $dayRule['clock_in'] ?? $template->work_start_time ?? '09:00:00';
            $expectedHours = $dayRule
                ? $this->calculateExpectedHours($dayRule['clock_in'], $dayRule['clock_out'])
                : ($template->required_hours_per_day ?? 9);

            $status = $this->calculateStatus(
                $log->clock_in_time,
                $finalClockOutTime,
                $expectedHours,
                $workStartTime
            );

            $log->update([
                'clock_out_time' => $finalClockOutTime,
                'status'         => $status,
                'notes'          => ($log->notes ? $log->notes . "\n" : '') . 'Auto clocked out (End of Day)',
            ]);
        }
    }
}