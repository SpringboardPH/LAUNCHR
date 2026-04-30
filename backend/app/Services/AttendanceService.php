<?php

namespace App\Services;

class AttendanceService
{
    /**
     * Calculate attendance status based on clocked times.
     *
     * @param string|null $clockIn
     * @param string|null $clockOut
     * @param int $expectedHours
     * @param string $workStart
     * @return string
     */
    public static function calculateStatus(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart): string
    {
        if (!$clockIn) {
            return 'absent';
        }

        $inMinutes = self::parseTimeToMinutes($clockIn);
        $outMinutes = $clockOut ? self::parseTimeToMinutes($clockOut) : $inMinutes;
        
        // Handle overnight shifts if necessary
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }

        $startMinutes = self::parseTimeToMinutes($workStart);
        $isLate = $inMinutes > $startMinutes;
        $hoursWorked = max(0, ($outMinutes - $inMinutes) / 60);
        $halfExpected = $expectedHours / 2;

        // Worked at least half the shift but less than the full expected hours → half_day
        if ($hoursWorked >= $halfExpected && $hoursWorked < $expectedHours) {
            return 'half_day';
        }

        // Worked less than half the shift → undertime (previously incomplete)
        if ($hoursWorked < $halfExpected) {
            return 'undertime';
        }

        return $isLate ? 'late' : 'completed';
    }

    /**
     * Parse time string to minutes.
     *
     * @param string $time
     * @return int
     */
    public static function parseTimeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $hour * 60 + $minute;
    }
}