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

    public function test_calculate_status_incomplete()
    {
        $this->assertEquals('incomplete', AttendanceService::calculateStatus('09:00:00', '12:00:00', 8, '09:00:00'));
    }
}
