<?php

namespace App\Models;

use App\Helpers\SystemClock;
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

        $activeSchedule = self::where('employee_id', $employeeId)
            ->whereDate('start_date', '<=', $dateString)
            ->whereDate('end_date', '>=', $dateString)
            ->where('status', 'active')
            ->with('template')
            ->orderByDesc('updated_at')
            ->first();

        if ($activeSchedule) {
            return $activeSchedule;
        }

        // Carry-forward fallback: use the most recent prior active schedule
        // when no explicit schedule exists for the date.
        return self::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereDate('end_date', '<', $dateString)
            ->with('template')
            ->orderByDesc('end_date')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Get current week's schedule for employee (if assigned)
     */
    public static function getCurrentForEmployee($employeeId)
    {
        $now = SystemClock::now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        $currentSchedule = self::where('employee_id', $employeeId)
            ->where('start_date', '<=', $weekEnd)
            ->where('end_date', '>=', $weekStart)
            ->where('status', 'active')
            ->with('template')
            ->first();

        if ($currentSchedule) {
            return $currentSchedule;
        }

        // Carry forward latest prior active schedule when no explicit current week entry exists.
        return self::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('end_date', '<', $weekStart)
            ->with('template')
            ->orderByDesc('end_date')
            ->orderByDesc('updated_at')
            ->first();
    }
}
