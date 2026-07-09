<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    protected $fillable = ['loan_id', 'payroll_id', 'amount', 'cutoff_start', 'cutoff_end'];

    protected $casts = [
        'amount' => 'decimal:2',
        'cutoff_start' => 'date',
        'cutoff_end' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
