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
 * DELETE /bir/drafts/{id}: a form in draft status (a returned one too) is soft-deleted; any
 * other status is 400. A deleted form no longer blocks creating its period again, but a deleted
 * correction still counts toward the amendments and its version number is never reused.
 */
class BirDeleteDraftTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'hr' => ['hr'], 'accounting' => ['accounting']];
    }

    #[DataProvider('staffRoles')]
    public function test_admin_hr_and_accounting_can_delete_a_draft(string $role): void
    {
        $id = $this->formIn('draft');
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->deleteJson("/api/bir/drafts/{$id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['id' => $id, 'label' => "#{$id}"], 'message' => "Draft #{$id} deleted"]);

        $this->actingAs($user)->getJson('/api/bir/drafts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson("/api/bir/drafts/{$id}")->assertNotFound();

        $row = BirFormDraft::withTrashed()->find($id);
        $this->assertNotNull($row, 'A soft delete keeps the row.');
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('draft', $row->status);
    }

    /** @return array<string, array{string}> */
    public static function lockedStatuses(): array
    {
        return ['pending' => ['pending'], 'approved' => ['approved'], 'finalized' => ['finalized']];
    }

    #[DataProvider('lockedStatuses')]
    public function test_only_a_draft_can_be_deleted(string $status): void
    {
        $id = $this->formIn($status);

        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$id}")
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => "Only a draft can be deleted; form #{$id} is in {$status} status"]);

        $row = BirFormDraft::findOrFail($id);
        $this->assertNull($row->deleted_at);
        $this->assertSame($status, $row->status);
    }

    public function test_a_missing_form_is_404(): void
    {
        $this->actingAs($this->accounting())->deleteJson('/api/bir/drafts/999999')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Draft not found']);
    }

    public function test_an_employee_cannot_delete_a_draft(): void
    {
        $id = $this->formIn('draft');

        $this->actingAs(User::factory()->create(['role' => 'employee']))->deleteJson("/api/bir/drafts/{$id}")
            ->assertForbidden();

        $this->assertNull(BirFormDraft::findOrFail($id)->deleted_at);
    }

    public function test_nothing_is_deleted_while_the_switch_is_off(): void
    {
        $id = $this->formIn('draft');
        SystemSettings::set('bir_forms_enabled', false, null, 'boolean');

        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$id}")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'The BIR Form Assistant is not enabled']);

        $this->assertNull(BirFormDraft::findOrFail($id)->deleted_at);
    }

    public function test_a_returned_draft_can_be_deleted(): void
    {
        $id = $this->formIn('pending');
        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$id}/reject", ['reason' => 'Recheck item 25'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rejection_reason', 'Recheck item 25');

        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$id}")->assertOk();

        $this->assertNull(BirFormDraft::find($id));
        $this->assertNotNull(BirFormDraft::withTrashed()->find($id));
    }

    public function test_after_deleting_a_1601c_its_period_can_be_created_again(): void
    {
        $id = $this->formIn('draft');
        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$id}")->assertOk();

        $new = $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->json('data.id');

        $this->assertNotSame($id, $new);
    }

    public function test_a_deleted_correction_still_counts_and_its_version_is_not_reused(): void
    {
        $original = BirFormDraft::create([
            'form_type' => '1601-C', 'period' => '2026-07', 'status' => 'finalized', 'version' => 1,
            'prepared_by' => $this->accounting()->id, 'fields' => ['return_period' => ['value' => '07/2026']],
        ]);
        $v2 = $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$original->id}/revise")
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->accounting())->deleteJson("/api/bir/drafts/{$v2}")
            ->assertOk()
            ->assertJsonPath('message', "Draft #{$original->id}v2 deleted");

        $this->actingAs($this->accounting())->getJson("/api/bir/drafts/{$original->id}")
            ->assertJsonPath('data.amendments', ['made' => 1, 'limit' => 5]);

        $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$original->id}/revise")
            ->assertCreated()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.label', "#{$original->id}v3")
            ->assertJsonPath('data.amendments', ['made' => 2, 'limit' => 5]);
    }

    public function test_the_delete_is_in_the_audit_log(): void
    {
        $id = $this->formIn('draft');
        $accounting = $this->accounting();

        $this->actingAs($accounting)->deleteJson("/api/bir/drafts/{$id}")->assertOk();

        $log = AuditLog::where('auditable_type', BirFormDraft::class)->where('auditable_id', $id)
            ->where('event', 'deleted_birformdraft')
            ->first();
        $this->assertNotNull($log, 'No audit_logs row for the delete.');
        $this->assertSame($accounting->id, $log->user_id);
        $this->assertSame('draft', $log->old_values['status']);
        $this->assertArrayNotHasKey('source_snapshot', $log->old_values);
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
