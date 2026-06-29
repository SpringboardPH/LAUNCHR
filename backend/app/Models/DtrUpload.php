<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DtrUpload extends Model
{
    protected $fillable = [
        'employee_id',
        'cutoff_type',
        'period_label',
        'original_filename',
        'file_path',
        'auto_delete_at',
    ];

    protected $casts = [
        'auto_delete_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
