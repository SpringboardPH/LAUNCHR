<?php

namespace App\Services;

class AttendanceService
{
    /**
     * Calculate attendance status based on clocked times and grace period.
     *
     * @param string|null $clockIn
     * @param string|null $clockOut
     * @param int $expectedHours
     * @param string $workStart
     * @param array|null $dayRule - Optional day rule with grace period info
     * @return string
     */
    public static function calculateStatus(?string $clockIn, ?string $clockOut, int $expectedHours, string $workStart, ?array $dayRule = null): string
    {
        if (!$clockIn) {
            return 'absent';
        }

        $inMinutes = self::parseTimeToMinutes($clockIn);
        $startMinutes = self::parseTimeToMinutes($workStart);

        // Treat early clock-in within the hour as "normal" start for duration/OT
        $effectiveInMin = $inMinutes;
        if ($inMinutes < $startMinutes && $inMinutes >= ($startMinutes - 60)) {
            $effectiveInMin = $startMinutes;
        }

        $outMinutes = $clockOut ? self::parseTimeToMinutes($clockOut) : $inMinutes;
        
        // Handle overnight shifts if necessary
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }

        $lateMinutes = max(0, $inMinutes - $startMinutes);
        $hoursWorked = max(0, ($outMinutes - $effectiveInMin) / 60);
        $halfExpected = $expectedHours / 2;
        $expectedMinutes = $expectedHours * 60;
        $undertimeMinutes = max(0, $expectedMinutes - ($outMinutes - $effectiveInMin));

        // Check if grace period covers the deviation
        $graceCovered = false;
        if ($dayRule && isset($dayRule['grace_enabled']) && $dayRule['grace_enabled']) {
            $graceMinutes = (int)($dayRule['grace_minutes'] ?? 0);
            $graceType = $dayRule['grace_type'] ?? '-/+';
            
            // Check if this deviation is covered by grace
            if ($lateMinutes <= $graceMinutes && ($graceType === '+' || $graceType === '-/+')) {
                $graceCovered = true;
            } elseif ($undertimeMinutes <= $graceMinutes && ($graceType === '-' || $graceType === '-/+')) {
                $graceCovered = true;
            }
        }

        // If grace covers the deviation, return completed
        if ($graceCovered) {
            return 'completed';
        }

        if ($hoursWorked > $expectedHours) {
            return 'overtime';
        }

        // Severely late or left very early — worked less than half the shift
        if ($hoursWorked < $halfExpected) {
            return 'undertime';
        }

        // Late arrival takes precedence over half_day (e.g. arrived late, clocked out at window)
        if ($lateMinutes > 0) {
            return 'late';
        }

        // On time but left early (worked >= half but < full shift)
        if ($hoursWorked < $expectedHours) {
            return 'half_day';
        }

        return 'completed';
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
     * Calculate attendance status for flexi schedules (no fixed start/end time).
     * Status is purely based on hours worked vs required hours.
     */
    public static function calculateFlexiStatus(?string $clockIn, ?string $clockOut, int $requiredHours): string
    {
        if (!$clockIn) return 'absent';
        if (!$clockOut) return 'working';

        $inMin  = self::parseTimeToMinutes($clockIn);
        $outMin = self::parseTimeToMinutes($clockOut);
        if ($outMin < $inMin) $outMin += 1440;

        $hoursWorked = ($outMin - $inMin) / 60;

        if ($hoursWorked >= $requiredHours) {
            return $hoursWorked > $requiredHours ? 'overtime' : 'completed';
        }

        return $hoursWorked >= ($requiredHours / 2) ? 'half_day' : 'undertime';
    }

    /**
     * Calculate detailed metrics for payroll on flexi schedules (no fixed start/end time).
     */
    public static function calculateFlexiDetails(?string $clockIn, ?string $clockOut, int $requiredHours): array
    {
        if (!$clockIn || !$clockOut) {
            return [
                'hours_worked'      => 0,
                'overtime_hours'    => 0,
                'late_minutes'      => 0,
                'undertime_minutes' => 0,
                'status'            => $clockIn ? 'working' : 'absent'
            ];
        }

        $inMin  = self::parseTimeToMinutes($clockIn);
        $outMin = self::parseTimeToMinutes($clockOut);
        if ($outMin < $inMin) $outMin += 1440;

        $minutesWorked = $outMin - $inMin;
        $hoursWorked   = $minutesWorked / 60;
        $overtimeHours = max(0, $hoursWorked - $requiredHours);
        $undertimeMin  = max(0, ($requiredHours * 60) - $minutesWorked);

        return [
            'hours_worked'      => $hoursWorked,
            'overtime_hours'    => $overtimeHours,
            'late_minutes'      => 0,
            'undertime_minutes' => $undertimeMin,
            'status'            => self::calculateFlexiStatus($clockIn, $clockOut, $requiredHours)
        ];
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

        // Treat early clock-in within the hour as "normal" start for duration/OT
        $effectiveInMin = $inMin;
        if ($inMin < $startMin && $inMin >= ($startMin - 60)) {
            $effectiveInMin = $startMin;
        }

        if ($outMin < $inMin) $outMin += 1440;

        $minutesWorked = max(0, $outMin - $effectiveInMin);
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