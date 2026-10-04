<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Week 4 done-when: a real 2316 for a mid-year hire correctly lists
 * previous-employer figures as missing.
 *
 * "Real" means the draft is made by POST /bir/drafts from payroll rows, exactly
 * as the app makes it, and the answer goes in through PUT /bir/drafts/{id} —
 * nothing here hand-builds a fields object. BirMissingFieldsTest covers the
 * rules on plain arrays; this proves they hold on the draft the app produces.
 */
class BirConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_2316_for_a_mid_year_hire_lists_previous_employer_figures_once_confirmed(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $employee = $this->employeeWithPay('2026-07-01');

        $id = $this->actingAs($accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');

        $before = $this->missingKeys($id);
        $this->assertContains('has_previous_employer', $before);
        $this->assertNotContains('previous_employer_tin', $before, 'Not before we know there was one.');

        $this->actingAs($accounting)
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['has_previous_employer' => true]])
            ->assertOk();

        $after = $this->missingKeys($id);
        $this->assertNotContains('has_previous_employer', $after);
        $this->assertContains('previous_employer_tin', $after);
        $this->assertContains('previous_employer_name', $after);
        $this->assertContains('taxable_income_previous_employer', $after);
    }

    public function test_a_real_2316_for_a_full_year_employee_never_mentions_a_previous_employer(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $employee = $this->employeeWithPay('2025-03-01');

        $id = $this->actingAs($accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');

        $keys = $this->missingKeys($id);
        foreach (['has_previous_employer', 'previous_employer_tin', 'previous_employer_name',
            'taxable_income_previous_employer', 'taxes_withheld_previous', 'period_from'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public static function hireDates(): array
    {
        return [
            'hired mid-year' => ['2026-07-01', true],
            'hired 2 January' => ['2026-01-02', true],
            'hired 1 January counts as the full year' => ['2026-01-01', false],
            'hired in an earlier year' => ['2024-06-03', false],
            'no hire date on record is asked, not assumed' => [null, true],
        ];
    }

    #[DataProvider('hireDates')]
    public function test_hired_in_year_for_a_2026_certificate(?string $hireDate, bool $expected): void
    {
        $draft = new BirFormDraft(['form_type' => '2316', 'period' => '2026']);
        $draft->setRelation('employee', $hireDate === null ? null : new Employee(['hire_date' => $hireDate]));

        $this->assertSame(['hired_in_year' => $expected], (new BirConversationService())->context($draft));
    }

    public function test_a_1601c_has_no_employee_context(): void
    {
        $draft = new BirFormDraft(['form_type' => '1601-C', 'period' => '2026-08']);

        $this->assertSame([], (new BirConversationService())->context($draft));
    }

    /** @return array<int, string> */
    private function missingKeys(int $draftId): array
    {
        $draft = BirFormDraft::with('employee')->findOrFail($draftId);

        return array_column((new BirConversationService())->missingFields($draft), 'key');
    }

    private function employeeWithPay(string $hireDate): Employee
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BIR-' . str_replace('-', '', $hireDate),
            'first_name' => 'Bir',
            'last_name' => 'Tester',
            'email' => "bir-{$hireDate}@example.com",
            'position' => 'Staff',
            'hire_date' => $hireDate,
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
            'tin_number' => '123-456-789-000',
        ]);

        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id,
                'cutoff_start' => $start,
                'cutoff_end' => $end,
                'base_salary' => 30000,
                'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [],
                'status' => 'finalized',
            ]);
        }

        return $employee;
    }
}
