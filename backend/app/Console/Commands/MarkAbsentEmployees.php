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
        $today = $dateInput ? Carbon::parse($dateInput) : SystemClock::today();
        
        $employees = Employee::where('status', 'active')->get();

        foreach ($employees as $employee) {
            // Skip if an attendance log already exists for today
            $exists = AttendanceLog::where('employee_id', $employee->id)
                ->whereDate('date', $today->toDateString())
                ->exists();

            if ($exists) continue;

            if ($onLeave) continue;
            
            // Check for holiday/event that doesn't count as absence
            $isHoliday = \App\Models\CalendarEvent::whereDate('event_date', $today->toDateString())
                ->whereHas('type', function($q) {
                    $q->where('counts_as_absence', false);
                })->exists();

            if ($isHoliday) continue;

            // Check if scheduled to work
            $schedule = EmployeeSchedule::getForEmployeeOnDate($employee->id, $today);
            if (!$schedule || !$schedule->template) continue;

            $template = $schedule->template;
            $dayOfWeek = $today->dayOfWeek;
            
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
                    'date' => $today->toDateString(),
                    'status' => 'absent',
                    'schedule_template_id' => $template->id,
                    'schedule_template_name' => $template->name,
                    'notes' => '[System] Automatically marked absent.'
                ]);
            }
        }
        $this->info('Daily absentee marking completed.');
    }
}
