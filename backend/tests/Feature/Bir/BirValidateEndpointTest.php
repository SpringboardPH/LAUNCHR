<?php

namespace Tests\Feature\Bir;

use App\Models\AuditLog;
use App\Models\BirFormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /bir/drafts/{id}/validate: runs BirDraftValidator and stores the result on
 * validation_errors. Drafts are made the way the app makes them: POST to create, PUT to answer.
 */
class BirValidateEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_returns_the_draft_with_its_validation_errors_filled_in(): void
    {
        // No payroll for the month, so there is one empty_period warning among the errors.
        $id = $this->create1601C();

        $response = $this->validateDraft($id)->assertOk()->assertJson(['success' => true]);

        $errors = $response->json('data.validation_errors');
        $this->assertNotEmpty($errors);
        foreach ($errors as $error) {
            $this->assertSame(['field', 'code', 'severity', 'message'], array_keys($error));
            $this->assertContains($error['severity'], ['error', 'warning']);
        }

        $errorCount = count(array_filter($errors, fn (array $error) => $error['severity'] === 'error'));
        $this->assertSame("Draft validated: {$errorCount} errors, 1 warning", $response->json('message'));
        // assertEquals, not assertSame: MySQL's JSON column hands the keys back in its own order.
        $this->assertEquals($errors, BirFormDraft::findOrFail($id)->validation_errors, 'The result is stored on the draft.');
    }

    public function test_fixing_a_field_and_validating_again_clears_its_entry(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'interest' => '500.00']);

        $entry = $this->entriesFor($this->validateDraft($id)->assertOk(), 'total_penalties');
        $this->assertCount(1, $entry);
        $this->assertSame('total_mismatch', $entry[0]['code']);
        $this->assertSame('error', $entry[0]['severity']);

        $this->answer($id, ['total_penalties' => '1500.00']);

        $this->assertSame([], $this->entriesFor($this->validateDraft($id)->assertOk(), 'total_penalties'));
    }

    public function test_an_unknown_draft_is_not_found(): void
    {
        $this->validateDraft(999999)
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Draft not found']);
    }

    public function test_an_employee_cannot_validate_a_draft(): void
    {
        $id = $this->create1601C();
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)->postJson("/api/bir/drafts/{$id}/validate")->assertForbidden();

        $this->assertSame([], BirFormDraft::findOrFail($id)->validation_errors);
    }

    public function test_a_finalized_draft_is_refused_but_pending_and_approved_drafts_are_validated(): void
    {
        $id = $this->create1601C();

        // Query-builder updates: only the status changes, without going through the workflow.
        foreach (['pending', 'approved'] as $status) {
            BirFormDraft::whereKey($id)->update(['status' => $status, 'validation_errors' => '[]']);
            $this->validateDraft($id)->assertOk();
            $this->assertNotEmpty(BirFormDraft::findOrFail($id)->validation_errors, $status);
        }

        BirFormDraft::whereKey($id)->update(['status' => 'finalized', 'validation_errors' => '[]']);
        $this->validateDraft($id)
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => "Finalized forms are locked and can't be revalidated"]);

        $this->assertSame([], BirFormDraft::findOrFail($id)->validation_errors, 'A finalized form is left as filed.');
    }

    public function test_source_snapshot_never_appears_in_the_response(): void
    {
        $id = $this->create1601C();
        $this->assertNotNull(BirFormDraft::findOrFail($id)->source_snapshot, 'The draft has a snapshot to leak.');

        $response = $this->validateDraft($id)->assertOk();

        $this->assertStringNotContainsString('source_snapshot', $response->getContent());
    }

    public function test_validating_again_with_nothing_changed_writes_no_audit_log(): void
    {
        $id = $this->create1601C();
        $audits = fn () => AuditLog::where('auditable_type', BirFormDraft::class)
            ->where('auditable_id', $id)
            ->where('event', 'updated_birformdraft')
            ->count();

        $this->validateDraft($id)->assertOk();
        $this->assertSame(1, $audits(), 'The first result changes validation_errors, so it is logged.');

        $this->validateDraft($id)->assertOk();
        $this->assertSame(1, $audits(), 'The same result again is not a change, so nothing is logged.');
    }

    private function validateDraft(int $draftId): TestResponse
    {
        return $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$draftId}/validate");
    }

    /** @return array<int, array{field: string, code: string, severity: string, message: string}> */
    private function entriesFor(TestResponse $response, string $field): array
    {
        return array_values(array_filter(
            $response->json('data.validation_errors'),
            fn (array $error) => $error['field'] === $field,
        ));
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    private function answer(int $draftId, array $fields): void
    {
        $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields])->assertOk();
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
