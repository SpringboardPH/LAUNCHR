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

        // Carry-forward fallback: use the most recent prior active schedule,
        // but skip temporary templates — they should not bleed into future weeks.
        return self::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereDate('end_date', '<', $dateString)
            ->whereHas('template', fn ($q) => $q->where('is_temporary', false))
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
            ->orderBy('start_date', 'desc')
            ->first();

        if ($currentSchedule) {
            return $currentSchedule;
        }

        // Carry forward latest prior active schedule when no explicit current week entry exists,
        // but skip temporary templates — they should not bleed into future weeks.
        return self::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('end_date', '<', $weekStart)
            ->whereHas('template', fn ($q) => $q->where('is_temporary', false))
            ->with('template')
            ->orderByDesc('end_date')
            ->orderByDesc('updated_at')
            ->first();
    }
}
