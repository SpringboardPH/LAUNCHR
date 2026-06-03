<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Helpers\SystemClock;
use Illuminate\Support\Carbon;

class MarkAbsentEmployees extends Command
{
    protected $signature = 'attendance:mark-absent {date?}';
    protected $description = 'Scan for scheduled working days with no attendance log and mark them as absent';

    public function handle()
    {
        $dateInput = $this->argument('date');
        
        // If no date is provided, determine the target date.
        // If it's very early in the morning (e.g., 00:00), we usually want to mark absences for "yesterday".
        if ($dateInput) {
            $targetDate = Carbon::parse($dateInput);
        } else {
            $now = SystemClock::now();
            // If running before 4 AM, assume we are marking for the day that just ended.
            if ($now->hour < 4) {
                $targetDate = $now->copy()->subDay()->startOfDay();
            } else {
                $targetDate = $now->copy()->startOfDay();
            }
        }
        
        $this->info("Scanning for absences on: " . $targetDate->toDateString());
        
        $employees = Employee::where('status', 'active')->get();

        foreach ($employees as $employee) {
            // Skip if an attendance log already exists for this date
            $exists = AttendanceLog::where('employee_id', $employee->id)
                ->where('date', $targetDate->toDateString())
                ->exists();

            if ($exists) continue;

            $onLeave = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $targetDate->toDateString())
                ->where('end_date', '>=', $targetDate->toDateString())
                ->exists();

            if ($onLeave) continue;
            
            // Check for holiday/event that doesn't count as absence
            $isHoliday = \App\Models\CalendarEvent::where('event_date', $targetDate->toDateString())
                ->whereHas('type', function($q) {
                    $q->where('counts_as_absence', false);
                })->exists();

            if ($isHoliday) continue;

            // Check if scheduled to work
            $schedule = EmployeeSchedule::getForEmployeeOnDate($employee->id, $targetDate);
            if (!$schedule || !$schedule->template) continue;

            $template = $schedule->template;
            $dayOfWeek = $targetDate->dayOfWeek;
            
            $isWorkingDay = false;
            if ($template->day_rules) {
                foreach ($template->day_rules as $rule) {
                    if ($rule['day'] == $dayOfWeek && $rule['enabled']) {
                        $isWorkingDay = true;
                        break;
                    }
                }
            } elseif ($template->work_days) {
                if (in_array($dayOfWeek, $template->work_days)) {
                    $isWorkingDay = true;
                }
            }

            if ($isWorkingDay) {
                AttendanceLog::create([
                    'employee_id' => $employee->id,
                    'date' => $targetDate->toDateString(),
                    'status' => 'absent',
                    'schedule_template_id' => $template->id,
                    'schedule_template_name' => $template->name,
                    'clock_in_notes' => '[System] Automatically marked absent.'
                ]);
            }
        }
        $this->info('Daily absentee marking completed.');
    }
}
