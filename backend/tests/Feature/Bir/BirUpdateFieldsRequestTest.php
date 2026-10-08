<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT /bir/drafts/{id} checks each answer as it is saved (UpdateBirDraftFieldsRequest):
 * an unknown key or a value that doesn't suit its field is a 422 naming the field, and
 * nothing in that request is saved. Clearing with null and totals recalculating are
 * covered in BirDraftUpdateTest.
 */
class BirUpdateFieldsRequestTest extends TestCase
{
    use RefreshDatabase;

    private const AMOUNT_MESSAGE = '"Surcharge" must be an amount like 1234.50, without commas or a currency sign.';

    public function test_an_unknown_key_is_refused_and_nothing_is_saved(): void
    {
        $id = $this->create1601C();

        $this->answer($id, ['not_a_field' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['fields.not_a_field' => ['"not_a_field" is not a field on the 1601-C.']]);

        $this->assertArrayNotHasKey('not_a_field', BirFormDraft::findOrFail($id)->fields);
    }

    public function test_an_amount_with_commas_a_currency_sign_or_words_is_refused(): void
    {
        $id = $this->create1601C();

        foreach (['abc', '1,000.00', '₱500'] as $bad) {
            $this->answer($id, ['surcharge' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['fields.surcharge' => [self::AMOUNT_MESSAGE]]);
        }
    }

    public function test_plain_decimal_amounts_in_any_accepted_shape_are_saved(): void
    {
        $id = $this->create1601C();

        $fields = $this->answer($id, [
            'surcharge' => '1500',
            'interest' => '20.5',
            'compromise' => 1000.25,
            'prior_month_adjustment' => '-250.00',
        ])->assertOk()->json('data.fields');

        $this->assertSame('1500', $fields['surcharge']['value']);
        $this->assertSame('-250.00', $fields['prior_month_adjustment']['value']);
        $this->assertSame('user', $fields['surcharge']['origin']);
    }

    public function test_a_yes_is_stored_as_true_and_maybe_is_refused(): void
    {
        $id = $this->create1601C();

        $fields = $this->answer($id, ['is_amended' => 'yes'])->assertOk()->json('data.fields');
        $this->assertTrue($fields['is_amended']['value']);

        $this->answer($id, ['is_amended' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['fields.is_amended' => ['"Amended Return?" must be yes or no.']]);
    }

    public function test_options_months_and_dates_are_checked(): void
    {
        $id1601c = $this->create1601C();

        $this->answer($id1601c, ['agent_category' => 'government'])->assertOk();
        $this->answer($id1601c, ['agent_category' => 'federal'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['fields.agent_category' => ['"Category of Withholding Agent" must be one of: private, government.']]);

        $this->answer($id1601c, ['return_period' => '09/2026'])->assertOk();
        $this->answer($id1601c, ['return_period' => '2026-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.return_period']);

        $id2316 = $this->create2316();

        $this->answer($id2316, ['employee_birthdate' => '2026-01-31'])->assertOk();
        // Right shape, but not a real date.
        $this->answer($id2316, ['employee_birthdate' => '2026-02-30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.employee_birthdate']);
    }

    public function test_a_signature_field_is_refused(): void
    {
        $id = $this->create2316();

        $this->answer($id, ['substituted_employer_signature' => 'Jane Dela Cruz'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['fields.substituted_employer_signature' => [
                '"Present Employer/Authorized Agent Signature over Printed Name (substituted filing)" is signed on the printed form, not entered here.',
            ]]);
    }

    public function test_one_bad_answer_refuses_the_whole_request(): void
    {
        $id = $this->create1601C();
        $before = BirFormDraft::findOrFail($id)->fields['interest'];

        $this->answer($id, ['interest' => '500.00', 'surcharge' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.surcharge'])
            ->assertJsonMissingValidationErrors(['fields.interest']);

        $this->assertSame($before, BirFormDraft::findOrFail($id)->fields['interest'], 'The good answer was not saved either.');
    }

    public function test_a_missing_draft_is_404_and_a_locked_one_is_400_even_with_bad_answers(): void
    {
        $this->answer(999999, ['not_a_field' => 'abc'])->assertNotFound();

        $id = $this->create1601C();
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);

        $this->answer($id, ['not_a_field' => 'abc'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Only draft and pending forms can be edited; this form is in approved status');
    }

    private function answer(int $draftId, array $fields): TestResponse
    {
        return $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields]);
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    private function create2316(): int
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BIR-U1',
            'first_name' => 'Bir',
            'last_name' => 'Answers',
            'email' => 'bir-answers@example.com',
            'position' => 'Staff',
            'hire_date' => '2025-01-06',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);

        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
