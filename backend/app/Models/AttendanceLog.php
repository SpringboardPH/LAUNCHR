<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class AttendanceLog extends Model
{
    use Auditable;
    protected $fillable = [
        'employee_id',
        'date',
        'clock_in_time',
        'clock_out_time',
        'notes',
        'clock_in_notes',
        'clock_out_notes',
        'status',
        'schedule_template_id',
        'schedule_template_name',
    ];
    
    protected $casts = [
        'date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
