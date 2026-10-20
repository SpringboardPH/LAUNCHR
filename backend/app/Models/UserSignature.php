<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved signature image (contract: docs/bir-api-contract.md). The file is on the private
 * `local` disk; the path is hidden so it can never reach API JSON.
 */
class UserSignature extends Model
{
    protected $fillable = ['user_id', 'path', 'mime'];

    protected $hidden = ['path'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
