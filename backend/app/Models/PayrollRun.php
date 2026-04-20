<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $fillable = ['period', 'start_date', 'end_date', 'status', 'total_employees', 'total_gross', 'total_deductions', 'total_net', 'notes'];
    
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }
}
