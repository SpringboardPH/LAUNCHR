<?php

namespace Tests\Unit\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Mappers\Form1601CMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee who leaves mid-month: the 1601-C of that month and the 2316. The last month is
 * judged by the pay actually got (accountant A2 = b), and the final pay's prorated 13th month
 * goes on the 1601-C of the month its cutoff ends in (accountant, 10/2026).
 */
class BirLeaverTest extends TestCase
{
    use RefreshDatabase;

    /** The employee's share each cutoff: SSS 750, PhilHealth 375, Pag-IBIG 100. */
    private const EE = 1225;

    public function test_the_prorated_13th_month_in_the_final_pay_is_on_that_months_1601c(): void
    {
        // 30,000 a month, leaves 08/15; the final pay adds 30,000 x 7.5 / 12 = 18,750.
        $e = $this->leaverUpToJuly(30000);
        $this->pay($e, '2026-08-01', '2026-08-15', 15000, ['13th Month Pay' => 18750], tax: 1000);

        $built = Form1601CMapper::map(2026, 8);
        $fields = $built['fields'];

        $this->assertSame('33750.00', $fields['total_compensation']['value']);            // 14
        $this->assertSame('18750.00', $fields['thirteenth_month_and_benefits']['value']); // 17
        $this->assertSame('1225.00', $fields['statutory_contributions_ee']['value']);     // 19
        // Half a month's pay, 13,775 after the share, x 12 = 165,300: item 23, not 24.
        $this->assertSame('13775.00', $fields['exempt_250k_compensation']['value']);      // 23
        $this->assertSame('0.00', $fields['net_taxable_compensation']['value']);          // 24
        $this->assertSame('1000.00', $fields['total_taxes_withheld']['value']);           // 25
        $this->assertStringContainsString('at or under P250,000', implode(' ', $built['meta']['warnings']));
    }

    public function test_a_final_pay_released_the_next_month_is_on_that_months_1601c(): void
    {
        $e = $this->leaverUpToJuly(30000);
        $this->pay($e, '2026-08-01', '2026-08-15', 15000, tax: 1000);
        $this->pay($e, '2026-08-16', '2026-09-05', 0, ['13th Month Pay' => 18750], ee: 0);

        $this->assertSame('0.00', Form1601CMapper::map(2026, 8)['fields']['thirteenth_month_and_benefits']['value']);
        $september = Form1601CMapper::map(2026, 9)['fields'];
        $this->assertSame('18750.00', $september['total_compensation']['value']);            // 14
        $this->assertSame('18750.00', $september['thirteenth_month_and_benefits']['value']); // 17
        $this->assertSame('0.00', $september['total_taxable_compensation']['value']);        // 22
    }

    public function test_a_final_pay_still_in_draft_is_left_out_and_reported(): void
    {
        $e = $this->leaverUpToJuly(30000);
        $this->pay($e, '2026-08-01', '2026-08-15', 15000, ['13th Month Pay' => 18750], tax: 1000, status: 'draft');

        $built = Form1601CMapper::map(2026, 8);

        $this->assertSame('0.00', $built['fields']['thirteenth_month_and_benefits']['value']);
        $this->assertContains("1 payroll(s) in 'draft' status were not counted.", $built['meta']['warnings']);
    }

    public function test_a_13th_month_over_90000_is_taxable_above_the_cap_and_keeps_the_leaver_taxable(): void
    {
        // 200,000 a month, leaves 08/15: 13th month 125,000, of which 35,000 is over the cap.
        $e = $this->leaverUpToJuly(200000);
        $this->pay($e, '2026-08-01', '2026-08-15', 100000, ['13th Month Pay' => 125000], tax: 25000);

        $fields = Form1601CMapper::map(2026, 8)['fields'];

        $this->assertSame('225000.00', $fields['total_compensation']['value']);          // 14
        $this->assertSame('90000.00', $fields['thirteenth_month_and_benefits']['value']); // 17
        // 225,000 - 90,000 - 1,225 = 133,775, all taxable.
        $this->assertSame('133775.00', $fields['total_taxable_compensation']['value']);   // 22
        $this->assertSame('0.00', $fields['exempt_250k_compensation']['value']);          // 23
        $this->assertSame('133775.00', $fields['net_taxable_compensation']['value']);     // 24
    }

    public function test_the_leavers_2316_ends_at_the_last_cutoff_and_refunds_tax_not_due(): void
    {
        // 15 cutoffs of 15,000 + 18,750 13th month: 206,625 after the share, at or under P250,000,
        // so no tax is due and the 15,000 withheld is refunded (item 25A 0, refund in 1601-C item 26).
        $e = $this->leaverUpToJuly(30000);
        $this->pay($e, '2026-08-01', '2026-08-15', 15000, ['13th Month Pay' => 18750], tax: 1000);

        $v = BirAggregationService::annualCompensation($e->id, 2026);

        $this->assertSame('exempt_250k', $v['_meta']['category']);
        $this->assertSame('08/15', $v['period_to']);                     // 2: last cutoff, as the employee has left
        $this->assertSame('18750.00', $v['nontax_thirteenth_month']);    // 34
        $this->assertSame('0.00', $v['tax_due']);                        // 24
        $this->assertSame('15000.00', $v['taxes_withheld_payroll']);
        $this->assertSame('15000.00', $v['year_end_refund']);
        $this->assertSame('0.00', $v['taxes_withheld_present']);         // 25A
    }

    /** An inactive employee paid 01/2026 to 07/2026, two cutoffs a month. */
    private function leaverUpToJuly(float $salary): Employee
    {
        $e = Employee::create([
            'employee_id' => 'BIR-Q', 'first_name' => 'Employee', 'last_name' => 'Q', 'email' => 'bir-q@example.com',
            'position' => 'Staff', 'hire_date' => '2024-01-01', 'salary' => $salary, 'status' => 'inactive', 'rate_type' => 'monthly',
        ]);
        for ($m = 1; $m <= 7; $m++) {
            $this->pay($e, sprintf('2026-%02d-01', $m), sprintf('2026-%02d-15', $m), $salary / 2, tax: 1000);
            $this->pay($e, sprintf('2026-%02d-16', $m), date('Y-m-t', mktime(0, 0, 0, $m, 1, 2026)), $salary / 2, tax: 1000);
        }

        return $e;
    }

    /** @param  array<string, float>  $allowances  label => amount */
    private function pay(Employee $e, string $start, string $end, float $basic, array $allowances = [],
        float $tax = 0, string $status = 'paid', float $ee = self::EE): void
    {
        $deductions = $ee > 0 ? ['SSS EE Contribution' => 750, 'PhilHealth EE Contribution' => 375, 'Pag-IBIG EE Contribution' => 100] : [];
        Payroll::create([
            'employee_id' => $e->id, 'cutoff_start' => $start, 'cutoff_end' => $end, 'base_salary' => $basic,
            'gross_pay' => $basic + array_sum($allowances),
            'deductions' => $deductions + ($tax > 0 ? ['Withholding Tax' => $tax] : []),
            'allowances' => array_map(fn ($label, $amount) => ['label' => $label, 'amount' => $amount], array_keys($allowances), $allowances),
            'status' => $status,
        ]);
    }
}
