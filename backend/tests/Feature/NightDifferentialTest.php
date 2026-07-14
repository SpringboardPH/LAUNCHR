<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\ScheduleTemplate;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NightDifferentialTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('nightHoursProvider')]
    public function test_calculate_night_hours(?string $clockIn, ?string $clockOut, float $expected)
    {
        $this->assertEquals($expected, AttendanceService::calculateNightHours($clockIn, $clockOut));
    }

    public static function nightHoursProvider(): array
    {
        return [
            'canonical night shift'        => ['22:00:00', '06:00:00', 8.0],
            'partial overlap at the front' => ['18:00:00', '23:00:00', 1.0],
            'previous nights tail'         => ['04:00:00', '12:00:00', 2.0],
            'pure day shift'               => ['09:00:00', '18:00:00', 0.0],
            'both null'                    => [null, null, 0.0],
            'day shift with OT into window' => ['09:00:00', '23:30:00', 1.5],
            'clocked in early out late'    => ['21:00:00', '07:00:00', 8.0],
        ];
    }

    public function test_overnight_late_arrival_is_correctly_flagged()
    {
        $details = AttendanceService::calculateDetails('00:30:00', '06:00:00', 8, '22:00:00');
        $this->assertEquals(150, $details['late_minutes']);
        $this->assertEquals(5.5, $details['hours_worked']);
    }

    public function test_on_time_night_shift_is_not_marked_late()
    {
        $details = AttendanceService::calculateDetails('22:00:00', '06:00:00', 8, '22:00:00');
        $this->assertEquals(0, $details['late_minutes']);
        $this->assertEquals(8.0, $details['hours_worked']);
    }

    public function test_normal_day_shift_is_unaffected()
    {
        $details = AttendanceService::calculateDetails('09:15:00', '18:00:00', 9, '09:00:00');
        $this->assertEquals(15, $details['late_minutes']);
    }

    public function test_night_template_wraps_midnight_on_overnight_day_rule()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $rule = ['clock_in' => '22:00:00', 'clock_out' => '06:00:00'];

        $this->assertTrue($template->wrapsMidnight($rule));
    }

    public function test_night_template_does_not_wrap_on_a_mixed_week_early_day_rule()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $rule = ['clock_in' => '06:00:00', 'clock_out' => '15:00:00'];

        $this->assertFalse($template->wrapsMidnight($rule));
    }

    public function test_fixed_template_never_wraps_midnight_even_with_overnight_day_rule()
    {
        $template = new ScheduleTemplate([
            'type' => 'fixed',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $rule = ['clock_in' => '22:00:00', 'clock_out' => '06:00:00'];

        $this->assertFalse($template->wrapsMidnight($rule));
    }

    public function test_wraps_midnight_falls_back_to_template_times_when_no_day_rule_given()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $this->assertTrue($template->wrapsMidnight(null));
    }

    public function test_shift_end_for_returns_the_day_rules_clock_out()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $overnightRule = ['clock_in' => '22:00:00', 'clock_out' => '06:00:00'];
        $earlyRule = ['clock_in' => '06:00:00', 'clock_out' => '15:00:00'];

        $this->assertEquals('06:00:00', $template->shiftEndFor($overnightRule));
        $this->assertEquals('15:00:00', $template->shiftEndFor($earlyRule));
    }

    public function test_wraps_midnight_returns_false_for_a_disabled_day_rule()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        // Sunday, disabled: no shift exists that day, so it must not claim to wrap.
        $disabledRule = ['day' => 0, 'enabled' => false, 'clock_in' => null, 'clock_out' => null];

        $this->assertFalse($template->wrapsMidnight($disabledRule));
        $this->assertEquals('06:00:00', $template->shiftEndFor($disabledRule));
    }

    /**
     * Builds a full 7-day day_rules payload with a single enabled day.
     */
    private function buildDayRules(int $enabledDay, ?string $clockIn, ?string $clockOut): array
    {
        $rules = [];
        for ($day = 0; $day <= 6; $day++) {
            $enabled = $day === $enabledDay;
            $rules[] = [
                'day' => $day,
                'enabled' => $enabled,
                'clock_in' => $enabled ? $clockIn : null,
                'clock_out' => $enabled ? $clockOut : null,
                'grace_enabled' => false,
                'grace_type' => '-/+',
                'grace_minutes' => 15,
            ];
        }

        return $rules;
    }

    public function test_store_route_persists_night_type_and_derives_8_hour_wrapping_shift()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/schedule-templates', [
            'name' => 'Night Shift Template',
            'type' => 'night',
            'day_rules' => $this->buildDayRules(3, '22:00:00', '06:00:00'),
        ]);

        $response->assertCreated();
        $this->assertSame('night', $response->json('data.type'));
        $this->assertSame(8, $response->json('data.required_hours_per_day'));

        $this->assertDatabaseHas('schedule_templates', [
            'name' => 'Night Shift Template',
            'type' => 'night',
            'required_hours_per_day' => 8,
        ]);
    }

    public function test_store_route_still_defaults_to_fixed_when_type_is_omitted()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/schedule-templates', [
            'name' => 'Untyped Template',
            'day_rules' => $this->buildDayRules(3, '09:00:00', '18:00:00'),
        ]);

        $response->assertCreated();
        $this->assertSame('fixed', $response->json('data.type'));
        $this->assertDatabaseHas('schedule_templates', [
            'name' => 'Untyped Template',
            'type' => 'fixed',
        ]);
    }

    public function test_store_route_persists_fixed_when_type_explicitly_fixed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/schedule-templates', [
            'name' => 'Explicit Fixed Template',
            'type' => 'fixed',
            'day_rules' => $this->buildDayRules(3, '09:00:00', '18:00:00'),
        ]);

        $response->assertCreated();
        $this->assertSame('fixed', $response->json('data.type'));
        $this->assertDatabaseHas('schedule_templates', [
            'name' => 'Explicit Fixed Template',
            'type' => 'fixed',
        ]);
    }

    public function test_night_template_created_via_store_route_wraps_midnight_on_its_wednesday_rule()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/schedule-templates', [
            'name' => 'Night Shift Round Trip',
            'type' => 'night',
            'day_rules' => $this->buildDayRules(3, '22:00:00', '06:00:00'),
        ]);

        $response->assertCreated();

        $template = ScheduleTemplate::where('name', 'Night Shift Round Trip')->firstOrFail();
        $this->assertSame('night', $template->type);

        $wednesdayRule = collect($template->day_rules)->firstWhere('day', 3);
        $this->assertNotNull($wednesdayRule);
        $this->assertTrue($template->wrapsMidnight($wednesdayRule));
    }

    /**
     * Builds a full 7-day day_rules payload where every day in $enabledDays
     * shares the same clock_in/clock_out.
     */
    private function buildWeekDayRules(array $enabledDays, string $clockIn, string $clockOut): array
    {
        $rules = [];
        for ($day = 0; $day <= 6; $day++) {
            $enabled = in_array($day, $enabledDays, true);
            $rules[] = [
                'day' => $day,
                'enabled' => $enabled,
                'clock_in' => $enabled ? $clockIn : null,
                'clock_out' => $enabled ? $clockOut : null,
                'grace_enabled' => false,
                'grace_type' => '-/+',
                'grace_minutes' => 15,
            ];
        }

        return $rules;
    }

    /**
     * Mixed-week day_rules: Monday (day 1) is an early, non-wrapping shift;
     * Wednesday (day 3) is a wrapping night shift. All other days disabled.
     */
    private function buildMixedWeekDayRules(): array
    {
        $rules = [];
        for ($day = 0; $day <= 6; $day++) {
            if ($day === 1) {
                $rules[] = [
                    'day' => 1, 'enabled' => true,
                    'clock_in' => '06:00:00', 'clock_out' => '15:00:00',
                    'grace_enabled' => false, 'grace_type' => '-/+', 'grace_minutes' => 15,
                ];
            } elseif ($day === 3) {
                $rules[] = [
                    'day' => 3, 'enabled' => true,
                    'clock_in' => '22:00:00', 'clock_out' => '06:00:00',
                    'grace_enabled' => false, 'grace_type' => '-/+', 'grace_minutes' => 15,
                ];
            } else {
                $rules[] = [
                    'day' => $day, 'enabled' => false,
                    'clock_in' => null, 'clock_out' => null,
                    'grace_enabled' => false, 'grace_type' => '-/+', 'grace_minutes' => 15,
                ];
            }
        }

        return $rules;
    }

    private function makeEmployee(string $suffix): Employee
    {
        return Employee::create([
            'employee_id' => "EMP-ND-{$suffix}",
            'first_name'  => 'Night',
            'last_name'   => "Shift{$suffix}",
            'email'       => "night-shift-{$suffix}@example.com",
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 20000,
            'status'      => 'active',
        ]);
    }

    private function makeNightTemplate(array $dayRules, array $workDays): ScheduleTemplate
    {
        return ScheduleTemplate::create([
            'type' => 'night',
            'name' => 'Night Shift ' . uniqid(),
            'work_days' => $workDays,
            'day_rules' => $dayRules,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
            'required_hours_per_day' => 8,
        ]);
    }

    private function assignSchedule(Employee $employee, ScheduleTemplate $template): EmployeeSchedule
    {
        return EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'schedule_template_id' => $template->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'active',
        ]);
    }

    public function test_night_shift_clock_in_at_shift_start_succeeds_and_is_not_late()
    {
        $employee = $this->makeEmployee('1');
        $template = $this->makeNightTemplate(
            $this->buildWeekDayRules([1, 2, 3, 4, 5], '22:00:00', '06:00:00'),
            [1, 2, 3, 4, 5]
        );
        $this->assignSchedule($employee, $template);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->travelTo(Carbon::parse('2026-01-05 22:00:00')); // Monday

        $response = $this->actingAs($admin)->postJson('/api/attendance/clock-in', [
            'employee_id' => $employee->id,
        ]);

        $response->assertCreated();
        $this->assertSame('working', $response->json('data.status'));

        $log = AttendanceLog::where('employee_id', $employee->id)->sole();
        $this->assertSame('2026-01-05', $log->date->toDateString());
    }

    public function test_post_midnight_arrival_resolves_to_yesterdays_shift()
    {
        $employee = $this->makeEmployee('2');
        $template = $this->makeNightTemplate(
            $this->buildWeekDayRules([1, 2, 3, 4, 5], '22:00:00', '06:00:00'),
            [1, 2, 3, 4, 5]
        );
        $this->assignSchedule($employee, $template);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->travelTo(Carbon::parse('2026-01-06 00:30:00')); // Tuesday 00:30, shift began Monday 22:00

        $response = $this->actingAs($admin)->postJson('/api/attendance/clock-in', [
            'employee_id' => $employee->id,
        ]);

        $response->assertCreated();
        $this->assertSame('late', $response->json('data.status'));

        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
        $log = AttendanceLog::where('employee_id', $employee->id)->sole();
        $this->assertSame('2026-01-05', $log->date->toDateString());
    }

    public function test_one_night_shift_produces_exactly_one_attendance_log_row()
    {
        $employee = $this->makeEmployee('3');
        $template = $this->makeNightTemplate(
            $this->buildWeekDayRules([1, 2, 3, 4, 5], '22:00:00', '06:00:00'),
            [1, 2, 3, 4, 5]
        );
        $this->assignSchedule($employee, $template);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->travelTo(Carbon::parse('2026-01-05 22:00:00')); // Monday
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', ['employee_id' => $employee->id])
            ->assertCreated();

        $this->travelTo(Carbon::parse('2026-01-06 06:00:00')); // Tuesday
        $this->actingAs($admin)->postJson('/api/attendance/clock-out', ['employee_id' => $employee->id])
            ->assertOk();

        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
        $log = AttendanceLog::where('employee_id', $employee->id)->sole();
        $this->assertSame('2026-01-05', $log->date->toDateString());
        $this->assertSame('22:00:00', $log->clock_in_time);
        $this->assertSame('06:00:00', $log->clock_out_time);
    }

    public function test_mixed_week_unwrap_is_per_day_rule_not_per_template()
    {
        $employee = $this->makeEmployee('4');
        $template = $this->makeNightTemplate($this->buildMixedWeekDayRules(), [1, 3]);
        $this->assignSchedule($employee, $template);
        $admin = User::factory()->create(['role' => 'admin']);

        // Monday's day rule is 06:00-15:00 (does not wrap) - a 22:00 arrival
        // is well past its window and must be rejected.
        $this->travelTo(Carbon::parse('2026-01-05 22:00:00')); // Monday
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', ['employee_id' => $employee->id])
            ->assertStatus(400);

        // Wednesday's day rule is 22:00-06:00 (wraps) - the same clock time succeeds.
        $this->travelTo(Carbon::parse('2026-01-07 22:00:00')); // Wednesday
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', ['employee_id' => $employee->id])
            ->assertCreated();
    }

    public function test_fixed_template_night_time_handling_is_unaffected()
    {
        $template = ScheduleTemplate::create([
            'type' => 'fixed',
            'name' => 'Fixed 9-6 Regression',
            'work_days' => [1, 2, 3, 4, 5],
            'day_rules' => $this->buildWeekDayRules([1, 2, 3, 4, 5], '09:00:00', '18:00:00'),
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'required_hours_per_day' => 9,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $onTimeEmployee = $this->makeEmployee('5a');
        $this->assignSchedule($onTimeEmployee, $template);

        $this->travelTo(Carbon::parse('2026-01-05 09:15:00')); // Monday
        $response = $this->actingAs($admin)->postJson('/api/attendance/clock-in', [
            'employee_id' => $onTimeEmployee->id,
        ]);
        $response->assertCreated();
        $this->assertSame('late', $response->json('data.status'));

        $lateArrivalEmployee = $this->makeEmployee('5b');
        $this->assignSchedule($lateArrivalEmployee, $template);

        $this->travelTo(Carbon::parse('2026-01-05 22:00:00')); // Monday, same day
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', [
            'employee_id' => $lateArrivalEmployee->id,
        ])->assertStatus(400);
    }

    public function test_clock_in_after_already_completing_last_nights_shift_is_rejected_not_a_new_shift()
    {
        $employee = $this->makeEmployee('6');
        $template = $this->makeNightTemplate(
            $this->buildWeekDayRules([1, 2, 3, 4, 5], '22:00:00', '06:00:00'),
            [1, 2, 3, 4, 5]
        );
        $this->assignSchedule($employee, $template);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->travelTo(Carbon::parse('2026-01-05 22:00:00')); // Monday 22:00
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', ['employee_id' => $employee->id])
            ->assertCreated();

        $this->travelTo(Carbon::parse('2026-01-06 05:00:00')); // Tuesday 05:00
        $this->actingAs($admin)->postJson('/api/attendance/clock-out', [
            'employee_id' => $employee->id,
            'confirm_early_clock_out' => true,
        ])->assertOk();

        // Monday's shift is closed. A second arrival at 05:30 is 16.5 hours before
        // Tuesday's own 22:00 shift and must not be reinterpreted as a post-midnight
        // arrival for it — that would open a spurious second attendance_log row.
        $this->travelTo(Carbon::parse('2026-01-06 05:30:00')); // Tuesday 05:30
        $this->actingAs($admin)->postJson('/api/attendance/clock-in', ['employee_id' => $employee->id])
            ->assertStatus(400);

        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
    }
}
