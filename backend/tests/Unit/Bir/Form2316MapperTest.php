<?php

namespace Tests\Unit\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Mappers\Form2316Mapper;
use App\Services\BIR\Schemas\Form2316Schema;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form2316MapperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingsSeeder::class);
        SystemSettings::where('key', 'company_tin')->update(['value' => '123456789']);
    }

    public function test_every_field_shows_where_it_came_from(): void
    {
        $e = $this->employee('B');
        $this->year($e, 20000, 1700);

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];

        $this->assertSame(array_column(Form2316Schema::fields(), 'key'), array_keys($fields));
        $this->assertSame(['value' => 2026, 'origin' => 'payroll'], $this->pick($fields['tax_year']));
        $this->assertSame(['value' => '01/01', 'origin' => 'payroll'], $this->pick($fields['period_from']));
        $this->assertSame(['value' => '12/31', 'origin' => 'payroll'], $this->pick($fields['period_to']));
        $this->assertSame(['value' => false, 'origin' => 'payroll'], $this->pick($fields['is_mwe']));
        $this->assertSame(['value' => '123-456-789-000', 'origin' => 'settings'], $this->pick($fields['present_employer_tin']));
        $this->assertSame(['value' => null, 'origin' => 'pending'], $this->pick($fields['present_employer_name'])); // seed placeholder
        $this->assertSame(['value' => null, 'origin' => 'pending'], $this->pick($fields['employee_rdo_code']));
    }

    public function test_built_totals_equal_the_aggregation(): void
    {
        $e = $this->employee('A');
        $this->year($e, 27000, 2225, 485.05);

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];

        foreach (array_diff_key(BirAggregationService::annualCompensation($e->id, 2026), ['_meta' => true]) as $key => $value) {
            // An empty string (no phone on file) is pending, the same as missing.
            $this->assertSame($value === '' ? null : $value, $fields[$key]['value'], $key);
        }
    }

    public function test_mid_year_hire_and_leaver_get_their_period_worked(): void
    {
        $hire = $this->employee('H', '2026-07-01');
        $this->year($hire, 20000, 1700, 0, 7);
        $left = $this->employee('L', '2025-01-01', 'inactive');
        $this->year($left, 20000, 1700, 0, 1, 9);

        $this->assertSame(['07/01', '12/31'], $this->period(Form2316Mapper::map($hire->id, 2026)['fields']));
        $this->assertSame(['01/01', '09/30'], $this->period(Form2316Mapper::map($left->id, 2026)['fields']));
    }

    public function test_a_previous_employer_can_make_a_p250k_employee_taxable(): void
    {
        // 20,000 a month: 219,600 basic after EE, at or under P250,000 on its own.
        $e = $this->employee('B');
        $this->year($e, 20000, 1700);
        $fields = Form2316Mapper::map($e->id, 2026)['fields'];
        $this->assertSame('219600.00', $fields['nontax_mwe_basic']['value']);

        $fields = Form2316Mapper::totals($this->answer($fields, [
            'has_previous_employer' => true, 'taxable_income_previous_employer' => '100000.00', 'taxes_withheld_previous' => '3000.00',
        ]));

        $this->assertSame('0.00', $fields['nontax_mwe_basic']['value']);            // 29: no longer exempt
        $this->assertSame('219600.00', $fields['tax_basic_salary']['value']);       // 39
        $this->assertSame('219600.00', $fields['tax_regular_total']['value']);      // 52
        $this->assertSame('260000.00', $fields['gross_compensation_present']['value']); // 19 unchanged: same pay, other box
        $this->assertSame('319600.00', $fields['gross_taxable_income']['value']);   // 23 = 219,600 + 100,000
        $this->assertSame('10440.00', $fields['tax_due']['value']);                 // 24 = 69,600 x 15%
        $this->assertSame('3000.00', $fields['total_taxes_withheld_adjusted']['value']); // 26 = 0 + 3,000
        $this->assertSame('3000.00', $fields['total_taxes_withheld_final']['value']);    // 28

        // Taking the previous employer's pay away puts it back under P250,000.
        $fields = Form2316Mapper::totals($this->answer($fields, ['taxable_income_previous_employer' => '0.00']));
        $this->assertSame('219600.00', $fields['nontax_mwe_basic']['value']);
        $this->assertSame('0.00', $fields['tax_basic_salary']['value']);
        $this->assertSame('0.00', $fields['tax_due']['value']);
    }

    public function test_other_taxable_pay_entered_by_the_user_is_taxed(): void
    {
        $e = $this->employee('A');
        $this->year($e, 27000, 2225, 485.05); // 297,300 taxable: tax due 7,095

        $fields = Form2316Mapper::totals($this->answer(Form2316Mapper::map($e->id, 2026)['fields'], [
            'tax_others_51a_desc' => 'Commission paid outside payroll', 'tax_others_51a_amount' => '10000.00',
            'pera_tax_credit' => '100.00',
        ]));

        $this->assertSame('307300.00', $fields['tax_regular_total']['value']);      // 52
        $this->assertSame('361000.00', $fields['gross_compensation_present']['value']); // 19 = 351,000 + 10,000
        $this->assertSame('307300.00', $fields['taxable_income_present']['value']); // 21
        $this->assertSame('8595.00', $fields['tax_due']['value']);                  // (307,300 - 250,000) x 15%
        $this->assertSame('5920.60', $fields['total_taxes_withheld_final']['value']); // 28 = 5,820.60 + 100
    }

    public function test_an_mwe_stays_exempt_and_only_extra_taxable_pay_is_taxed(): void
    {
        $e = $this->employee('C');
        SystemSettings::updateOrCreate(['key' => 'bir_mwe_employee_ids'], ['value' => json_encode([$e->id]), 'type' => 'json', 'description' => 'test']);
        $this->year($e, 18127.92, 1550);

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];
        $this->assertTrue($fields['is_mwe']['value']);
        $this->assertSame('0.00', $fields['tax_due']['value']);

        $fields = Form2316Mapper::totals($this->answer($fields, ['tax_others_51a_amount' => '260000.00']));

        $this->assertSame('198935.04', $fields['nontax_mwe_basic']['value']);       // 29 stays: MWE pay is exempt
        $this->assertSame('260000.00', $fields['tax_regular_total']['value']);
        $this->assertSame('1500.00', $fields['tax_due']['value']);                  // 10,000 x 15%
    }

    public function test_hand_edited_basic_pay_is_left_where_it_is(): void
    {
        $e = $this->employee('B');
        $this->year($e, 20000, 1700);
        $fields = Form2316Mapper::map($e->id, 2026)['fields'];
        $fields['nontax_mwe_basic'] = ['value' => '219600.00', 'origin' => 'user', 'edited' => true] + $fields['nontax_mwe_basic'];

        $fields = Form2316Mapper::totals($this->answer($fields, ['taxable_income_previous_employer' => '100000.00']));

        $this->assertSame('219600.00', $fields['nontax_mwe_basic']['value']);
        $this->assertSame('0.00', $fields['tax_basic_salary']['value']);
        $this->assertSame('100000.00', $fields['gross_taxable_income']['value']);
    }

    // ── Awkward cases (Week 6) ──────────────────────────────────────────────
    // Each builds the 2316 from payroll and also checks BirDraftValidator finds no
    // total that doesn't add up and no amount in the wrong format.

    public function test_a_mid_year_hire_is_taxed_on_the_pay_they_actually_got(): void
    {
        // July to December at 40,000 a month: 280,000 with the 13th month, but only
        // 228,000 taxable after it and the EE share, so at or under P250,000.
        $e = $this->employee('H', '2026-07-01');
        $this->year($e, 40000, 2000, 1000, 7);

        $built = Form2316Mapper::map($e->id, 2026);
        $fields = $built['fields'];

        $this->assertSame(['07/01', '12/31'], $this->period($fields));
        $this->assertSame('228000.00', $fields['nontax_mwe_basic']['value']);       // 29
        $this->assertSame('40000.00', $fields['nontax_thirteenth_month']['value']); // 34
        $this->assertSame('12000.00', $fields['nontax_statutory_contributions']['value']); // 36
        $this->assertSame('280000.00', $fields['gross_compensation_present']['value']); // 19
        $this->assertSame('0.00', $fields['tax_due']['value']);                     // 24
        $this->assertSame('6000.00', $fields['taxes_withheld_present']['value']);   // 25A: withheld as if all year
        $this->assertContains('Tax due 0.00 vs. withheld 6,000.00: 6,000.00 over-withheld. Needs a year-end adjustment.',
            $built['meta']['warnings']);
        $this->assertAddsUp($e, $fields);

        // Their earlier job this year takes them over P250,000: basic pay moves to 39 and is taxed.
        $fields = Form2316Mapper::totals($this->answer($fields, [
            'has_previous_employer' => true, 'taxable_income_previous_employer' => '100000.00', 'taxes_withheld_previous' => '3000.00',
        ]));

        $this->assertSame('0.00', $fields['nontax_mwe_basic']['value']);            // 29
        $this->assertSame('228000.00', $fields['tax_basic_salary']['value']);       // 39
        $this->assertSame('328000.00', $fields['gross_taxable_income']['value']);   // 23 = 228,000 + 100,000
        $this->assertSame('11700.00', $fields['tax_due']['value']);                 // 78,000 x 15%
        $this->assertSame('9000.00', $fields['total_taxes_withheld_final']['value']); // 28 = 6,000 + 3,000
        $this->assertAddsUp($e, $fields);
    }

    public function test_a_leaver_is_covered_up_to_their_last_payroll(): void
    {
        // Left after September (inactive): 9 months at 30,000, no 13th month paid.
        $e = $this->employee('L', '2025-01-01', 'inactive');
        $this->year($e, 30000, 1500, 100, 1, 9);

        $built = Form2316Mapper::map($e->id, 2026);
        $fields = $built['fields'];

        $this->assertSame(['01/01', '09/30'], $this->period($fields));
        $this->assertSame('270000.00', $fields['gross_compensation_present']['value']); // 19
        $this->assertSame('256500.00', $fields['tax_basic_salary']['value']);       // 39: over P250,000
        $this->assertSame('0.00', $fields['nontax_mwe_basic']['value']);            // 29
        $this->assertSame('975.00', $fields['tax_due']['value']);                   // 6,500 x 15%
        $this->assertSame('900.00', $fields['taxes_withheld_present']['value']);    // 25A
        $this->assertContains('Tax due 975.00 vs. withheld 900.00: 75.00 under-withheld. Needs a year-end adjustment.',
            $built['meta']['warnings']);
        $this->assertAddsUp($e, $fields);
    }

    public function test_someone_hired_and_gone_in_the_same_year_gets_both_dates(): void
    {
        $e = $this->employee('S', '2026-03-16', 'inactive');
        $this->year($e, 20000, 1000, 0, 3, 8);

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];

        $this->assertSame(['03/16', '08/31'], $this->period($fields));
        $this->assertSame('114000.00', $fields['nontax_mwe_basic']['value']);       // 29 = 120,000 - 6,000
        $this->assertSame('0.00', $fields['tax_due']['value']);
        $this->assertAddsUp($e, $fields);
    }

    public function test_exactly_p250000_taxable_is_still_exempt_and_one_peso_more_is_taxed(): void
    {
        // 10 months at 26,000 less 1,000 EE: exactly 250,000.
        $at = $this->employee('E');
        $this->year($at, 26000, 1000, 0, 1, 10);
        $fields = Form2316Mapper::map($at->id, 2026)['fields'];

        $this->assertSame('250000.00', $fields['nontax_mwe_basic']['value']);       // 29
        $this->assertSame('0.00', $fields['tax_basic_salary']['value']);            // 39
        $this->assertSame('0.00', $fields['tax_due']['value']);
        $this->assertAddsUp($at, $fields);

        // 10 centavos more a month: 250,001.00, so basic moves to 39 and the peso is taxed.
        $over = $this->employee('O');
        $this->year($over, 26000.10, 1000, 0, 1, 10);
        $fields = Form2316Mapper::map($over->id, 2026)['fields'];

        $this->assertSame('0.00', $fields['nontax_mwe_basic']['value']);            // 29
        $this->assertSame('250001.00', $fields['tax_basic_salary']['value']);       // 39
        $this->assertSame('0.15', $fields['tax_due']['value']);                     // 1.00 x 15%
        $this->assertAddsUp($over, $fields);
    }

    public function test_an_mwe_over_p250000_still_owes_no_tax(): void
    {
        $e = $this->employee('M');
        SystemSettings::updateOrCreate(['key' => 'bir_mwe_employee_ids'], ['value' => json_encode([$e->id]), 'type' => 'json', 'description' => 'test']);
        $this->year($e, 22000, 1000);

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];

        $this->assertTrue($fields['is_mwe']['value']);
        $this->assertSame('252000.00', $fields['nontax_mwe_basic']['value']);       // 29: over P250,000 but exempt
        $this->assertSame('0.00', $fields['tax_basic_salary']['value']);            // 39
        $this->assertSame('286000.00', $fields['nontax_total']['value']);           // 38
        $this->assertSame('0.00', $fields['tax_due']['value']);
        $this->assertAddsUp($e, $fields);
    }

    public function test_no_13th_month_record_leaves_item_34_at_zero_and_says_so(): void
    {
        $e = $this->employee('N');
        $this->year($e, 20000, 1700, 0, 1, 12, false);

        $built = Form2316Mapper::map($e->id, 2026);
        $fields = $built['fields'];

        $this->assertSame('0.00', $fields['nontax_thirteenth_month']['value']);     // 34
        $this->assertSame('0.00', $fields['tax_thirteenth_month_excess']['value']); // 48
        $this->assertSame('219600.00', $fields['nontax_mwe_basic']['value']);       // 29
        $this->assertSame('240000.00', $fields['gross_compensation_present']['value']); // 19
        $this->assertContains("No '13th Month Pay' in this year's payroll. If it was paid outside payroll, item 34 is short.",
            $built['meta']['warnings']);
        $this->assertAddsUp($e, $fields);
    }

    public function test_a_13th_month_over_p90000_splits_between_items_34_and_48(): void
    {
        $e = $this->employee('T');
        $this->year($e, 120000, 2000); // 13th month of 120,000 in December

        $fields = Form2316Mapper::map($e->id, 2026)['fields'];

        $this->assertSame('90000.00', $fields['nontax_thirteenth_month']['value']); // 34: the tax-free part
        $this->assertSame('30000.00', $fields['tax_thirteenth_month_excess']['value']); // 48: the rest
        $this->assertSame('1416000.00', $fields['tax_basic_salary']['value']);      // 39
        $this->assertSame('1446000.00', $fields['tax_regular_total']['value']);     // 52 = 39 + 48
        $this->assertSame('264000.00', $fields['tax_due']['value']);                // 102,500 + 646,000 x 25%
        $this->assertAddsUp($e, $fields);
    }

    private function employee(string $code, string $hired = '2025-01-01', string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => "BIR-{$code}", 'first_name' => 'Employee', 'last_name' => $code, 'email' => "bir-{$code}@example.com",
            'position' => 'Staff', 'hire_date' => $hired, 'salary' => 20000, 'status' => $status, 'rate_type' => 'monthly',
            'tin_number' => '111222333',
        ]);
    }

    /** Monthly payroll from $fromMonth to $toMonth 2026, with a one-month 13th month in December unless left out. */
    private function year(Employee $e, float $monthly, float $ee, float $tax = 0, int $fromMonth = 1, int $toMonth = 12, bool $thirteenthMonth = true): void
    {
        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $start = sprintf('2026-%02d-01', $m);
            $paid13th = $thirteenthMonth && $m === 12;
            $thirteenth = $paid13th ? [['label' => '13th Month Pay', 'amount' => $monthly]] : [];
            Payroll::create([
                'employee_id' => $e->id, 'cutoff_start' => $start, 'cutoff_end' => date('Y-m-t', strtotime($start)),
                'base_salary' => $monthly, 'gross_pay' => $monthly + ($paid13th ? $monthly : 0),
                'deductions' => ['SSS EE Contribution' => $ee] + ($tax ? ['Withholding Tax' => $tax] : []),
                'allowances' => $thirteenth, 'status' => 'finalized',
            ]);
        }
    }

    /** A draft built from these fields passes BirDraftValidator's totals and amount-format checks. */
    private function assertAddsUp(Employee $e, array $fields): void
    {
        $draft = new BirFormDraft(['form_type' => Form2316Schema::FORM_TYPE, 'period' => '2026', 'employee_id' => $e->id, 'fields' => $fields]);
        $codes = array_column(app(BirDraftValidator::class)->validate($draft), 'code');

        $this->assertNotContains('total_mismatch', $codes);
        $this->assertNotContains('invalid_amount', $codes);
    }

    private function answer(array $fields, array $answers): array
    {
        foreach ($answers as $key => $value) {
            $fields[$key] = ['value' => $value, 'origin' => 'user', 'edited' => true] + $fields[$key];
        }
        return $fields;
    }

    private function period(array $fields): array
    {
        return [$fields['period_from']['value'], $fields['period_to']['value']];
    }

    private function pick(array $entry): array
    {
        return ['value' => $entry['value'], 'origin' => $entry['origin']];
    }
}
