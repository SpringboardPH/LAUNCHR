<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\Auditable;

/**
 * Voided (POST /bir/drafts/{id}/void, drafts only), never deleted: the form stays with status
 * 'void'. The notVoid scope makes every normal query skip it, as it once skipped a deleted form
 * (rows soft-deleted before voiding replaced deleting stay hidden too). The list, the form
 * itself and its history include it (withVoid), and so do the version numbering and the
 * amendment count (everything()): a voided correction still counts, its number is never reused.
 */
class BirFormDraft extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'bir_form_drafts';

    public const VOID = 'void';

    /**
     * Statuses whose fields can be changed with PUT. pending is included so the approver can
     * correct a submitted form and approve it in the same step (contract §5). approved is
     * included because an approved form isn't filed yet (supervisor's rule): changing it sends
     * it back to draft (reopenIfApproved()), to be submitted and approved again. Read by both
     * UpdateBirDraftFieldsRequest and BirFormController::update(), so they can't drift apart.
     */
    public const EDITABLE_STATUSES = ['draft', 'pending', 'approved'];

    /**
     * Statuses only PENDING_EDITOR_ROLES may edit: a form under review, or one already approved
     * (changing it cancels the approval). Read by UpdateBirDraftFieldsRequest::refusedByRole().
     */
    public const REVIEWED_STATUSES = ['pending', 'approved'];

    /**
     * Roles that may edit a pending or approved form (REVIEWED_STATUSES); anyone else gets 403
     * (contract §5). A draft can be edited by every role that reaches /bir.
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
        'void_reason',
        'voided_by',
        'voided_at',
        'signing_method',
        'due_date',
    ];

    /** signing_method values: e-signatures, or a wet signature on the printout. Null = not chosen. */
    public const SIGN_DIGITAL = 'digital';

    public const SIGN_PAPER = 'paper';

    // Never serialised to the API. Hiding it also keeps the frozen payroll rows out of audit_logs.
    protected $hidden = ['source_snapshot'];

    // Known quirk of the shared Auditable trait: for fields and validation_errors, old_values
    // holds a decoded array while new_values holds a JSON string. Accepted, not worked around.
    protected $casts = [
        'fields' => 'array',
        'source_snapshot' => 'array',
        'validation_errors' => 'array',
        'version' => 'integer',
        'voided_at' => 'datetime',
        'due_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('notVoid', fn (Builder $query) => $query->where($query->qualifyColumn('status'), '!=', self::VOID));
    }

    /** Voided forms included: the list, a form opened on its own, its PDF and its history. */
    public static function withVoid(): Builder
    {
        return static::withoutGlobalScope('notVoid');
    }

    /** Every form ever made, voided and soft-deleted ones too: for version numbers and amendment counts. */
    public static function everything(): Builder
    {
        return static::withTrashed()->withoutGlobalScope('notVoid');
    }

    /**
     * The number of the original this form is a version of (its own for an original):
     * parent_id followed back, since revise sets it to the version that was revised.
     */
    public function rootId(): int
    {
        $seen = [];
        $form = $this;
        while ($form->parent_id !== null && !isset($seen[$form->id]) && ($parent = self::everything()->find($form->parent_id)) !== null) {
            $seen[$form->id] = true;
            $form = $parent;
        }

        return $form->id;
    }

    /**
     * When this form is due: the date set in the chat, or the usual one, the 10th of the next
     * month for a 1601-C and January 31 of the next year for a 2316 (as the BIR Forms to-do shows).
     */
    public function dueDate(): ?Carbon
    {
        if ($this->due_date !== null) {
            return $this->due_date->copy();
        }
        if (!preg_match('/^(\d{4})(?:-(\d{2}))?$/', (string) $this->period, $m)) {
            return null;
        }

        return match (true) {
            $this->form_type === '1601-C' && isset($m[2]) => Carbon::create((int) $m[1], (int) $m[2], 1)->addMonthNoOverflow()->day(10),
            $this->form_type === '2316' => Carbon::create((int) $m[1] + 1, 1, 31),
            default => null,
        };
    }

    /** "#64" for an original, "#64v2" for its second version: every version shares the original's number. */
    public function label(): string
    {
        return '#' . $this->rootId() . ($this->version > 1 ? "v{$this->version}" : '');
    }

    /** An approved form that is changed goes back to draft: it has to be submitted and approved again. */
    public function reopenIfApproved(): void
    {
        if ($this->status === 'approved') {
            $this->status = 'draft';
            $this->approved_by = null;
        }
    }

    /**
     * The fields a new version starts with: a copy of the filed one's. On a 1601-C, item 2
     * "Amended Return?" is Yes, since every version after the original is an amended return.
     *
     * @return array<string, mixed>
     */
    public static function fieldsForRevision(self $filed): array
    {
        $fields = $filed->fields ?? [];
        if ($filed->form_type === '1601-C') {
            // Filled in by the system (schema system_filled), not a person's answer.
            $fields['is_amended'] = ['value' => true, 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null];
        }

        return $fields;
    }

    /** Amendments made to this form so far: its highest version, less the original. A voided correction counts. */
    public function amendmentsMade(): int
    {
        return (int) self::everything()->where('form_type', $this->form_type)
            ->where('period', $this->period)
            ->where('employee_id', $this->employee_id)
            ->max('version') - 1;
    }

    /**
     * What this form was built from (BirFormController::buildSourceSnapshot). A revision is a copy
     * of fields only, so it uses the nearest snapshot up its parent chain. Null if there is none.
     */
    public function sourceSnapshot(): ?array
    {
        $from = $this;
        $seen = [];
        while ($from->source_snapshot === null && $from->parent_id !== null && !isset($seen[$from->id])) {
            $seen[$from->id] = true;
            $from = self::find($from->parent_id);
            if ($from === null) {
                return null;
            }
        }

        return $from->source_snapshot;
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

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function signatures()
    {
        return $this->hasMany(BirFormSignature::class);
    }

    public function signatureBy(string $role): ?BirFormSignature
    {
        return $this->signatures->firstWhere('role', $role);
    }

    /**
     * Fully signed: marked for a wet signature, or a 1601-C the employer signed, or a 2316 both the
     * employer and the employee signed. Informational only; it never blocks anything.
     */
    public function signingComplete(): bool
    {
        if ($this->signing_method === self::SIGN_PAPER) {
            return true;
        }

        $employer = $this->signatureBy(BirFormSignature::EMPLOYER) !== null;
        if ($this->form_type !== '2316') {
            return $employer;
        }

        return $employer && $this->signatureBy(BirFormSignature::EMPLOYEE) !== null;
    }
}
