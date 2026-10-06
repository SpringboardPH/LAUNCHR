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
        // Payroll for the month, or empty_period stays however much is answered.
        $this->payrollFor('2026-09-01', '2026-09-15', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['SSS EE Contribution' => 700, 'Withholding Tax' => 300]);
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

    public function test_an_amount_that_is_not_plain_decimal_text_is_flagged_and_its_total_is_not_checked(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => 'abc', 'interest' => '1,000.00']);

        $errors = $this->errorsByField($id);
        $label = Form1601CSchema::byKey()['surcharge']['label'];

        $this->assertSame('invalid_amount', $errors['surcharge']['code']);
        $this->assertSame("\"{$label}\" must be an amount like 1234.50, without commas or a currency sign.", $errors['surcharge']['message']);
        $this->assertSame('invalid_amount', $errors['interest']['code']);
        $this->assertArrayNotHasKey('total_penalties', $errors, 'A total with an invalid part is not checked.');
    }

    public function test_plain_decimal_amounts_in_any_accepted_shape_are_not_flagged(): void
    {
        $id = $this->create1601C();
        $this->answer($id, [
            'surcharge' => '1500',
            'interest' => '20.5',
            'compromise' => 1000.25,
            'prior_month_adjustment' => '-250.00',
        ]);

        $this->assertNotContains('invalid_amount', array_column($this->errorsByField($id), 'code'));
    }

    public function test_a_draft_built_from_payroll_has_no_payroll_mismatch(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300.25]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['Withholding Tax' => 300.25]);

        $this->assertNotContains('payroll_mismatch', array_column($this->errorsByField($this->create1601C()), 'code'));
        $this->assertNotContains('payroll_mismatch', array_column($this->errorsByField($this->create2316()), 'code'));
    }

    public function test_overwriting_1601c_tax_withheld_is_flagged_against_the_payroll(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300.25]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['Withholding Tax' => 300.25]);
        $id = $this->create1601C();
        $this->answer($id, ['total_taxes_withheld' => '1234.56']);

        $error = $this->errorsByField($id)['total_taxes_withheld'];
        $label = Form1601CSchema::byKey()['total_taxes_withheld']['label'];

        $this->assertSame('payroll_mismatch', $error['code']);
        $this->assertSame("\"{$label}\" is 1,234.56, but the payroll this draft was built from withheld 600.50.", $error['message']);
    }

    public function test_overwriting_2316_tax_withheld_is_flagged_against_the_payroll(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300.25]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['Withholding Tax' => 300.25]);
        $id = $this->create2316();
        $this->answer($id, ['taxes_withheld_present' => '1234.56']);

        $error = $this->errorsByField($id)['taxes_withheld_present'];
        $label = Form2316Schema::byKey()['taxes_withheld_present']['label'];

        $this->assertSame('payroll_mismatch', $error['code']);
        $this->assertSame("\"{$label}\" is 1,234.56, but the payroll this draft was built from withheld 600.50.", $error['message']);
    }

    public function test_a_draft_without_a_payroll_snapshot_is_never_flagged(): void
    {
        // Like a revision or a seeded fixture: fields but no source_snapshot.
        $draft = BirFormDraft::create([
            'form_type' => '1601-C',
            'period' => '2026-09',
            'status' => 'draft',
            'version' => 1,
            'prepared_by' => $this->accounting()->id,
            'fields' => ['total_taxes_withheld' => [
                'value' => '999.00', 'origin' => 'user', 'edited' => true,
                'system_value' => null, 'edited_by' => null, 'edited_at' => null,
            ]],
            'validation_errors' => [],
        ]);

        $this->assertNotContains('payroll_mismatch', array_column($this->errorsByField($draft->id), 'code'));
        $this->assertSame([], $this->errorsWithCode($draft->id, 'empty_period'));
    }

    public function test_a_1601c_for_a_month_with_no_payroll_is_an_empty_period(): void
    {
        $errors = $this->errorsWithCode($this->create1601C(), 'empty_period');

        $this->assertCount(1, $errors);
        $this->assertSame('return_period', $errors[0]['field']);
        $this->assertSame(
            'There is no finalized or paid payroll for 2026-09, so every payroll figure on this form is zero. '
                . 'Finalize or pay that payroll, then create a new draft.',
            $errors[0]['message'],
        );
    }

    public function test_a_2316_for_a_year_with_no_payroll_is_an_empty_period(): void
    {
        // Creates the employee; this payroll belongs to 2025, so the 2026 certificate has none.
        $this->payrollFor('2025-12-01', '2025-12-15', ['Withholding Tax' => 300]);

        $errors = $this->errorsWithCode($this->create2316(), 'empty_period');

        $this->assertCount(1, $errors);
        $this->assertSame('tax_year', $errors[0]['field']);
        $this->assertSame(
            'There is no finalized or paid payroll for 2026, so every payroll figure on this form is zero. '
                . 'Finalize or pay that payroll, then create a new draft.',
            $errors[0]['message'],
        );
    }

    public function test_a_draft_built_from_payroll_is_not_an_empty_period(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300]);
        $this->payrollFor('2026-09-16', '2026-09-30', ['Withholding Tax' => 300]);

        $this->assertSame([], $this->errorsWithCode($this->create1601C(), 'empty_period'));
        $this->assertSame([], $this->errorsWithCode($this->create2316(), 'empty_period'));
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

    /**
     * Every error with one code. Unlike errorsByField(), keeps an error that shares its
     * field with another one (empty_period and required are both on item 1).
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function errorsWithCode(int $draftId, string $code): array
    {
        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::with('employee')->findOrFail($draftId));

        return array_values(array_filter($errors, fn (array $error) => $error['code'] === $code));
    }
}
