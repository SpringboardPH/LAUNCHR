<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'default_days',
        'requires_balance',
        'is_paid',
        'is_active',
    ];

    protected $casts = [
        'default_days' => 'integer',
        'requires_balance' => 'boolean',
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employeeBalances()
    {
        return $this->hasMany(EmployeeLeaveBalance::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type', 'code');
    }
}