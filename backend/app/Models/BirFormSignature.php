<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

/**
 * One signature on a finalized BIR form (contract: docs/bir-api-contract.md). The image is a
 * frozen copy on the private disk. The path, IP, user agent and hash are hidden: they never
 * reach API JSON, and the Auditable trait leaves hidden attributes out of audit_logs.
 */
class BirFormSignature extends Model
{
    use Auditable;

    public const EMPLOYER = 'employer';

    public const EMPLOYEE = 'employee';

    protected $fillable = [
        'bir_form_draft_id',
        'role',
        'user_id',
        'image_path',
        'signed_at',
        'ip',
        'user_agent',
        'fields_hash',
    ];

    protected $hidden = ['image_path', 'ip', 'user_agent', 'fields_hash'];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function draft()
    {
        return $this->belongsTo(BirFormDraft::class, 'bir_form_draft_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
