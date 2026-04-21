<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class LeaveRequest extends Model
{
    protected $fillable = ['employee_id', 'leave_type', 'start_date', 'end_date', 'days_requested', 'reason', 'status', 'approver_id', 'rejection_reason'];
    
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function getDaysRequestedAttribute($value)
    {
        if (!empty($value) && (int) $value > 0) {
            return (int) $value;
        }

        if (!$this->start_date || !$this->end_date) {
            return (int) $value;
        }

        return $this->calculateDaysRequested();
    }

    public function calculateDaysRequested(): int
    {
        if (!$this->start_date || !$this->end_date) {
            return 0;
        }

        $startDate = Carbon::parse($this->start_date)->startOfDay();
        $endDate = Carbon::parse($this->end_date)->startOfDay();

        if ($endDate->lt($startDate)) {
            return 0;
        }

        if (SystemSettings::get('leave_include_weekends', false)) {
            return $startDate->diffInDays($endDate) + 1;
        }

        $daysRequested = 0;

        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            if ($date->isWeekday()) {
                $daysRequested++;
            }
        }

        return $daysRequested;
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveRequest $leaveRequest) {
            if ($leaveRequest->start_date && $leaveRequest->end_date) {
                $leaveRequest->days_requested = $leaveRequest->calculateDaysRequested();
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type', 'code');
    }
}
