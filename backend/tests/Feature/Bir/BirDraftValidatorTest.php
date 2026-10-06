<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The required-fields rule, on drafts made the way the app makes them:
 * POST /bir/drafts to create, PUT /bir/drafts/{id} to answer.
 */
class BirDraftValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_1601c_reports_unanswered_questions_and_missing_company_settings(): void
    {
        $errors = $this->errorsByField($this->create1601C());

        foreach (['is_amended', 'sheets_attached'] as $key) {
            $this->assertSame('required', $errors[$key]['code'], $key);
            $this->assertSame('"' . Form1601CSchema::byKey()[$key]['label'] . '" is required.', $errors[$key]['message']);
        }

        // The test database has no company settings, so every settings field is a record gap.
        foreach (['company_tin', 'company_name', 'rdo_code'] as $key) {
            $this->assertSame('record_gap', $errors[$key]['code'], $key);
            $this->assertStringContainsString('should come from the company settings', $errors[$key]['message']);
        }
    }

    public function test_answering_yes_to_tax_relief_makes_its_details_required_and_says_why(): void
    {
        $id = $this->create1601C();
        $this->assertArrayNotHasKey('tax_relief_details', $this->errorsByField($id), 'Not before the question is answered.');

        $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['has_tax_relief' => true]])
            ->assertOk();

        $error = $this->errorsByField($id)['tax_relief_details'];
        $schema = Form1601CSchema::byKey();
        $this->assertSame('condition', $error['code']);
        $this->assertSame(
            "\"{$schema['tax_relief_details']['label']}\" is required because \"{$schema['has_tax_relief']['label']}\" is yes.",
            $error['message'],
        );
    }

    public function test_errors_point_at_draft_fields_in_form_order_and_clear_once_everything_is_answered(): void
    {
        $id = $this->create1601C();
        $schema = Form1601CSchema::byKey();

        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::findOrFail($id));
        $fieldKeys = array_keys(BirFormDraft::findOrFail($id)->fields);
        $reported = array_column($errors, 'field');
        $this->assertNotEmpty($reported);
        $this->assertSame([], array_diff($reported, $fieldKeys), 'Every error names a key in the draft.');
        $this->assertSame(array_values(array_intersect(array_keys($schema), $reported)), $reported, 'Errors come out in form order.');

        // Answer whatever is reported until nothing is. An answer can make another field
        // required, so this loops; ten rounds is far more than any form needs.
        for ($round = 0; $round < 10 && $reported !== []; $round++) {
            $answers = [];
            foreach ($reported as $key) {
                $answers[$key] = match ($schema[$key]['type']) {
                    'boolean' => false,
                    'enum' => $schema[$key]['options'][0],
                    'decimal' => '0.00',
                    'integer' => 0,
                    'date' => '2026-01-01',
                    'month' => '09/2026',
                    default => 'X',
                };
            }
            $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$id}", ['fields' => $answers])->assertOk();
            $reported = array_column($this->errorsByField($id), 'field');
        }

        $this->assertSame([], $reported);
    }

    public function test_a_1601c_built_from_payroll_has_totals_that_add_up(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);

        $codes = array_column($this->errorsByField($this->create1601C()), 'code');

        $this->assertNotContains('total_mismatch', $codes);
    }

    public function test_a_penalty_entered_without_updating_its_total_is_flagged_on_that_total(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00']);

        $errors = $this->errorsByField($id);
        $label = Form1601CSchema::byKey()['total_penalties']['label'];

        $this->assertSame('total_mismatch', $errors['total_penalties']['code']);
        $this->assertSame("\"{$label}\" should be 1,500.00 (items 32 to 34) but is 0.00.", $errors['total_penalties']['message']);
        // Item 36 is checked against what item 35 holds now (still 0), so it isn't flagged yet.
        $this->assertArrayNotHasKey('total_amount_due', $errors);
    }

    public function test_correcting_one_total_brings_up_the_next_until_all_are_correct(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00']);

        $this->answer($id, ['total_penalties' => '1500.00']);
        $errors = $this->errorsByField($id);
        $this->assertArrayNotHasKey('total_penalties', $errors);
        $this->assertSame('total_mismatch', $errors['total_amount_due']['code']);

        $this->answer($id, ['total_amount_due' => '1500.00']);
        $this->assertNotContains('total_mismatch', array_column($this->errorsByField($id), 'code'));
    }

    public function test_a_2316_built_from_payroll_has_totals_that_add_up(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);

        $codes = array_column($this->errorsByField($this->create2316()), 'code');

        $this->assertNotContains('total_mismatch', $codes);
    }

    public function test_a_2316_previous_employer_tax_entered_without_updating_the_total_is_flagged(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300]);
        $id = $this->create2316();
        $this->answer($id, ['taxes_withheld_previous' => '5000.00']);

        $error = $this->errorsByField($id)['total_taxes_withheld_adjusted'];
        $label = Form2316Schema::byKey()['total_taxes_withheld_adjusted']['label'];

        $this->assertSame('total_mismatch', $error['code']);
        $this->assertSame("\"{$label}\" should be 5,300.00 (items 25A and 25B) but is 300.00.", $error['message']);
    }

    private function create2316(): int
    {
        $employeeId = Employee::where('employee_id', 'EMP-BIR-V1')->value('id');

        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employeeId])
            ->assertCreated()
            ->json('data.id');
    }

    private function answer(int $draftId, array $fields): void
    {
        $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields])->assertOk();
    }

    private function payrollFor(string $start, string $end, array $deductions): void
    {
        $employee = Employee::firstOrCreate(['employee_id' => 'EMP-BIR-V1'], [
            'first_name' => 'Bir',
            'last_name' => 'Validator',
            'email' => 'bir-validator@example.com',
            'position' => 'Staff',
            'hire_date' => '2025-01-06',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);

        Payroll::create([
            'employee_id' => $employee->id,
            'cutoff_start' => $start,
            'cutoff_end' => $end,
            'base_salary' => 30000,
            'gross_pay' => 15000,
            'deductions' => $deductions,
            'allowances' => [],
            'status' => 'finalized',
        ]);
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }

    /** @return array<string, array{field: string, code: string, message: string}> */
    private function errorsByField(int $draftId): array
    {
        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::with('employee')->findOrFail($draftId));

        return array_column($errors, null, 'field');
    }
}
