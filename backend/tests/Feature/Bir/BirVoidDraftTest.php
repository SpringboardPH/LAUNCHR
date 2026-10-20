<?php

namespace Tests\Feature\Bir;

use App\Models\AuditLog;
use App\Models\BirFormDraft;
use App\Models\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * POST /bir/drafts/{id}/void: a form in draft status (a returned one too) is voided with a reason
 * and kept, never deleted; any other status is 400. A voided form stays on the list and opens
 * read-only, no longer blocks creating its period again, and a voided correction still counts
 * toward the amendments. GET /bir/drafts/{id}/history reads the form's audit log back.
 */
class BirVoidDraftTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'hr' => ['hr'], 'accounting' => ['accounting']];
    }

    #[DataProvider('staffRoles')]
    public function test_admin_hr_and_accounting_can_void_a_draft(string $role): void
    {
        $id = $this->formIn('draft');
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'Made by mistake'])
            ->assertOk()
            ->assertJsonPath('message', "Form #{$id} voided")
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.void.reason', 'Made by mistake')
            ->assertJsonPath('data.void.by.id', $user->id);

        // Kept, never deleted: still on the list and still opens.
        $this->actingAs($user)->getJson('/api/bir/drafts')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'void');
        $this->actingAs($user)->getJson("/api/bir/drafts/{$id}")->assertOk()->assertJsonPath('data.status', 'void');

        $row = BirFormDraft::withVoid()->find($id);
        $this->assertNull($row->deleted_at);
        $this->assertNotNull($row->voided_at);
    }

    public function test_there_is_no_delete_any_more(): void
    {
        $id = $this->formIn('draft');

        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$id}")->assertStatus(405);
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_reason_is_required(): void
    {
        $id = $this->formIn('draft');

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    /** @return array<string, array{string}> */
    public static function lockedStatuses(): array
    {
        return ['pending' => ['pending'], 'approved' => ['approved'], 'finalized' => ['finalized']];
    }

    #[DataProvider('lockedStatuses')]
    public function test_only_a_draft_can_be_voided(string $status): void
    {
        $id = $this->formIn($status);

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => "Only a draft can be voided; form #{$id} is in {$status} status"]);

        $this->assertSame($status, BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_void_form_cannot_be_voided_again_or_changed(): void
    {
        $id = $this->formIn('draft');
        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])->assertOk();

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])
            ->assertStatus(400)->assertJsonPath('message', "Form #{$id} is already void");
        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/submit")->assertNotFound();
        $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$id}", ['fields' => ['sheets_attached' => 1]])->assertNotFound();
    }

    public function test_a_missing_form_is_404(): void
    {
        $this->actingAs($this->accounting())->postJson('/api/bir/drafts/999999/void', ['reason' => 'x'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Draft not found']);
    }

    public function test_an_employee_cannot_void_or_read_the_history(): void
    {
        $id = $this->formIn('draft');
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($employee)->getJson("/api/bir/drafts/{$id}/history")->assertForbidden();
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_nothing_is_voided_while_the_switch_is_off(): void
    {
        $id = $this->formIn('draft');
        SystemSettings::set('bir_forms_enabled', false, null, 'boolean');

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'The BIR Form Assistant is not enabled']);
    }

    public function test_a_returned_draft_can_be_voided(): void
    {
        $id = $this->formIn('pending');
        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/reject", ['reason' => 'Recheck item 25'])->assertOk();

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'Starting over'])->assertOk();
        $this->assertSame('void', BirFormDraft::withVoid()->find($id)->status);
    }

    public function test_after_voiding_a_1601c_its_period_can_be_created_again(): void
    {
        $id = $this->formIn('draft');
        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'x'])->assertOk();

        $new = $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->json('data.id');

        $this->assertNotSame($id, $new);
    }

    public function test_a_voided_correction_still_counts_and_its_version_is_not_reused(): void
    {
        $original = BirFormDraft::create([
            'form_type' => '1601-C', 'period' => '2026-07', 'status' => 'finalized', 'version' => 1,
            'prepared_by' => $this->accounting()->id, 'fields' => ['return_period' => ['value' => '07/2026']],
        ]);
        $v2 = $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$original->id}/revise")
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$v2}/void", ['reason' => 'x'])
            ->assertOk()
            ->assertJsonPath('message', "Form #{$original->id}v2 voided");

        $this->actingAs($this->accounting())->getJson("/api/bir/drafts/{$original->id}")
            ->assertJsonPath('data.amendments', ['made' => 1, 'limit' => 5]);

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$original->id}/revise")
            ->assertCreated()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.label', "#{$original->id}v3")
            ->assertJsonPath('data.amendments', ['made' => 2, 'limit' => 5]);
    }

    public function test_the_void_is_in_the_audit_log(): void
    {
        $id = $this->formIn('draft');
        $accounting = $this->accounting();

        $this->actingAs($accounting)->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'Duplicate'])->assertOk();

        $log = AuditLog::where('auditable_type', BirFormDraft::class)->where('auditable_id', $id)
            ->where('event', 'updated_birformdraft')->latest('id')->first();
        $this->assertSame($accounting->id, $log->user_id);
        $this->assertSame('void', $log->new_values['status']);
        $this->assertSame('Duplicate', $log->new_values['void_reason']);
    }

    public function test_the_history_lists_every_step_newest_first(): void
    {
        $accounting = $this->accounting();
        $id = $this->actingAs($accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()->json('data.id');
        $this->actingAs($accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['sheets_attached' => 2]])->assertOk();
        BirFormDraft::whereKey($id)->update(['status' => 'pending']); // no audit row: a query update
        $this->actingAs($accounting)->postJson("/api/bir/drafts/{$id}/reject", ['reason' => 'Recheck item 4'])->assertOk();
        $this->actingAs($accounting)->postJson("/api/bir/drafts/{$id}/void", ['reason' => 'Duplicate'])->assertOk();

        $history = $this->actingAs($accounting)->getJson("/api/bir/drafts/{$id}/history")->assertOk()->json('data');

        $this->assertSame(['voided', 'returned', 'edited', 'created'], array_column($history, 'event'));
        $this->assertSame('Duplicate', $history[0]['reason']);
        $this->assertSame($accounting->name, $history[0]['by']['name']);
        $this->assertSame('Recheck item 4', $history[1]['reason']);
        $this->assertSame('Changed 1 box', $history[2]['text']);
        $this->assertSame(['key' => 'sheets_attached', 'item' => '4', 'label' => 'Number of Sheet/s Attached', 'type' => 'integer', 'from' => null, 'to' => 2], $history[2]['changes'][0]);
        $this->assertSame('Form created from payroll data', $history[3]['text']);
    }

    /** A real 1601-C made through POST /bir/drafts, then moved straight to a status for the test. */
    private function formIn(string $status): int
    {
        $id = $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');

        BirFormDraft::whereKey($id)->update(['status' => $status]);

        return $id;
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
