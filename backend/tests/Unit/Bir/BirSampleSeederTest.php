<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Services\BIR\BirAggregationService;
use Database\Seeders\BirSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BirSampleSeeder: one taxable, one not taxable (P250,000 and below) and one minimum wage employee for 2025. */
class BirSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_one_employee_of_each_kind_with_the_year_squared_and_runs_again(): void
    {
        $this->seed(BirSampleSeeder::class);
        $this->seed(BirSampleSeeder::class);

        $ids = Employee::where('employee_id', 'like', 'TST-%')->pluck('id', 'employee_id');
        $this->assertCount(3, $ids);

        $year = fn (string $code) => BirAggregationService::annualCompensation($ids[$code], 2025);
        $this->assertSame(['taxable', 'exempt_250k', 'mwe'], array_map(fn ($code) => $year($code)['_meta']['category'], ['TST-001', 'TST-002', 'TST-003']));

        // Year-end adjustment: tax withheld equals tax due, nothing refunded.
        $taxable = $year('TST-001');
        $this->assertGreaterThan(0, (float) $taxable['tax_due']);
        $this->assertSame($taxable['tax_due'], $taxable['taxes_withheld_present']);
        $this->assertSame('0.00', $taxable['year_end_refund']);

        $june = BirAggregationService::monthlyWithholding(2025, 6)['_meta']['employees'];
        $this->assertSame(['taxable', 'exempt_250k', 'mwe'], array_column($june, 'category'));

        // A full profile with a login, and a 2025 2316 draft that passes validation with nothing to fix.
        foreach (Employee::whereIn('id', $ids)->get() as $employee) {
            $this->assertNotNull($employee->user_id);
            foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin_number', 'bank_account_number'] as $field) {
                $this->assertNotEmpty($employee->{$field}, "{$employee->employee_id} {$field}");
            }
            $draft = BirFormDraft::where('employee_id', $employee->id)->sole();
            $this->assertSame(['2316', '2025', 'draft'], [$draft->form_type, $draft->period, $draft->status]);
            $this->assertSame([], $draft->validation_errors, $employee->employee_id);
        }
    }
}
