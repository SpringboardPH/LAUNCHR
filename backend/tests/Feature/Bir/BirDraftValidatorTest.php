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
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The required-fields rule, on drafts made the way the app makes them:
 * POST /bir/drafts to create, PUT /bir/drafts/{id} to answer.
 */
class BirDraftValidatorTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    public function test_a_fresh_1601c_reports_unanswered_questions_and_missing_company_settings(): void
    {
        $errors = $this->errorsByField($this->create1601C());

        // Item 2 "Amended Return?" is filled in by the system, so it isn't one of them.
        $this->assertArrayNotHasKey('is_amended', $errors);
        foreach (['sheets_attached'] as $key) {
            $this->assertSame('required', $errors[$key]['code'], $key);
            $this->assertSame('error', $errors[$key]['severity'], $key);
            $this->assertSame('"' . Form1601CSchema::byKey()[$key]['label'] . '" is required.', $errors[$key]['message']);
        }

        // The test database has no company settings, so every settings field is a record gap.
        foreach (['company_tin', 'company_name', 'rdo_code'] as $key) {
            $this->assertSame('record_gap', $errors[$key]['code'], $key);
            $this->assertSame('error', $errors[$key]['severity'], $key);
            $this->assertStringContainsString('should come from the company settings', $errors[$key]['message']);
        }
    }

    public function test_yes_to_tax_relief_on_an_old_draft_does_not_ask_for_its_details(): void
    {
        $id = $this->create1601C();

        // Written straight to the draft: item 13 is fixed at No and PUT refuses it, but a
        // draft made before that could still hold a yes. Item 13A is fixed blank, so it
        // isn't asked for: the item 13 error already says to create a new draft.
        $this->storeTaxRelief($id, ['value' => true, 'origin' => 'user']);

        $errors = $this->errorsByField($id);
        $this->assertArrayNotHasKey('tax_relief_details', $errors);
        $this->assertSame('fixed_value', $errors['has_tax_relief']['code']);
    }

    public function test_a_new_1601c_has_no_fixed_value_error(): void
    {
        $this->assertSame([], $this->errorsWithCode($this->create1601C(), 'fixed_value'));
    }

    public function test_an_old_draft_with_yes_to_tax_relief_is_a_fixed_value_error_and_cannot_be_submitted(): void
    {
        $id = $this->create1601C();
        $this->storeTaxRelief($id, ['value' => true, 'origin' => 'user']);

        $errors = $this->errorsWithCode($id, 'fixed_value');
        $label = Form1601CSchema::byKey()['has_tax_relief']['label'];
        $this->assertCount(1, $errors);
        $this->assertSame('has_tax_relief', $errors[0]['field']);
        $this->assertSame('error', $errors[0]['severity']);
        $this->assertSame("\"{$label}\" must be No. Create a new draft for this period to fix it.", $errors[0]['message']);

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/submit")->assertStatus(422);
        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('draft', $draft->status);
        $this->assertContains('fixed_value', array_column($draft->validation_errors, 'code'));
    }

    public function test_an_old_draft_with_tax_relief_pending_is_a_fixed_value_error_only(): void
    {
        $id = $this->create1601C();
        $this->storeTaxRelief($id, ['value' => null, 'origin' => 'pending']);

        $errors = array_values(array_filter(
            app(BirDraftValidator::class)->validate(BirFormDraft::findOrFail($id)),
            fn (array $error) => $error['field'] === 'has_tax_relief',
        ));
        $this->assertSame(['fixed_value'], array_column($errors, 'code'), 'Not also a missing-field error saying to enter it.');
    }

    public function test_an_old_draft_with_text_in_item_13a_is_a_fixed_value_error_saying_blank(): void
    {
        $id = $this->create1601C();
        $this->storeEntry($id, 'tax_relief_details', ['value' => 'TAX TREATY', 'origin' => 'user']);

        $errors = array_values(array_filter(
            $this->errorsWithCode($id, 'fixed_value'),
            fn (array $error) => $error['field'] === 'tax_relief_details',
        ));
        $label = Form1601CSchema::byKey()['tax_relief_details']['label'];
        $this->assertCount(1, $errors);
        $this->assertSame("\"{$label}\" must be blank. Create a new draft for this period to fix it.", $errors[0]['message']);
    }

    public function test_an_old_draft_with_item_13a_left_empty_is_not_flagged(): void
    {
        // Blank is the right answer for 13A, so an old draft where it was never answered is fine.
        $id = $this->create1601C();
        $this->storeEntry($id, 'tax_relief_details', ['value' => null, 'origin' => 'pending']);

        $fields = array_column(app(BirDraftValidator::class)->validate(BirFormDraft::findOrFail($id)), 'field');
        $this->assertNotContains('tax_relief_details', $fields);
    }

    public function test_a_new_original_and_its_correction_have_no_system_value_error(): void
    {
        $original = $this->create1601C();
        $this->assertSame([], $this->errorsWithCode($original, 'system_value'));

        $this->assertSame([], $this->errorsWithCode($this->correctionOf($original), 'system_value'));
    }

    public function test_an_original_with_yes_in_item_2_is_a_system_value_error_and_cannot_be_submitted(): void
    {
        $id = $this->create1601C();
        $this->storeEntry($id, 'is_amended', ['value' => true, 'origin' => 'user']);

        $errors = $this->errorsWithCode($id, 'system_value');
        $label = Form1601CSchema::byKey()['is_amended']['label'];
        $this->assertCount(1, $errors);
        $this->assertSame('is_amended', $errors[0]['field']);
        $this->assertSame('error', $errors[0]['severity']);
        $this->assertSame("\"{$label}\" must be No on the original return.", $errors[0]['message']);

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/submit")->assertStatus(422);
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_correction_with_no_in_item_2_is_a_system_value_error(): void
    {
        $correction = $this->correctionOf($this->create1601C());
        $this->storeEntry($correction, 'is_amended', ['value' => false, 'origin' => 'user']);

        $errors = $this->errorsWithCode($correction, 'system_value');
        $label = Form1601CSchema::byKey()['is_amended']['label'];
        $this->assertCount(1, $errors);
        $this->assertSame("\"{$label}\" must be Yes on an amended return (version 2).", $errors[0]['message']);
    }

    public function test_item_2_pending_is_a_system_value_error_only(): void
    {
        $id = $this->create1601C();
        $this->storeEntry($id, 'is_amended', ['value' => null, 'origin' => 'pending']);

        $errors = array_values(array_filter(
            app(BirDraftValidator::class)->validate(BirFormDraft::findOrFail($id)),
            fn (array $error) => $error['field'] === 'is_amended',
        ));
        $this->assertSame(['system_value'], array_column($errors, 'code'), 'Not also a missing-field error saying to enter it.');
    }

    public function test_a_stored_value_in_the_wrong_format_is_an_invalid_format_error(): void
    {
        // Written straight to the draft: PUT now refuses this, but older drafts and figures
        // filled from payroll or settings never went through PUT.
        $id = $this->create1601C();
        $draft = BirFormDraft::findOrFail($id);
        $fields = $draft->fields;
        $fields['company_zip'] = array_merge($fields['company_zip'], ['value' => '12AB', 'origin' => 'settings']);
        $draft->fields = $fields;
        $draft->save();

        $error = $this->errorsByField($id)['company_zip'];
        $label = Form1601CSchema::byKey()['company_zip']['label'];

        $this->assertSame('invalid_format', $error['code']);
        $this->assertSame('error', $error['severity']);
        $this->assertSame("\"{$label}\" must be a 4-digit ZIP code like 1004.", $error['message']);
    }

    public function test_a_value_in_the_right_format_is_not_flagged(): void
    {
        $id = $this->create1601C();
        $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['company_zip' => '1004', 'company_tin' => '123-456-789-000']])
            ->assertOk();

        $errors = $this->errorsByField($id);
        $this->assertArrayNotHasKey('company_zip', $errors);
        $this->assertArrayNotHasKey('company_tin', $errors);
    }

    public function test_a_blank_formatted_field_is_reported_as_missing_not_as_a_bad_format(): void
    {
        // The test database has no company settings, so company_zip starts blank.
        $error = $this->errorsByField($this->create1601C())['company_zip'];

        $this->assertSame('record_gap', $error['code']);
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
                    // A formatted field (TIN, RDO, ZIP, ...) needs a value in its format, or PUT refuses it.
                    default => match ($schema[$key]['format'] ?? null) {
                        'tin' => '123-456-789-000', 'rdo' => '043', 'zip' => '1004',
                        'phone' => '09171234567', 'email' => 'name@company.com', 'mmdd' => '01/01',
                        'address' => '12 Mabini St., Brgy. San Roque, Quezon City',
                        default => 'X',
                    },
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

    public function test_a_hand_typed_total_that_does_not_add_up_is_flagged_on_that_total(): void
    {
        $id = $this->create1601C();
        // PUT recalculates totals, so only a total typed by hand can go stale.
        $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00', 'total_penalties' => '0.00']);

        $errors = $this->errorsByField($id);
        $label = Form1601CSchema::byKey()['total_penalties']['label'];

        $this->assertSame('total_mismatch', $errors['total_penalties']['code']);
        $this->assertSame('error', $errors['total_penalties']['severity']);
        $this->assertSame("\"{$label}\" should be 1,500.00 (items 32 to 34) but is 0.00.", $errors['total_penalties']['message']);
        // Item 36 is recalculated from what item 35 holds (the typed 0), so it adds up.
        $this->assertArrayNotHasKey('total_amount_due', $errors);
    }

    public function test_correcting_one_hand_typed_total_brings_up_the_next_until_all_are_correct(): void
    {
        $id = $this->create1601C();
        $this->answer($id, [
            'surcharge' => '1000.00', 'interest' => '500.00', 'total_penalties' => '0.00', 'total_amount_due' => '0.00',
        ]);

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

    public function test_a_2316_hand_typed_total_that_leaves_out_previous_employer_tax_is_flagged(): void
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300]);
        $id = $this->create2316();
        $this->answer($id, ['taxes_withheld_previous' => '5000.00', 'total_taxes_withheld_adjusted' => '300.00']);

        $error = $this->errorsByField($id)['total_taxes_withheld_adjusted'];
        $label = Form2316Schema::byKey()['total_taxes_withheld_adjusted']['label'];

        // Tax due is 0, so the 300 withheld is refunded at year-end and 25A is 0: 26 should be 0 + 5,000.
        $this->assertSame('total_mismatch', $error['code']);
        $this->assertSame("\"{$label}\" should be 5,000.00 (items 25A and 25B) but is 300.00.", $error['message']);
    }

    public function test_an_amount_that_is_not_plain_decimal_text_is_flagged_and_its_total_is_not_checked(): void
    {
        $id = $this->create1601C();
        // PUT now refuses these (UpdateBirDraftFieldsRequest); write them straight onto the draft,
        // as older data could hold them, to check the validator still catches them.
        $draft = BirFormDraft::findOrFail($id);
        $fields = $draft->fields;
        $fields['surcharge'] = array_merge($fields['surcharge'], ['value' => 'abc', 'origin' => 'user', 'edited' => true]);
        $fields['interest'] = array_merge($fields['interest'], ['value' => '1,000.00', 'origin' => 'user', 'edited' => true]);
        $draft->update(['fields' => $fields]);

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
        $this->assertSame('warning', $error['severity']);
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

        // Tax due is 0, so 25A should be the 600.50 withheld less its year-end refund: 0.
        $this->assertSame('payroll_mismatch', $error['code']);
        $this->assertSame('warning', $error['severity']);
        $this->assertSame("\"{$label}\" is 1,234.56, but the payroll this draft was built from withheld 600.50, less the year-end refund of 600.50.", $error['message']);
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
        $this->assertSame('warning', $errors[0]['severity']);
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
        $this->assertSame('warning', $errors[0]['severity']);
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

    public function test_a_2316_period_from_after_its_to_is_a_period_order_error_and_cannot_be_submitted(): void
    {
        $id = $this->create2316WithPeriod('10/15', '03/31');

        $errors = $this->errorsWithCode($id, 'period_order');
        $schema = Form2316Schema::byKey();
        $this->assertCount(1, $errors);
        $this->assertSame('period_from', $errors[0]['field']);
        $this->assertSame('error', $errors[0]['severity']);
        $this->assertSame(
            "\"{$schema['period_from']['label']}\" (10/15) is after \"{$schema['period_to']['label']}\" (03/31).",
            $errors[0]['message'],
        );

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/submit")->assertStatus(422);
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_2316_full_year_period_is_not_a_period_order_error(): void
    {
        $this->assertSame([], $this->errorsWithCode($this->create2316WithPeriod('01/01', '12/31'), 'period_order'));
    }

    public function test_a_2316_one_day_period_is_not_a_period_order_error(): void
    {
        $this->assertSame([], $this->errorsWithCode($this->create2316WithPeriod('06/30', '06/30'), 'period_order'));
    }

    public function test_a_blank_or_malformed_period_date_is_not_a_period_order_error(): void
    {
        $malformed = $this->create2316WithPeriod('13/45', '03/31');
        $this->assertSame([], $this->errorsWithCode($malformed, 'period_order'));
        $this->assertSame('invalid_format', $this->errorsByField($malformed)['period_from']['code'], 'Only the format error.');

        // The same draft: a second 2316 for the same employee and year would be a 409.
        $this->storeEntry($malformed, 'period_from', ['value' => null, 'origin' => 'pending']);
        $this->assertSame([], $this->errorsWithCode($malformed, 'period_order'));
    }

    public function test_a_1601c_never_gets_a_period_order_error(): void
    {
        $this->assertSame([], $this->errorsWithCode($this->create1601C(), 'period_order'));
    }

    /** A 2316 with item 2 written straight into the draft, as the other tests write fields. */
    private function create2316WithPeriod(?string $from, ?string $to): int
    {
        $this->payrollFor('2026-09-01', '2026-09-15', ['Withholding Tax' => 300]);
        $id = $this->create2316();
        $this->storeEntry($id, 'period_from', ['value' => $from, 'origin' => $from === null ? 'pending' : 'user']);
        $this->storeEntry($id, 'period_to', ['value' => $to, 'origin' => $to === null ? 'pending' : 'user']);

        return $id;
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

    /** Writes item 13 straight into the draft, as a draft made before it was fixed could hold it. */
    private function storeTaxRelief(int $draftId, array $entry): void
    {
        $this->storeEntry($draftId, 'has_tax_relief', $entry);
    }

    /** Files the form, then starts a correction of it: version 2. */
    private function correctionOf(int $draftId): int
    {
        BirFormDraft::whereKey($draftId)->update(['status' => 'finalized']);

        return $this->actingAs($this->accounting())
            ->postJson("/api/bir/drafts/{$draftId}/revise")
            ->assertCreated()
            ->json('data.id');
    }

    /** Writes a field straight into the draft, skipping PUT, as an old draft could hold it. */
    private function storeEntry(int $draftId, string $key, array $entry): void
    {
        $draft = BirFormDraft::findOrFail($draftId);
        $fields = $draft->fields;
        $fields[$key] = array_merge($fields[$key], $entry);
        $draft->fields = $fields;
        $draft->save();
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

    /** @return array<string, array{field: string, code: string, severity: string, message: string}> */
    private function errorsByField(int $draftId): array
    {
        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::with('employee')->findOrFail($draftId));

        return array_column($errors, null, 'field');
    }

    /**
     * Every error with one code. Unlike errorsByField(), keeps an error that shares its
     * field with another one (empty_period and required are both on item 1).
     *
     * @return array<int, array{field: string, code: string, severity: string, message: string}>
     */
    private function errorsWithCode(int $draftId, string $code): array
    {
        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::with('employee')->findOrFail($draftId));

        return array_values(array_filter($errors, fn (array $error) => $error['code'] === $code));
    }
}
