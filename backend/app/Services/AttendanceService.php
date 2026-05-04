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

        // Worked more than expected → overtime
        if ($hoursWorked > $expectedHours) {
            return 'overtime';
        }

        // Worked at least half the shift but less than the full expected hours → half_day
        if ($hoursWorked >= $halfExpected && $hoursWorked < $expectedHours) {
            return 'half_day';
        }

        // Worked less than half the shift → undertime
        if ($hoursWorked < $halfExpected) {
            return 'undertime';
        }

        return $isLate ? 'late' : 'completed';
    }

    /**
     * Parse time string to minutes.
     */
    public static function parseTimeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $hour * 60 + $minute;
    }

    /**
     * Calculate detailed metrics for payroll.
     */
    public static function calculateDetails(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart): array
    {
        if (!$clockIn || !$clockOut) {
            return [
                'hours_worked' => 0,
                'overtime_hours' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'status' => $clockIn ? 'working' : 'absent'
            ];
        }

        $inMin = self::parseTimeToMinutes($clockIn);
        $outMin = self::parseTimeToMinutes($clockOut);
        $startMin = self::parseTimeToMinutes($workStart);

        if ($outMin < $inMin) $outMin += 1440;

        $minutesWorked = $outMin - $inMin;
        $hoursWorked = $minutesWorked / 60;
        $lateMin = max(0, $inMin - $startMin);
        
        $overtimeHours = max(0, $hoursWorked - $expectedHours);
        $expectedMinutes = $expectedHours * 60;
        $undertimeMin = max(0, $expectedMinutes - $minutesWorked);

        return [
            'hours_worked' => $hoursWorked,
            'overtime_hours' => $overtimeHours,
            'late_minutes' => $lateMin,
            'undertime_minutes' => $undertimeMin,
            'status' => self::calculateStatus($clockIn, $clockOut, $expectedHours, $workStart)
        ];
    }
}