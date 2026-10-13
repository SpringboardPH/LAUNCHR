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

    /**
     * Statuses whose fields can be changed with PUT. pending is included so the approver can
     * correct a submitted form and approve it in the same step (contract §5). Read by both
     * UpdateBirDraftFieldsRequest and BirFormController::update(), so they can't drift apart.
     */
    public const EDITABLE_STATUSES = ['draft', 'pending'];

    /**
     * Roles that may edit a pending form; anyone else gets 403 (contract §5). A draft can be
     * edited by every role that reaches /bir. Read by UpdateBirDraftFieldsRequest::refusedByRole().
     */
    public const PENDING_EDITOR_ROLES = ['accounting'];

    /**
     * How many times a filed form may be amended, by form type: a 1601-C five times per
     * month (supervisor's rule). Each amendment is one version after the original, however
     * many boxes it changes. A form type not listed has no limit (the 2316). Read by revise()
     * and by the chat (BirFormFinder::amendmentLimit), so the button and the chat agree.
     */
    public const AMENDMENT_LIMITS = ['1601-C' => 5];

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

    /** Amendments made to this form so far: its highest version, less the original. */
    public function amendmentsMade(): int
    {
        return (int) self::where('form_type', $this->form_type)
            ->where('period', $this->period)
            ->where('employee_id', $this->employee_id)
            ->max('version') - 1;
    }

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
