<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * A field with a schema 'fixed' value is filled in by the system and can't be answered:
 * PUT /bir/drafts/{id} refuses any value for it, null included. 1601-C item 13
 * (has_tax_relief) is fixed at No.
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
