<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AttendanceService;

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

    public function test_calculate_status_undertime()
    {
        $this->assertEquals('undertime', AttendanceService::calculateStatus('09:00:00', '12:00:00', 8, '09:00:00'));
    }

    public function test_late_arrival_clocks_out_at_scheduled_end_is_late_not_half_day()
    {
        // Arrives 30 min late, clocks out at the scheduled window → late, not half_day
        $this->assertEquals('late', AttendanceService::calculateStatus('09:30:00', '17:00:00', 8, '09:00:00'));
    }

    public function test_on_time_early_departure_is_half_day()
    {
        // Arrives on time, leaves after half the shift → half_day
        $this->assertEquals('half_day', AttendanceService::calculateStatus('09:00:00', '14:00:00', 8, '09:00:00'));
    }

    public function test_severely_late_with_little_work_is_undertime()
    {
        // Arrives 5h late, works only 2h (< half of 8h shift) → undertime
        $this->assertEquals('undertime', AttendanceService::calculateStatus('14:00:00', '16:00:00', 8, '09:00:00'));
    }

    public function test_calculate_status_overtime()
    {
        $this->assertEquals('overtime', AttendanceService::calculateStatus('09:00:00', '18:00:00', 8, '09:00:00'));
    }
}
