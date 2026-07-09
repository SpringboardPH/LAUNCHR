<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;
use Illuminate\Support\Facades\DB;

class LoanService
{
    // ponytail: flat add-on interest; swap for declining-balance only if finance asks.
    const DEDUCTION_LABELS = [
        'sss_salary'    => 'SSS Loan',
        'sss_calamity'  => 'SSS Loan',
        'pagibig_mpl'   => 'Pag-IBIG Loan',
        'cash_advance'  => 'Cash Advance/Others',
        'company_loan'  => 'Cash Advance/Others',
    ];

    public static function computeSchedule(float $principal, float $interestRate, int $termCount): array
    {
        $totalPayable = round($principal * (1 + $interestRate), 2);
        $installmentAmount = round($totalPayable / $termCount, 2);

        return [$totalPayable, $installmentAmount];
    }

    /**
     * Undo any loan charges already tied to this payroll run (idempotency for regeneration).
     */
    public static function reverseForPayroll(int $payrollId): void
    {
        DB::transaction(function () use ($payrollId) {
            $payments = LoanPayment::where('payroll_id', $payrollId)->get();

            foreach ($payments as $payment) {
                $loan = Loan::find($payment->loan_id);
                if ($loan) {
                    $loan->balance = round($loan->balance + $payment->amount, 2);
                    if ($loan->status === 'paid_off' && $loan->balance > 0) {
                        $loan->status = 'active';
                    }
                    $loan->save();
                }
                $payment->delete();
            }
        });
    }

    /**
     * Charge each active, started loan for this employee's cutoff, oldest first,
     * capped so net pay never drops below $floor. Returns a deductions map
     * (grouped by payslip label) and the total charged.
     */
    public static function chargeForPayroll(
        int $employeeId,
        string $cutoffStart,
        string $cutoffEnd,
        int $payrollId,
        float $preLoanNet,
        float $floor
    ): array {
        $loans = Loan::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('start_cutoff', '<=', $cutoffStart)
            ->orderBy('start_cutoff')
            ->orderBy('id')
            ->get();

        $deductions = [];

        DB::transaction(function () use ($loans, $payrollId, $cutoffStart, $cutoffEnd, $preLoanNet, $floor, &$deductions) {
            $runningNet = $preLoanNet;

            foreach ($loans as $loan) {
                $netAboveFloor = max(0, $runningNet - $floor);
                $charge = round(min((float) $loan->installment_amount, (float) $loan->balance, $netAboveFloor), 2);

                if ($charge <= 0) {
                    continue;
                }

                LoanPayment::create([
                    'loan_id' => $loan->id,
                    'payroll_id' => $payrollId,
                    'amount' => $charge,
                    'cutoff_start' => $cutoffStart,
                    'cutoff_end' => $cutoffEnd,
                ]);

                $loan->balance = round($loan->balance - $charge, 2);
                $loan->status = $loan->balance <= 0 ? 'paid_off' : $loan->status;
                $loan->save();

                $label = self::DEDUCTION_LABELS[$loan->loan_type] ?? 'Cash Advance/Others';
                $deductions[$label] = round(($deductions[$label] ?? 0) + $charge, 2);
                $runningNet = round($runningNet - $charge, 2);
            }
        });

        return [
            'deductions' => $deductions,
            'total' => round(array_sum($deductions), 2),
        ];
    }
}
