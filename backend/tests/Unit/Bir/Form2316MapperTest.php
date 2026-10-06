<?php

namespace Tests\Unit\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
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

    private function employee(string $code, string $hired = '2025-01-01', string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => "BIR-{$code}", 'first_name' => 'Employee', 'last_name' => $code, 'email' => "bir-{$code}@example.com",
            'position' => 'Staff', 'hire_date' => $hired, 'salary' => 20000, 'status' => $status, 'rate_type' => 'monthly',
            'tin_number' => '111222333',
        ]);
    }

    /** Monthly payroll from $fromMonth to $toMonth 2026, with a one-month 13th month in December. */
    private function year(Employee $e, float $monthly, float $ee, float $tax = 0, int $fromMonth = 1, int $toMonth = 12): void
    {
        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $start = sprintf('2026-%02d-01', $m);
            $thirteenth = $m === 12 ? [['label' => '13th Month Pay', 'amount' => $monthly]] : [];
            Payroll::create([
                'employee_id' => $e->id, 'cutoff_start' => $start, 'cutoff_end' => date('Y-m-t', strtotime($start)),
                'base_salary' => $monthly, 'gross_pay' => $monthly + ($m === 12 ? $monthly : 0),
                'deductions' => ['SSS EE Contribution' => $ee] + ($tax ? ['Withholding Tax' => $tax] : []),
                'allowances' => $thirteenth, 'status' => 'finalized',
            ]);
        }
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
