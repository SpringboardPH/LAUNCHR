<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSchedule extends Model
{
    protected $fillable = [
        'employee_id',
        'schedule_template_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function template()
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }

    /**
     * Get the schedule for a specific employee on a specific date
     */
    public static function getForEmployeeOnDate($employeeId, $date)
    {
        $date = \Carbon\Carbon::parse($date);
        $dateString = $date->toDateString();
        
        return self::where('employee_id', $employeeId)
            ->whereDate('start_date', '<=', $dateString)
            ->whereDate('end_date', '>=', $dateString)
            ->with('template')
            // Keep historical schedule context even if a record becomes archived/inactive later.
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Get current week's schedule for employee (if assigned)
     */
    public static function getCurrentForEmployee($employeeId)
    {
        $now = \Carbon\Carbon::now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        return self::where('employee_id', $employeeId)
            ->where('start_date', '<=', $weekEnd)
            ->where('end_date', '>=', $weekStart)
            ->where('status', 'active')
            ->with('template')
            ->first();
    }
}
