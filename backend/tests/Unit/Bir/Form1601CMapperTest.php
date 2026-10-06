<?php

namespace Tests\Unit\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Mappers\BirFormMapper;
use App\Services\BIR\Mappers\Form1601CMapper;
use App\Services\BIR\Schemas\Form1601CSchema;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form1601CMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_field_shows_where_it_came_from(): void
    {
        $this->month();

        $fields = Form1601CMapper::map(2026, 8)['fields'];

        $this->assertSame(array_column(Form1601CSchema::fields(), 'key'), array_keys($fields));
        foreach ($fields as $key => $entry) {
            $this->assertSame(['value', 'origin', 'edited', 'system_value', 'edited_by', 'edited_at'], array_keys($entry), $key);
        }
        // Payroll: the totals, plus the return period from the draft's month.
        $this->assertSame(['value' => '30000.00', 'origin' => 'payroll'], $this->pick($fields['total_compensation']));
        $this->assertSame(['value' => '08/2026', 'origin' => 'payroll'], $this->pick($fields['return_period']));
        // Company settings: the TIN is set, the RDO code is still a seed placeholder.
        $this->assertSame(['value' => '123-456-789-000', 'origin' => 'settings'], $this->pick($fields['company_tin']));
        $this->assertSame(['value' => null, 'origin' => 'pending'], $this->pick($fields['rdo_code']));
        // Awaiting user input.
        $this->assertSame(['value' => null, 'origin' => 'pending'], $this->pick($fields['is_amended']));
    }

    public function test_built_totals_equal_the_aggregation(): void
    {
        $this->month();

        $fields = Form1601CMapper::map(2026, 8)['fields'];
        $aggregation = BirAggregationService::monthlyWithholding(2026, 8);

        foreach (array_diff_key($aggregation, ['_meta' => true]) as $key => $value) {
            $this->assertSame($value, $fields[$key]['value'], $key);
        }
    }

    public function test_other_nontaxable_compensation_lowers_the_taxable_figures(): void
    {
        $this->month();
        $fields = Form1601CMapper::map(2026, 8)['fields'];

        $fields = Form1601CMapper::totals($this->answer($fields, ['other_nontaxable_compensation' => '1000.00']));

        $this->assertSame('2000.00', $fields['total_nontaxable_compensation']['value']);  // 21 = 1,000 EE + 1,000
        $this->assertSame('28000.00', $fields['total_taxable_compensation']['value']);    // 22 = 30,000 - 2,000
        $this->assertSame('28000.00', $fields['net_taxable_compensation']['value']);      // 24 = 22 - 23 (0)
    }

    public function test_adjustments_remittances_and_penalties_flow_to_the_amount_due(): void
    {
        $this->month();
        $fields = Form1601CMapper::map(2026, 8)['fields'];

        $fields = Form1601CMapper::totals($this->answer($fields, [
            'prior_month_adjustment' => '-100.00',                                          // 26
            'is_amended' => true, 'previously_remitted_tax' => '300.00', 'other_remittances' => '50.00', // 28, 29
            'surcharge' => '25.00', 'interest' => '10.00', 'compromise' => '5.00',          // 32-34
        ]));

        $this->assertSame('400.00', $fields['taxes_withheld_for_remittance']['value']);   // 27 = 500 - 100
        $this->assertSame('350.00', $fields['total_remittances_made']['value']);          // 30 = 300 + 50
        $this->assertSame('50.00', $fields['tax_still_due']['value']);                    // 31 = 400 - 350
        $this->assertSame('40.00', $fields['total_penalties']['value']);                  // 35
        $this->assertSame('90.00', $fields['total_amount_due']['value']);                 // 36 = 50 + 40
    }

    public function test_an_amount_that_is_not_plain_decimal_text_is_not_added(): void
    {
        $this->month();
        $fields = Form1601CMapper::map(2026, 8)['fields'];

        // Read the same way as BirDraftValidator, which flags "1,000.00" as invalid_amount.
        $fields = Form1601CMapper::totals($this->answer($fields, ['surcharge' => '1,000.00', 'interest' => '50.00']));

        $this->assertSame('50.00', $fields['total_penalties']['value']);
    }

    public function test_a_total_changed_by_hand_stands_and_the_next_one_follows_it(): void
    {
        $this->month();
        $fields = Form1601CMapper::map(2026, 8)['fields'];
        $fields['total_taxable_compensation'] = ['value' => '15000.00', 'origin' => 'user', 'edited' => true]
            + $fields['total_taxable_compensation'];

        $fields = Form1601CMapper::totals($this->answer($fields, ['other_nontaxable_compensation' => '1000.00']));

        $this->assertSame('15000.00', $fields['total_taxable_compensation']['value']);   // 22 kept
        $this->assertSame('user', $fields['total_taxable_compensation']['origin']);
        $this->assertSame('15000.00', $fields['net_taxable_compensation']['value']);     // 24 from the edited 22
    }

    public function test_any_taxes_withheld_follows_item_25(): void
    {
        $this->month();
        $fields = Form1601CMapper::map(2026, 8)['fields'];
        $this->assertTrue($fields['has_taxes_withheld']['value']);

        $fields['total_taxes_withheld'] = ['value' => '0.00', 'origin' => 'user', 'edited' => true] + $fields['total_taxes_withheld'];
        $fields = Form1601CMapper::totals($fields);

        $this->assertFalse($fields['has_taxes_withheld']['value']);
    }

    public function test_the_dispatcher_picks_the_mapper_by_form_type(): void
    {
        $this->month();

        $built = BirFormMapper::build('1601-C', '2026-08');

        $this->assertSame(Form1601CMapper::map(2026, 8), $built);
        $this->assertSame(Form1601CMapper::totals($built['fields']), BirFormMapper::recalculate('1601-C', $built['fields']));
        $this->expectException(\InvalidArgumentException::class);
        BirFormMapper::build('1700', '2026');
    }

    /** One taxable employee, one August cutoff: 30,000 gross, 1,000 EE, 500 tax; company TIN set, RDO still a placeholder. */
    private function month(): void
    {
        $this->seed(SystemSettingsSeeder::class);
        SystemSettings::where('key', 'company_tin')->update(['value' => '123456789']);

        $e = Employee::create([
            'employee_id' => 'BIR-A', 'first_name' => 'Employee', 'last_name' => 'A', 'email' => 'bir-a@example.com',
            'position' => 'Staff', 'hire_date' => '2026-01-01', 'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly',
        ]);
        Payroll::create([
            'employee_id' => $e->id, 'cutoff_start' => '2026-08-01', 'cutoff_end' => '2026-08-31', 'base_salary' => 30000,
            'gross_pay' => 30000, 'deductions' => ['SSS EE Contribution' => 1000, 'Withholding Tax' => 500],
            'allowances' => [], 'status' => 'finalized',
        ]);
    }

    /** User answers, as the PUT endpoint stores them. */
    private function answer(array $fields, array $answers): array
    {
        foreach ($answers as $key => $value) {
            $fields[$key] = ['value' => $value, 'origin' => 'user', 'edited' => true] + $fields[$key];
        }
        return $fields;
    }

    private function pick(array $entry): array
    {
        return ['value' => $entry['value'], 'origin' => $entry['origin']];
    }
}
