<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Helpers\SystemClock;
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

        $employees = Employee::all();
        foreach ($employees as $employee) {
            $this->performAutoClockOut($employee->id);
        }
        $this->info('Auto clock-out completed.');
    }

    private function parseTimeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $hour * 60 + $minute;
    }

    private function calculateExpectedHours(string $clockIn, string $clockOut): int
    {
        $inMinutes  = $this->parseTimeToMinutes($clockIn);
        $outMinutes = $this->parseTimeToMinutes($clockOut);
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }
        return max(1, (int) round(($outMinutes - $inMinutes) / 60));
    }

    private function calculateStatus(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart): string
    {
        if (!$clockIn) {
            return 'absent';
        }

        $inMinutes      = $this->parseTimeToMinutes($clockIn);
        $outMinutes     = $this->parseTimeToMinutes($clockOut);
        $startMinutes   = $this->parseTimeToMinutes($workStart);
        $isLate         = $inMinutes > $startMinutes;
        $hoursWorked    = max(0, ($outMinutes - $inMinutes) / 60);

        if ($hoursWorked < $expectedHours) {
            return 'incomplete';
        }

        return $isLate ? 'late' : 'completed';
    }

    private function performAutoClockOut($employeeId)
    {
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

            $clockOutEnd = null;
            $autoClockOutTime = null;

            if ($dayRule) {
                $targetOut = Carbon::parse($dayRule['clock_out']);
                $targetOut = $date->copy()->setTime($targetOut->hour, $targetOut->minute, 0);

                $grace = (int) ($dayRule['grace_minutes'] ?? 0);
                $type = $dayRule['grace_type'] ?? '-/+';
                $graceEnabled = (bool) ($dayRule['grace_enabled'] ?? false);

                $clockOutEnd = $targetOut->copy();
                if ($graceEnabled && ($type === '+' || $type === '-/+')) {
                    $clockOutEnd->addMinutes($grace);
                }

                // Clock out at the end of the grace window, not just the base time
                $autoClockOutTime = $clockOutEnd->format('H:i:s');
            } else {
                $clockOutEndStr = $template->clock_out_end ?? $template->end_time;
                if ($clockOutEndStr) {
                    $targetOut = Carbon::parse($clockOutEndStr);
                    $clockOutEnd = $date->copy()->setTime($targetOut->hour, $targetOut->minute, 0);
                    $autoClockOutTime = $clockOutEnd->format('H:i:s');
                }
            }

            if ($clockOutEnd && $autoClockOutTime && SystemClock::now()->isAfter($clockOutEnd)) {
                // Derive work start and expected hours for accurate status
                $workStartTime = $dayRule['clock_in'] ?? $template->work_start_time ?? '09:00:00';
                $expectedHours = $dayRule
                    ? $this->calculateExpectedHours($dayRule['clock_in'], $dayRule['clock_out'])
                    : ($template->required_hours_per_day ?? 9);

                $status = $this->calculateStatus(
                    $log->clock_in_time,
                    $autoClockOutTime,
                    $expectedHours,
                    $workStartTime
                );

                $log->update([
                    'clock_out_time' => $autoClockOutTime,
                    'status'         => $status,
                    'notes'          => ($log->notes ? $log->notes . "\n" : '') . 'Auto clocked out',
                ]);
            }
        }
    }
}