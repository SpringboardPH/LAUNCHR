<?php

namespace Tests\Unit\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(['draft' => 1], $r['_meta']['excluded_payrolls']);
        $this->assertContains("1 payroll(s) in 'draft' status were not counted.", $r['_meta']['warnings']);
    }

    public function test_a_payroll_in_an_unexpected_status_is_reported_not_silently_dropped(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-07-26', '2026-08-10', 10000);
        $this->payroll($e, '2026-08-11', '2026-08-25', 10000, [], [], 'cancelled');

        $r = BirAggregationService::monthlyWithholding(2026, 8);

        $this->assertSame('10000.00', $r['total_compensation']);
        $this->assertSame(['cancelled' => 1], $r['_meta']['excluded_payrolls']);
        $this->assertContains("1 payroll(s) in 'cancelled' status were not counted.", $r['_meta']['warnings']);
    }

    public function test_a_cutoff_ending_in_january_belongs_to_the_next_year(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-12-11', '2026-12-25', 10000);
        $this->payroll($e, '2026-12-26', '2027-01-10', 10000);

        $this->assertSame(1, BirAggregationService::yearQuery(2026)->count());
        $this->assertSame(1, BirAggregationService::yearQuery(2027)->count());
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
        $this->assertFalse($r['has_taxes_withheld']);
        $this->assertSame('08/2026', $r['return_period']);
        // All zeros reads as a finished form; the warning is what says otherwise.
        $this->assertSame(['No finalized or paid payroll for 2026-08.'], $r['_meta']['warnings']);
        // Contract §3: a payroll-filled value is never null; §7: money is a plain decimal string.
        foreach (array_diff_key($fields, ['has_taxes_withheld' => true, 'return_period' => true]) as $key => $value) {
            $this->assertSame('0.00', $value, $key);
        }
    }

        public function test_a_december_13th_month_goes_to_item_17_and_keeps_an_exempt_employee_exempt(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-12-01', '2026-12-15', 7500, ['SSS EE Contribution' => 500]);
        $this->payroll($e, '2026-12-16', '2026-12-31', 22500, ['SSS EE Contribution' => 500],
            [['label' => '13th Month Pay', 'amount' => 15000]]);

        $r = BirAggregationService::monthlyWithholding(2026, 12);

        // Counted as pay, the 13th month would project to (29,000 x 12) and look taxable.
        $this->assertSame('15000.00', $r['thirteenth_month_and_benefits']);
        $this->assertSame('14000.00', $r['exempt_250k_compensation']); // 30,000 - 15,000 - 1,000 EE
        $this->assertSame('0.00', $r['net_taxable_compensation']);
        $this->assertSame(1, $r['_meta']['employee_counts']['exempt_250k']);
    }

    /*
     * 2316 — the accountant's three samples (2316_TAXABLE_EMPLOYEE, _TAXABLE_BUT_BELOW_THRESHOLD,
     * _NON_TAXABLE_MWE) with the monthly pay from SAMPLE_1601C_JAN-DEC_2026. Basic is net of the EE
     * share, as on the MWE sample. The other two samples count the EE share twice (basic in full
     * plus item 36), so their items 29/39, 19 and 24 differ from these until the accountant confirms.
     */

    public function test_2316_minimum_wage_earner_matches_the_accountant_sample(): void
    {
        $e = $this->employee('C');
        $this->setMwe([$e->id]);
        foreach ($this->cutoffs2026() as $i => [$start, $end]) {
            // 18,127.916 x 12 = 217,535.00 for the year; the last cutoff absorbs payroll's cent rounding.
            $allowances = [];
            if ($i < 6) {
                $allowances[] = ['label' => 'Special Holiday', 'amount' => 695]; // 6 x 695 = 4,170
            }
            if ($i === 23) {
                $allowances[] = ['label' => '13th Month Pay', 'amount' => 18127.91];
            }
            $basic = $i === 23 ? 9063.92 : 9063.96;
            $this->payroll($e, $start, $end, $basic + array_sum(array_column($allowances, 'amount')),
                ['SSS EE Contribution' => 775], $allowances);
        }

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        $this->assertSame('198935.00', $r['nontax_mwe_basic']);               // 29
        $this->assertSame('4170.00', $r['nontax_mwe_holiday']);               // 30
        $this->assertSame('18127.91', $r['nontax_thirteenth_month']);         // 34
        $this->assertSame('18600.00', $r['nontax_statutory_contributions']);  // 36
        $this->assertSame('239832.91', $r['nontax_total']);                   // 38
        $this->assertSame('0.00', $r['tax_regular_total']);                   // 52
        $this->assertSame('239832.91', $r['gross_compensation_present']);    // 19
        $this->assertSame('0.00', $r['tax_due']);                             // 24
        $this->assertSame('mwe', $r['_meta']['category']);
        $this->assertSame([], $r['_meta']['warnings']);
    }

    public function test_2316_employee_at_or_under_250k_puts_basic_in_item_29(): void
    {
        $e = $this->employee('B');
        foreach ($this->cutoffs2026() as $i => [$start, $end]) {
            $thirteenth = $i === 23 ? [['label' => '13th Month Pay', 'amount' => 20000]] : [];
            $this->payroll($e, $start, $end, $i === 23 ? 30000 : 10000, ['SSS EE Contribution' => 850], $thirteenth);
        }

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        // Sample: 240,000 and 280,400.
        $this->assertSame('219600.00', $r['nontax_mwe_basic']);               // 29 = 240,000 - 20,400
        $this->assertSame('20000.00', $r['nontax_thirteenth_month']);         // 34
        $this->assertSame('20400.00', $r['nontax_statutory_contributions']);  // 36
        $this->assertSame('260000.00', $r['nontax_total']);                   // 38
        $this->assertSame('0.00', $r['tax_basic_salary']);                    // 39
        $this->assertSame('260000.00', $r['gross_compensation_present']);    // 19
        $this->assertSame('0.00', $r['tax_due']);                             // 24
        $this->assertSame('exempt_250k', $r['_meta']['category']);
    }

    public function test_2316_taxable_employee_uses_the_annual_tax_table(): void
    {
        $e = $this->employee('A');
        $e->update(['tin_number' => '123456789']);
        foreach ($this->cutoffs2026() as $i => [$start, $end]) {
            $thirteenth = $i === 23 ? [['label' => '13th Month Pay', 'amount' => 27000]] : [];
            // 295.58 is payroll's semi-monthly tax on 13,500 - 1,112.50 EE.
            $this->payroll($e, $start, $end, $i === 23 ? 40500 : 13500,
                ['SSS EE Contribution' => 1112.50, 'Withholding Tax' => 295.58], $thirteenth);
        }

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        // Sample: 324,000 taxable and 11,100 tax.
        $this->assertSame('0.00', $r['nontax_mwe_basic']);                    // 29
        $this->assertSame('27000.00', $r['nontax_thirteenth_month']);         // 34
        $this->assertSame('26700.00', $r['nontax_statutory_contributions']);  // 36
        $this->assertSame('53700.00', $r['nontax_total']);                    // 38
        $this->assertSame('297300.00', $r['tax_basic_salary']);               // 39 = 324,000 - 26,700
        $this->assertSame('297300.00', $r['tax_regular_total']);              // 52
        $this->assertSame('351000.00', $r['gross_compensation_present']);    // 19
        $this->assertSame('297300.00', $r['gross_taxable_income']);          // 23
        $this->assertSame('7095.00', $r['tax_due']);                          // 24 = 47,300 x 15%
        $this->assertSame('7093.92', $r['taxes_withheld_present']);           // 25A = 24 x 295.58
        $this->assertSame('7093.92', $r['total_taxes_withheld_final']);       // 28
        $this->assertSame('123-456-789-000', $r['employee_tin']);
        $this->assertSame('A', $r['employee_last_name']);
        $this->assertSame('taxable', $r['_meta']['category']);
        $this->assertSame(['Tax due 7,095.00 vs. withheld 7,093.92: 1.08 under-withheld. Needs a year-end adjustment.'],
            $r['_meta']['warnings']);
    }

    public function test_2316_mid_year_hire_is_judged_on_actual_pay_not_a_full_year(): void
    {
        $e = $this->employee('H');
        $e->update(['hire_date' => '2026-07-01']);
        foreach ($this->cutoffs2026(7) as $i => [$start, $end]) {
            $thirteenth = $i === 11 ? [['label' => '13th Month Pay', 'amount' => 20000]] : [];
            // Payroll withholds per cutoff as if the pay ran all year.
            $this->payroll($e, $start, $end, $i === 11 ? 40000 : 20000,
                ['SSS EE Contribution' => 1000, 'Withholding Tax' => 1404.30], $thirteenth);
        }

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        // 40,000 a month, but only six months: 260,000 - 20,000 13th - 12,000 EE = 228,000.
        $this->assertSame('exempt_250k', $r['_meta']['category']);
        $this->assertSame('228000.00', $r['nontax_mwe_basic']);
        $this->assertSame('260000.00', $r['gross_compensation_present']);
        $this->assertSame('0.00', $r['tax_due']);
        $this->assertSame('16851.60', $r['taxes_withheld_present']);
        $this->assertSame(['from' => '2026-07-01', 'to' => '2026-12-31'], $r['_meta']['payroll_range']);
        $this->assertSame(['Tax due 0.00 vs. withheld 16,851.60: 16,851.60 over-withheld. Needs a year-end adjustment.'],
            $r['_meta']['warnings']);
    }

    public function test_2316_13th_month_over_90k_overtime_and_off_book_pay(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-06-01', '2026-06-30', 207000, ['Late' => 500, 'SSS EE Contribution' => 1000],
            [['label' => 'Overtime Pay', 'amount' => 2000], ['label' => 'Allowance', 'amount' => 5000]]);
        $this->payroll($e, '2026-12-01', '2026-12-31', 300000, ['SSS EE Contribution' => 1000],
            [['label' => '13th Month Pay', 'amount' => 100000]]);

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        // 507,000 gross - 5,000 undeclared - 500 late = 501,500.
        $this->assertSame('501500.00', $r['gross_compensation_present']);    // 19
        $this->assertSame('90000.00', $r['nontax_thirteenth_month']);         // 34 capped
        $this->assertSame('10000.00', $r['tax_thirteenth_month_excess']);     // 48
        $this->assertSame('2000.00', $r['tax_overtime']);                     // 50
        $this->assertSame('397500.00', $r['tax_basic_salary']);               // 39
        $this->assertSame('409500.00', $r['tax_regular_total']);              // 52
        $this->assertSame('24400.00', $r['tax_due']);                         // 22,500 + 9,500 x 20%
    }

    public function test_2316_premium_pay_below_250k_goes_to_item_37(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-06-01', '2026-06-15', 10800, [],
            [['label' => 'Special Holiday', 'amount' => 500], ['label' => 'Overtime Pay', 'amount' => 300]]);

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        $this->assertSame('10000.00', $r['nontax_mwe_basic']);                // 29
        $this->assertSame('0.00', $r['nontax_mwe_holiday']);                  // 30 is MWE-only
        $this->assertSame('800.00', $r['nontax_other_mwe_compensation']);     // 37
        $this->assertContains("No '13th Month Pay' in this year's payroll. If it was paid outside payroll, item 34 is short.",
            $r['_meta']['warnings']);
    }

    public function test_2316_counts_only_this_employees_counted_payrolls_in_the_year(): void
    {
        $e = $this->employee('X');
        $this->payroll($e, '2026-06-01', '2026-06-15', 10000);
        $this->payroll($e, '2026-06-16', '2026-06-30', 10000, [], [], 'draft');
        $this->payroll($e, '2025-12-11', '2025-12-25', 10000);
        $this->payroll($e, '2026-12-26', '2027-01-10', 10000);
        $this->payroll($this->employee('Y'), '2026-06-01', '2026-06-15', 10000);

        $r = BirAggregationService::annualCompensation($e->id, 2026);

        $this->assertSame('10000.00', $r['gross_compensation_present']);
        $this->assertSame(1, $r['_meta']['payroll_count']);
        $this->assertSame(['draft' => 1], $r['_meta']['excluded_payrolls']);
    }

    public function test_2316_with_no_payroll_returns_every_schema_key_and_says_why(): void
    {
        $e = $this->employee('X');

        $r = BirAggregationService::annualCompensation($e->id, 2026);
        $fields = array_diff_key($r, ['_meta' => true]);

        $this->assertEqualsCanonicalizing(Form2316Schema::payrollDerivedKeys(), array_keys($fields));
        $identity = ['employee_tin', 'employee_last_name', 'employee_first_name', 'employee_contact_number',
            'tax_year', 'period_from', 'period_to', 'is_mwe'];
        foreach (array_diff_key($fields, array_flip($identity)) as $key => $value) {
            $this->assertSame('0.00', $value, $key);
        }
        $this->assertNull($r['_meta']['payroll_range']);
        $this->assertSame(['No finalized or paid payroll for 2026.'], $r['_meta']['warnings']);
    }

    #[DataProvider('annualTaxTable')]
    public function test_annual_tax_due_follows_the_train_table(float $taxable, float $expected): void
    {
        $this->assertSame($expected, BirAggregationService::annualTaxDue($taxable));
    }

    public static function annualTaxTable(): array
    {
        return [
            'at the exempt line' => [250_000, 0.0],
            '15% band' => [297_300, 7_095.0],
            'top of 15% band' => [400_000, 22_500.0],
            '20% band' => [409_500, 24_400.0],
            'top of 20% band' => [800_000, 102_500.0],
            'top of 25% band' => [2_000_000, 402_500.0],
            'top of 30% band' => [8_000_000, 2_202_500.0],
            '35% band' => [9_000_000, 2_552_500.0],
        ];
    }

    /** Two semi-monthly cutoffs per month, from $fromMonth through December 2026. */
    private function cutoffs2026(int $fromMonth = 1): array
    {
        $cutoffs = [];
        for ($m = $fromMonth; $m <= 12; $m++) {
            $cutoffs[] = [sprintf('2026-%02d-01', $m), sprintf('2026-%02d-15', $m)];
            $cutoffs[] = [sprintf('2026-%02d-16', $m), date('Y-m-t', mktime(0, 0, 0, $m, 1, 2026))];
        }
        return $cutoffs;
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
            'tin_number' => '000-000-000-000',
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
