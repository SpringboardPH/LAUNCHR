<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTemplate extends Model
{
    protected $fillable = [
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
