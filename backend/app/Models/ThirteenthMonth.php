<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirteenthMonth extends Model
{
    protected $table = 'thirteenth_month_records';

    protected $fillable = ['employee_id', 'year', 'month', 'basic_pay', 'is_override'];

    protected $casts = [
        'is_override' => 'boolean',
        'basic_pay' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
