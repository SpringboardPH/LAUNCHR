<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\Api\AttendanceController;

class GeofenceDistanceTest extends TestCase
{
    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $ref = new \ReflectionClass(AttendanceController::class);
        $obj = $ref->newInstanceWithoutConstructor();
        $m = $ref->getMethod('haversineMeters');
        $m->setAccessible(true);
        return $m->invoke($obj, $lat1, $lng1, $lat2, $lng2);
    }

    public function test_same_point_is_zero(): void
    {
        $this->assertEqualsWithDelta(0, $this->distance(14.5995, 120.9842, 14.5995, 120.9842), 0.001);
    }

    public function test_known_north_offset_is_about_1km(): void
    {
        // ~0.009° of latitude ≈ 1 km due north
        $this->assertEqualsWithDelta(1000, $this->distance(14.5995, 120.9842, 14.6085, 120.9842), 30);
    }
}
