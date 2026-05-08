<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'position',
        'department',
        'hire_date',
        'salary',
        'undeclared_salary',
        'rate_type',
        'status',
        'notes',
        'bank_account_number',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'undeclared_salary' => 'decimal:2',
    ];

    protected $appends = ['full_name', 'schedule'];

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getScheduleAttribute()
    {
        $currentSchedule = EmployeeSchedule::getCurrentForEmployee($this->id);
        if ($currentSchedule && $currentSchedule->template) {
            $t = $currentSchedule->template;
            $daysArr = [
                0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday',
                3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'
            ];
            
            $workDays = $t->work_days ?? [1, 2, 3, 4, 5];
            sort($workDays);
            
            $daysStr = '';
            if ($workDays == [1, 2, 3, 4, 5]) {
                $daysStr = 'Monday - Friday';
            } elseif ($workDays == [1, 2, 3, 4, 5, 6]) {
                $daysStr = 'Monday - Saturday';
            } else {
                $daysStr = collect($workDays)->map(fn($d) => $daysArr[$d] ?? '')->filter()->implode(', ');
            }
            
            return [
                'name' => $t->name,
                'days' => $daysStr,
                'start_time' => \Carbon\Carbon::parse($t->work_start_time)->format('gA'),
                'end_time' => \Carbon\Carbon::parse($t->work_end_time)->format('gA'),
            ];
        }
        return null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function schedules()
    {
        return $this->hasMany(EmployeeSchedule::class);
    }
}
