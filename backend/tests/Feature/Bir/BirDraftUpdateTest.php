<?php

namespace Tests\Feature\Bir;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT /bir/drafts/{id} recalculates the form's totals after merging the answers
 * (BirFormMapper::recalculate()), except a total someone typed by hand.
 */
class BirDraftUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_entering_a_part_updates_the_totals_after_it(): void
    {
        $id = $this->create1601C();

        $fields = $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00'])->json('data.fields');

        $this->assertSame('1500.00', $fields['total_penalties']['value']);
        $this->assertFalse($fields['total_penalties']['edited']);
        // 36 = 31 + 35; nothing is due before penalties on a month with no payroll.
        $this->assertSame('1500.00', $fields['total_amount_due']['value']);
    }

    public function test_a_hand_typed_total_is_kept_when_its_parts_change(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'total_penalties' => '900.00']);

        $fields = $this->answer($id, ['interest' => '500.00'])->json('data.fields');

        $this->assertSame('900.00', $fields['total_penalties']['value']);
        $this->assertTrue($fields['total_penalties']['edited']);
        // Totals after it follow the typed value, not the sum of its parts.
        $this->assertSame('900.00', $fields['total_amount_due']['value']);
    }

    public function test_clearing_a_hand_typed_total_hands_it_back_to_the_calculation(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00', 'total_penalties' => '900.00']);

        $fields = $this->answer($id, ['total_penalties' => null])->json('data.fields');

        $this->assertSame('1500.00', $fields['total_penalties']['value']);
        $this->assertSame('payroll', $fields['total_penalties']['origin']);
        $this->assertFalse($fields['total_penalties']['edited']);
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    private function answer(int $draftId, array $fields): TestResponse
    {
        return $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields])
            ->assertOk();
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
