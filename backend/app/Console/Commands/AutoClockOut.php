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
        $employees = Employee::all();
        foreach ($employees as $employee) {
            $this->performAutoClockOut($employee->id);
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

    private function calculateStatus(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart, ?array $dayRule = null): string
    {
        return AttendanceService::calculateStatus($clockIn, $clockOut, $expectedHours, $workStart, $dayRule);
    }

    private function performAutoClockOut(int $employeeId)
    {
        // This command runs at 23:59 PM daily, so we always clock out any open logs
        $openLogs = AttendanceLog::where('employee_id', $employeeId)
            ->whereNotNull('clock_in_time')
            ->whereNull('clock_out_time')
            ->get();

        foreach ($openLogs as $log) {
            /** @var AttendanceLog $log */
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

            // Always use 23:59:00 (11:59 PM) for the clock-out time
            $finalClockOutTime = '23:59:00';

            // Derive work start and expected hours for accurate status
            $workStartTime = $dayRule['clock_in'] ?? $template->work_start_time ?? '09:00:00';
            $expectedHours = $dayRule
                ? $this->calculateExpectedHours($dayRule['clock_in'], $dayRule['clock_out'])
                : ($template->required_hours_per_day ?? 9);

            $status = $this->calculateStatus(
                $log->clock_in_time,
                $finalClockOutTime,
                $expectedHours,
                $workStartTime,
                $dayRule
            );

            // Option A: Cap at 'completed' or 'late' to avoid accidental overtime
            // for employees who simply forgot to clock out.
            if ($status === 'overtime') {
                $inMinutes = AttendanceService::parseTimeToMinutes($log->clock_in_time);
                $startMinutes = AttendanceService::parseTimeToMinutes($workStartTime);
                
                // If they were late but worked until midnight, we still mark them as 'late'
                // so HR can see the attendance infraction, but we don't give them overtime.
                $status = ($inMinutes > $startMinutes) ? 'late' : 'completed';
            }

            $log->update([
                'clock_out_time' => $finalClockOutTime,
                'status'         => $status,
                'clock_out_notes' => ($log->clock_out_notes ? $log->clock_out_notes . "\n" : '') . 'Auto clocked out (End of Day)',
            ]);
        }
    }
}