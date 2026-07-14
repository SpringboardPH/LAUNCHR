<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NightDifferentialTest extends TestCase
{
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
}
