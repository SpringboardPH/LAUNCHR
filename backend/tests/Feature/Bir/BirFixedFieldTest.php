<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use App\Services\BIR\Schemas\Form1601CSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * A field with a schema 'fixed' value is filled in by the system and can't be answered:
 * PUT /bir/drafts/{id} refuses any value for it, null included. 1601-C item 13
 * (has_tax_relief) is fixed at No, and item 13A (tax_relief_details) is fixed blank. A
 * system_filled field is refused the same way: item 2 (is_amended) is No on the original
 * and Yes on a correction.
 */
class BirFixedFieldTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private const MESSAGE = '"Are there payees availing of tax relief under Special Law or International Tax Treaty?"'
        . " is always No and can't be changed.";

    public function test_a_new_1601c_has_item_13_filled_in_as_no_from_payroll(): void
    {
        $entry = BirFormDraft::findOrFail($this->create1601C())->fields['has_tax_relief'];

        $this->assertFalse($entry['value']);
        $this->assertSame('payroll', $entry['origin']);
        $this->assertFalse($entry['edited']);
    }

    public function test_any_answer_to_item_13_is_refused_and_the_entry_is_unchanged(): void
    {
        $id = $this->create1601C();
        $before = BirFormDraft::findOrFail($id)->fields['has_tax_relief'];

        foreach ([true, null, false] as $answer) {
            $this->answer($id, ['has_tax_relief' => $answer])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['fields.has_tax_relief' => [self::MESSAGE]]);
        }

        $after = BirFormDraft::findOrFail($id)->fields['has_tax_relief'];
        $this->assertSame($before, $after);
        $this->assertFalse($after['value']);
        $this->assertSame('payroll', $after['origin']);
        $this->assertFalse($after['edited']);
    }

    public function test_a_new_1601c_has_item_13a_filled_in_as_blank_from_payroll(): void
    {
        $entry = BirFormDraft::findOrFail($this->create1601C())->fields['tax_relief_details'];

        // Blank is "", not null: null always means pending.
        $this->assertSame('', $entry['value']);
        $this->assertSame('payroll', $entry['origin']);
        $this->assertFalse($entry['edited']);
    }

    public function test_any_answer_to_item_13a_is_refused_as_always_blank(): void
    {
        $id = $this->create1601C();
        $before = BirFormDraft::findOrFail($id)->fields['tax_relief_details'];
        $label = Form1601CSchema::byKey()['tax_relief_details']['label'];

        foreach (['TAX TREATY', null, ''] as $answer) {
            $this->answer($id, ['tax_relief_details' => $answer])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['fields.tax_relief_details' => ["\"{$label}\" is always blank and can't be changed."]]);
        }

        $this->assertSame($before, BirFormDraft::findOrFail($id)->fields['tax_relief_details']);
    }

    public function test_item_13_with_a_valid_answer_refuses_both(): void
    {
        $id = $this->create1601C();
        $before = BirFormDraft::findOrFail($id)->fields;

        $this->answer($id, ['has_tax_relief' => true, 'interest' => '500.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.has_tax_relief'])
            ->assertJsonMissingValidationErrors(['fields.interest']);

        $after = BirFormDraft::findOrFail($id)->fields;
        $this->assertSame($before['has_tax_relief'], $after['has_tax_relief']);
        $this->assertSame($before['interest'], $after['interest'], 'The valid answer was not saved either.');
    }

    public function test_any_answer_to_item_2_is_refused_on_the_original_and_on_a_correction(): void
    {
        $original = $this->create1601C();
        $label = Form1601CSchema::byKey()['is_amended']['label'];
        $message = "\"{$label}\" is filled in automatically: No on the original, Yes on an amended return.";

        $before = BirFormDraft::findOrFail($original)->fields['is_amended'];
        foreach ([true, false, null] as $answer) {
            $this->answer($original, ['is_amended' => $answer])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['fields.is_amended' => [$message]]);
        }
        $this->assertSame($before, BirFormDraft::findOrFail($original)->fields['is_amended']);

        $correction = $this->correctionOf($original);
        $before = BirFormDraft::findOrFail($correction)->fields['is_amended'];
        foreach ([false, true, null] as $answer) {
            $this->answer($correction, ['is_amended' => $answer])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['fields.is_amended' => [$message]]);
        }
        $this->assertSame($before, BirFormDraft::findOrFail($correction)->fields['is_amended']);
    }

    public function test_item_2_with_a_valid_answer_refuses_both(): void
    {
        $id = $this->create1601C();
        $before = BirFormDraft::findOrFail($id)->fields;

        $this->answer($id, ['is_amended' => true, 'interest' => '500.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.is_amended'])
            ->assertJsonMissingValidationErrors(['fields.interest']);

        $after = BirFormDraft::findOrFail($id)->fields;
        $this->assertSame($before['is_amended'], $after['is_amended']);
        $this->assertSame($before['interest'], $after['interest'], 'The valid answer was not saved either.');
    }

    public function test_item_2_is_no_on_the_original_and_yes_on_a_correction_filled_in_by_the_system(): void
    {
        $original = $this->create1601C();
        $entry = BirFormDraft::findOrFail($original)->fields['is_amended'];
        $this->assertFalse($entry['value']);
        $this->assertSame('payroll', $entry['origin']);

        $entry = BirFormDraft::findOrFail($this->correctionOf($original))->fields['is_amended'];
        $this->assertTrue($entry['value']);
        $this->assertSame('payroll', $entry['origin'], 'Filled in by the system, not a person\'s answer.');
        $this->assertFalse($entry['edited']);
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

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
