<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AttendanceService;
use PHPUnit\Framework\Attributes\DataProvider;

class AttendanceServiceTest extends TestCase
{
    public function test_calculate_status_absent()
    {
        $this->assertEquals('absent', AttendanceService::calculateStatus(null, null, 8, '09:00:00'));
    }

    public function test_calculate_status_completed()
    {
        $this->assertEquals('completed', AttendanceService::calculateStatus('09:00:00', '17:00:00', 8, '09:00:00'));
    }

    public function test_calculate_status_late()
    {
        $this->assertEquals('late', AttendanceService::calculateStatus('09:30:00', '17:30:00', 8, '09:00:00'));
    }

    public function test_late_arrival_clocks_out_at_scheduled_end_is_late_not_half_day()
    {
        // Arrives 30 min late, clocks out at the scheduled window → late, not half_day
        $this->assertEquals('late', AttendanceService::calculateStatus('09:30:00', '17:00:00', 8, '09:00:00'));
    }

    /**
     * The pay-changing statuses are never written by the calculator — they require an
     * HR decision on an auto-filed EmployeeRequest. calculateStatus() only ever reports
     * the factual baseline, so a deviation must not leak into pay without sign-off.
     */
    #[DataProvider('deviationShiftProvider')]
    public function test_calculate_status_never_returns_a_pay_changing_status(string $in, string $out, string $expected)
    {
        $this->assertEquals($expected, AttendanceService::calculateStatus($in, $out, 8, '09:00:00'));
    }

    public static function deviationShiftProvider(): array
    {
        return [
            // description                      => [clock in, clock out, expected status]
            'on time, worked 3h of 8h'          => ['09:00:00', '12:00:00', 'completed'],
            'on time, worked 5h of 8h'          => ['09:00:00', '14:00:00', 'completed'],
            'on time, worked 9h of 8h'          => ['09:00:00', '18:00:00', 'completed'],
            '5h late, worked only 2h'           => ['14:00:00', '16:00:00', 'late'],
            '30m late, worked 9h'               => ['09:30:00', '18:30:00', 'late'],
        ];
    }

    #[DataProvider('deviationProvider')]
    public function test_classify_deviation(float $hoursWorked, ?string $expected)
    {
        $this->assertSame($expected, AttendanceService::classifyDeviation($hoursWorked, 8));
    }

    public static function deviationProvider(): array
    {
        return [
            'over the full shift'      => [9.0, 'overtime'],
            'a minute over'            => [8.02, 'overtime'],
            'exactly the full shift'   => [8.0, null],
            'short but past half'      => [5.0, 'half_day'],
            'exactly half'             => [4.0, 'half_day'],
            'under half'               => [3.99, 'undertime'],
            'barely worked'            => [0.5, 'undertime'],
        ];
    }

    public function test_classify_deviation_ignores_shifts_with_no_expected_hours()
    {
        // A template with no hours configured has nothing to deviate from — filing a
        // request off a zero baseline would flag every shift as overtime.
        $this->assertNull(AttendanceService::classifyDeviation(8.0, 0));
    }

    public function test_flexi_status_is_completed_regardless_of_hours()
    {
        // Flexi has no scheduled start, so no lateness — and its deviations go through
        // the same request flow as fixed.
        $this->assertEquals('absent', AttendanceService::calculateFlexiStatus(null, null, 8));
        $this->assertEquals('working', AttendanceService::calculateFlexiStatus('09:00:00', null, 8));
        $this->assertEquals('completed', AttendanceService::calculateFlexiStatus('09:00:00', '11:00:00', 8));
        $this->assertEquals('completed', AttendanceService::calculateFlexiStatus('09:00:00', '17:00:00', 8));
        $this->assertEquals('completed', AttendanceService::calculateFlexiStatus('09:00:00', '21:00:00', 8));
    }

    public function test_overtime_hours_rounded_to_one_decimal()
    {
        // 09:00–18:32 = 9h32m on an 8h day -> 1.5333h OT, billed as 1.5h
        $fixed = AttendanceService::calculateDetails('09:00:00', '18:32:00', 8, '09:00:00');
        $this->assertSame(1.5, $fixed['overtime_hours']);

        // 09:00–18:37 -> 1.6166h OT, rounds up to 1.6h
        $flexi = AttendanceService::calculateFlexiDetails('09:00:00', '18:37:00', 8);
        $this->assertSame(1.6, $flexi['overtime_hours']);
    }
}
