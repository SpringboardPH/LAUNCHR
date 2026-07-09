<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class Loan extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'employee_id', 'loan_type', 'principal', 'interest_rate',
        'total_payable', 'installment_amount', 'term_count', 'balance',
        'status', 'request_id', 'start_cutoff', 'approver_id', 'notes',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:4',
        'total_payable' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'start_cutoff' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function request()
    {
        return $this->belongsTo(EmployeeRequest::class, 'request_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }
}
