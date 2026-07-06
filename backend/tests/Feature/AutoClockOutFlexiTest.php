<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\ScheduleTemplate;
use App\Models\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoClockOutFlexiTest extends TestCase
{
    use RefreshDatabase;

    public function test_flexi_employee_who_forgets_to_clock_out_is_closed_without_crashing()
    {
        SystemSettings::set('auto_clock_out_enabled', true, null, 'boolean');

        $employee = Employee::create([
            'employee_id' => 'EMP-FLEXI-1',
            'first_name'  => 'Flexi',
            'last_name'   => 'Worker',
            'email'       => 'flexi-worker@example.com',
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 20000,
            'status'      => 'active',
        ]);

        $template = ScheduleTemplate::create([
            'type'                    => 'flexi',
            'name'                    => 'Flexi 8h',
            'work_days'               => [1, 2, 3, 4, 5],
            'day_rules'               => [['day' => 1, 'enabled' => true]],
            // legacy required columns, unused for flexi
            'start_time'              => '09:00:00',
            'end_time'                => '17:00:00',
            'required_hours_per_day'  => 8,
        ]);

        EmployeeSchedule::create([
            'employee_id'           => $employee->id,
            'schedule_template_id'  => $template->id,
            'start_date'            => '2026-01-05',
            'end_date'              => '2026-01-11',
            'status'                => 'active',
        ]);

        // Clocked in but forgot to clock out; worked "16h" by the time the
        // cron force-closes at 23:59 — far more than the 8h required.
        $log = AttendanceLog::create([
            'employee_id'    => $employee->id,
            'date'           => '2026-01-05', // Monday
            'clock_in_time'  => '08:00:00',
            'clock_out_time' => null,
        ]);

        $this->artisan('attendance:auto-clock-out')->assertExitCode(0);

        $log->refresh();
        $this->assertSame('23:59:00', $log->clock_out_time);
        // Must be capped, not 'overtime' — matches the fixed-schedule cap behavior.
        $this->assertSame('completed', $log->status);
    }

    public function test_flexi_employee_is_not_auto_clocked_out_mid_day_by_the_today_endpoint()
    {
        SystemSettings::set('auto_clock_out_enabled', true, null, 'boolean');

        $employee = Employee::create([
            'employee_id' => 'EMP-FLEXI-2',
            'first_name'  => 'Flexi',
            'last_name'   => 'Worker2',
            'email'       => 'flexi-worker-2@example.com',
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 20000,
            'status'      => 'active',
        ]);

        $template = ScheduleTemplate::create([
            'type'                    => 'flexi',
            'name'                    => 'Flexi 8h',
            'work_days'               => [1, 2, 3, 4, 5],
            // Flexi day_rules carry no clock_in/clock_out — this is what
            // previously made Carbon::parse() default to real wall-clock "now".
            'day_rules'               => [['day' => 1, 'enabled' => true]],
            'start_time'              => '09:00:00',
            'end_time'                => '17:00:00',
            'required_hours_per_day'  => 8,
        ]);

        EmployeeSchedule::create([
            'employee_id'           => $employee->id,
            'schedule_template_id'  => $template->id,
            'start_date'            => '2026-01-05',
            'end_date'              => '2026-01-11',
            'status'                => 'active',
        ]);

        // Just clocked in moments ago, still working.
        $log = AttendanceLog::create([
            'employee_id'    => $employee->id,
            'date'           => '2026-01-05', // Monday
            'clock_in_time'  => '07:59:21',
            'clock_out_time' => null,
            'schedule_type'  => 'flexi',
            'status'         => 'working',
        ]);

        // Same intraday path the frontend hits right after clock-in
        // (GET /api/attendance/today) — must not touch this open log.
        $controller = new \App\Http\Controllers\Api\AttendanceController();
        $method = new \ReflectionMethod($controller, 'performAutoClockOut');
        $method->setAccessible(true);
        $method->invoke($controller, $employee->id);

        $log->refresh();
        $this->assertNull($log->clock_out_time);
        $this->assertSame('working', $log->status);
    }
}
