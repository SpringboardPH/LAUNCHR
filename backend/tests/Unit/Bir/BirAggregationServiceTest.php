<?php

namespace Tests\Unit\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Schemas\Form1601CSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * August 2026 hand count from accounting's reference sheet (ref-1601c.xlsx,
     * "SAMPLE 1601C"): [cutoff 1 gross, cutoff 2 gross], monthly EE contribution.
     * Employees B, C, D are the sheet's MWEs; A is its only taxable employee.
     */
    private const AUGUST_2026 = [
        'A' => [[13500, 13500], 2225],
        'B' => [[7825, 6950], 1175],
        'C' => [[9215, 9035], 1550],
        'D' => [[9275, 9730], 1550],
        'E' => [[8626.44, 9500], 1400],
        'F' => [[9500, 8626.44], 1550],
        'G' => [[9080.46, 9080.46], 1700],
        'H' => [[10000, 10000], 1700],
        'I' => [[9500, 9500], 1625],
        'J' => [[9500, 9500], 1625],
        'K' => [[10000, 10000], 1700],
        'L' => [[10000, 9156.55], 1700],
        'M' => [[5402.29, 10000], 1700],
        'N' => [[10000, 10000], 1700],
        'O' => [[10000, 10000], 1700],
        'P' => [[10000, 10000], 1700],
        'Q' => [[10000, 10000], 1700],
        'R' => [[8172.42, 9000], 1550],
        'S' => [[9000, 9000], 1550],
        'T' => [[7500, 7500], 1325],
        'U' => [[10000, 8466.46], 1700],
        'V' => [[6932.907, 9233.23], 1700],
    ];

    public function test_august_2026_matches_accounting_hand_count(): void
    {
        $employees = [];
        foreach (self::AUGUST_2026 as $code => [[$first, $second], $ee]) {
            $employees[$code] = $this->employee($code);
            $half = $ee / 2;
            // T's EE is the only one the sheet breaks down (J30 = 750+375+200) — exercises all three labels.
            $contributions = $code === 'T'
                ? ['SSS EE Contribution' => 375, 'PhilHealth EE Contribution' => 187.5, 'Pag-IBIG EE Contribution' => 100]
                : ['SSS EE Contribution' => $half];
            // A is the sheet's only taxable employee; 295.58 is the semi-monthly bracket tax on 13,500 - 1,112.50.
            $tax = $code === 'A' ? ['Withholding Tax' => 295.58] : [];

            $this->payroll($employees[$code], '2026-07-26', '2026-08-10', $first, $contributions + $tax);
            $this->payroll($employees[$code], '2026-08-11', '2026-08-25', $second, $contributions + $tax);
        }
        $this->setMwe([$employees['B']->id, $employees['C']->id, $employees['D']->id]);

        $r = BirAggregationService::monthlyWithholding(2026, 8);

        // Exact to the centavo; the sheet's 410,807.657 is .66 because payroll stores V's 6,932.907 as 6,932.91.
        $this->assertSame('410807.66', $r['total_compensation']);             // P1  item 14
        $this->assertSame('47755.00', $r['mwe_statutory_wage']);              // P3  item 15
        $this->assertSame('0.00', $r['mwe_premium_pay']);                     // P7  item 16
        $this->assertSame('0.00', $r['thirteenth_month_and_benefits']);       // P8  item 17
        $this->assertSame('35825.00', $r['statutory_contributions_ee']);      // P9  item 19
        $this->assertSame('83580.00', $r['total_nontaxable_compensation']);   // P10 item 21
        $this->assertSame('327227.66', $r['total_taxable_compensation']);     // P13 item 22
        $this->assertSame('302452.66', $r['exempt_250k_compensation']);       // P14 item 23
        $this->assertSame('24775.00', $r['net_taxable_compensation']);        // P35 item 24
        $this->assertSame('591.16', $r['total_taxes_withheld']);              // item 25 (not on sheet)
        $this->assertTrue($r['has_taxes_withheld']);

        $this->assertSame(
            ['total' => 22, 'minimum_wage' => 3, 'taxable' => 1, 'exempt_250k' => 18],
            $r['_meta']['employee_counts']
        );
        $this->assertSame([], $r['_meta']['warnings']);
    }

    public function test_undeclared_allowance_and_attendance_deductions_are_not_compensation(): void
    {
        $this->payroll($this->employee('X'), '2026-08-11', '2026-08-25', 15000,
            ['Late' => 200, 'Absent' => 800, 'SSS EE Contribution' => 500],
            [['label' => 'Allowance', 'amount' => 3000], ['label' => 'Overtime Pay', 'amount' => 500]],
        );

        $r = BirAggregationService::monthlyWithholding(2026, 8);

        // 15,000 gross - 3,000 undeclared - 1,000 attendance; OT pay stays in.
        $this->assertSame('11000.00', $r['total_compensation']);
        $this->assertSame('500.00', $r['statutory_contributions_ee']);
    }

    public function test_mwe_premium_pay_goes_to_item_16_not_item_15(): void
    {
        $mwe = $this->employee('X');
        $this->payroll($mwe, '2026-08-11', '2026-08-25', 9000, ['SSS EE Contribution' => 400], [
            ['label' => 'Overtime Pay', 'amount' => 600],
            ['label' => 'Night Differential (2.5 hrs)', 'amount' => 150],
        ]);
        $this->setMwe([$mwe->id]);

        $r = BirAggregationService::monthlyWithholding(2026, 8);

        $this->assertSame('750.00', $r['mwe_premium_pay']);
        $this->assertSame('7850.00', $r['mwe_statutory_wage']); // 9,000 - 750 premium - 400 EE
        $this->assertSame('0.00', $r['total_taxable_compensation']);
    }

    public function test_only_finalized_and_paid_payrolls_count_and_drafts_are_reported(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-07-26', '2026-08-10', 10000, [], [], 'paid');
        $this->payroll($e, '2026-08-11', '2026-08-25', 10000, [], [], 'draft');

        $r = BirAggregationService::monthlyWithholding(2026, 8);

        $this->assertSame('10000.00', $r['total_compensation']);
        $this->assertSame(1, $r['_meta']['draft_payrolls_excluded']);
    }

    public function test_a_cutoff_counts_toward_the_month_it_ends_in(): void
    {
        $this->payroll($this->employee('X'), '2026-07-26', '2026-08-10', 10000);

        $this->assertSame('0.00', BirAggregationService::monthlyWithholding(2026, 7)['total_compensation']);
        $this->assertSame('10000.00', BirAggregationService::monthlyWithholding(2026, 8)['total_compensation']);
    }

    public function test_withholding_that_contradicts_the_category_is_flagged(): void
    {
        $exempt = $this->employee('X');
        $this->payroll($exempt, '2026-08-11', '2026-08-25', 15000, ['Withholding Tax' => 50]);

        $warnings = BirAggregationService::monthlyWithholding(2026, 8)['_meta']['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("#{$exempt->id}", $warnings[0]);
    }

    public function test_empty_month_returns_every_schema_key_as_a_zero_decimal_string(): void
    {
        $r = BirAggregationService::monthlyWithholding(2026, 8);
        $fields = array_diff_key($r, ['_meta' => true]);

        $this->assertEqualsCanonicalizing(Form1601CSchema::payrollDerivedKeys(), array_keys($fields));
        $this->assertFalse($r['has_taxes_withheld']);
        // Contract §3: a payroll-filled value is never null; §7: money is a plain decimal string.
        foreach (array_diff_key($fields, ['has_taxes_withheld' => true]) as $key => $value) {
            $this->assertSame('0.00', $value, $key);
        }
    }

    private function employee(string $code): Employee
    {
        return Employee::create([
            'employee_id' => "BIR-{$code}",
            'first_name' => 'Employee',
            'last_name' => $code,
            'email' => "bir-{$code}@example.com",
            'position' => 'Staff',
            'hire_date' => '2026-01-01',
            'salary' => 20000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);
    }

    private function payroll(Employee $e, string $start, string $end, float $gross, array $deductions = [], array $allowances = [], string $status = 'finalized'): Payroll
    {
        return Payroll::create([
            'employee_id' => $e->id,
            'cutoff_start' => $start,
            'cutoff_end' => $end,
            'base_salary' => 20000,
            'gross_pay' => $gross,
            'deductions' => $deductions,
            'allowances' => $allowances,
            'status' => $status,
        ]);
    }

    private function setMwe(array $employeeIds): void
    {
        SystemSettings::updateOrCreate(
            ['key' => 'bir_mwe_employee_ids'],
            ['value' => json_encode($employeeIds), 'type' => 'json', 'description' => 'test']
        );
    }
}
