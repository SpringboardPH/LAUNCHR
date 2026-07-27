<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirteenthMonthSetting extends Model
{
    protected $table    = 'thirteenth_month_employee_settings';
    protected $fillable = ['employee_id', 'year', 'mode', 'excluded_months'];

    protected $casts = ['excluded_months' => 'array'];
}
