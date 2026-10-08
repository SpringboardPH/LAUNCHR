<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A submitted (pending) form can be edited, so the approver can correct it and approve it
 * in the same step (contract §5). The same answer checks apply as on a draft, and approved
 * and finalized forms stay locked.
 */
class BirPendingEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_approver_can_edit_a_pending_form_and_it_stays_pending(): void
    {
        $id = $this->createWithStatus('pending');
        $approver = User::factory()->create(['role' => 'accounting']);

        $entry = $this->actingAs($approver)
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['company_tin' => '123-456-789-000']])
            ->assertOk()
            ->json('data.fields.company_tin');

        $this->assertSame('123-456-789-000', $entry['value']);
        $this->assertSame(['id' => $approver->id, 'name' => $approver->name], $entry['edited_by']);
        $this->assertSame('pending', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_bad_answer_on_a_pending_form_is_still_refused(): void
    {
        $id = $this->createWithStatus('pending');

        $this->answer($id, ['surcharge' => '1,000.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.surcharge']);
    }

    public function test_approved_and_finalized_forms_stay_locked(): void
    {
        foreach (['approved', 'finalized'] as $status) {
            $id = $this->createWithStatus($status);
            $before = BirFormDraft::findOrFail($id)->fields['surcharge'];

            $this->answer($id, ['surcharge' => '1000.00'])
                ->assertStatus(400)
                ->assertJsonPath('message', "Only draft and pending forms can be edited; this form is in {$status} status");

            $this->assertSame($before, BirFormDraft::findOrFail($id)->fields['surcharge'], "{$status} form was changed.");
        }
    }

    /** A real 1601-C made through the API, then moved straight to a status for the test. */
    private function createWithStatus(string $status): int
    {
        $id = $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');

        BirFormDraft::whereKey($id)->update(['status' => $status]);

        return $id;
    }

    private function answer(int $draftId, array $fields): TestResponse
    {
        return $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields]);
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
