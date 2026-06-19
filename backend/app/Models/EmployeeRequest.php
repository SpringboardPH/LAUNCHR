<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class EmployeeRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'request_type', 'subject', 'details',
        'meta', 'status', 'approver_id', 'response_notes',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
