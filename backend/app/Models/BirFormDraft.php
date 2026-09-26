<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class BirFormDraft extends Model
{
    use Auditable;
    use HasFactory;

    protected $table = 'bir_form_drafts';

    protected $fillable = [
        'form_type',
        'period',
        'employee_id',
        'status',
        'version',
        'parent_id',
        'fields',
        'source_snapshot',
        'validation_errors',
        'prepared_by',
        'approved_by',
        'rejection_reason',
    ];

    // Never serialised to the API. Hiding it also keeps the frozen payroll rows out of audit_logs.
    protected $hidden = ['source_snapshot'];

    // Known quirk of the shared Auditable trait: for fields and validation_errors, old_values
    // holds a decoded array while new_values holds a JSON string. Accepted, not worked around.
    protected $casts = [
        'fields' => 'array',
        'source_snapshot' => 'array',
        'validation_errors' => 'array',
        'version' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
