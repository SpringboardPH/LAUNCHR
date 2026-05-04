<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'cutoff_start',
        'cutoff_end',
        'base_salary',
        'total_hours',
        'days_worked',
        'overtime_hours',
        'late_minutes',
        'undertime_minutes',
        'gross_pay',
        'deductions',
        'allowances',
        'net_pay',
        'status',
        'processed_at',
        'paid_at',
    ];

    protected $casts = [
        'cutoff_start' => 'date',
        'cutoff_end' => 'date',
        'base_salary' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'deductions' => 'array',
        'allowances' => 'array',
        'processed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
