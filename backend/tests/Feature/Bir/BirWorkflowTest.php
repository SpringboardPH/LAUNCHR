<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeDraft(string $status, User $preparer): BirFormDraft
    {
        return BirFormDraft::create([
            'form_type'   => '1601-C',
            'period'      => '2026-07',
            'status'      => $status,
            'prepared_by' => $preparer->id,
        ]);
    }

    public function test_a_draft_cannot_be_finalized()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('draft', $accounting);

        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/finalize")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_a_draft_cannot_be_approved()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('draft', $accounting);

        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/approve")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_a_finalized_form_cannot_be_submitted()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('finalized', $accounting);

        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/submit")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertSame('finalized', $draft->fresh()->status);
    }

    public function test_a_finalized_form_cannot_be_rejected()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('finalized', $accounting);

        // A reason is sent so the 400 comes from the status check, not a 422 from validation.
        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/reject", ['reason' => 'Wrong figures'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertSame('finalized', $draft->fresh()->status);
    }

    public function test_a_draft_can_be_submitted()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('draft', $accounting);

        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/submit")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('pending', $draft->fresh()->status);
    }
}
