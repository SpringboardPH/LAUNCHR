<?php

namespace Tests\Feature;

use App\Models\ScheduleTemplate;
use App\Models\User;
use App\Services\AttendanceService;
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

    public function test_wraps_midnight_and_shift_end_for_handle_a_disabled_day_rule_without_crashing()
    {
        $template = new ScheduleTemplate([
            'type' => 'night',
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
        ]);

        $disabledRule = ['day' => 0, 'enabled' => false, 'clock_in' => null, 'clock_out' => null];

        $this->assertTrue($template->wrapsMidnight($disabledRule));
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
}
