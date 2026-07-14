<?php

namespace App\Models;

use App\Services\AttendanceService;
use Illuminate\Database\Eloquent\Model;

class ScheduleTemplate extends Model
{
    protected $fillable = [
        'type',
        'name',
        'description',
        'is_temporary',
        'work_days',
        'day_rules',
        'clock_in_start',
        'clock_in_end',
        'clock_out_start',
        'clock_out_end',
        'start_time',
        'end_time',
        'work_start_time',
        'work_end_time',
        'late_threshold_minutes',
        'required_hours_per_day',
        'overtime_threshold_hours',
        'expected_hours_per_day',
    ];

    protected $casts = [
        'is_temporary' => 'boolean',
        'work_days' => 'array',
        'day_rules' => 'array',
        'late_threshold_minutes' => 'integer',
        'required_hours_per_day' => 'integer',
        'overtime_threshold_hours' => 'integer',
    ];

    public function employeeSchedules()
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    /**
     * Whether the shift for a given day rule is allowed to cross midnight.
     * This is a permission gate on the template's type, not a pay flag —
     * night differential pay is earned by any schedule type based on
     * clocked hours (see AttendanceService::calculateNightHours()).
     */
    public function wrapsMidnight(?array $dayRule = null): bool
    {
        if ($this->type !== 'night') {
            return false;
        }

        $in = $dayRule['clock_in'] ?? $this->work_start_time;
        $out = $dayRule['clock_out'] ?? $this->work_end_time;

        return $in && $out && AttendanceService::parseTimeToMinutes($out)
                            < AttendanceService::parseTimeToMinutes($in);
    }

    /**
     * The day's clock-out time, preferring the day rule over the template default.
     */
    public function shiftEndFor(?array $dayRule = null): string
    {
        return $dayRule['clock_out'] ?? $this->work_end_time ?? '23:59:59';
    }

    /**
     * Calculate total expected hours for a given date range based on work_days
     */
    public function calculateExpectedHours($startDate, $endDate)
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        
        $workDays = $this->work_days ?? [];
        $totalHours = 0;
        
        while ($start->lte($end)) {
            if (in_array($start->dayOfWeek, $workDays)) {
                $totalHours += $this->expected_hours_per_day;
            }
            $start->addDay();
        }
        
        return $totalHours;
    }
}
