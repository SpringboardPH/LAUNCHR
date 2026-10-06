<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
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

    private function makeFinalizedPayroll(): Payroll
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BIR-1',
            'first_name'  => 'Bir',
            'last_name'   => 'Tester',
            'email'       => 'bir-tester@example.com',
            'position'    => 'Staff',
            'hire_date'   => '2026-01-01',
            'salary'      => 26000,
            'status'      => 'active',
            'rate_type'   => 'monthly',
        ]);

        return Payroll::create([
            'employee_id'  => $employee->id,
            'cutoff_start' => '2026-09-01',
            'cutoff_end'   => '2026-09-15',
            'base_salary'  => 26000,
            'gross_pay'    => 13000,
            'deductions'   => [
                'SSS EE Contribution'      => 650,
                'PhilHealth EE Contribution' => 325,
                'Pag-IBIG EE Contribution' => 100,
                'Withholding Tax'          => 250,
            ],
            'allowances'   => [
                ['label' => 'Overtime Pay', 'amount' => 500],
            ],
            'status'       => 'finalized',
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

    // A draft that passes and is submitted is covered in BirSubmitTest, which builds one.
    public function test_a_draft_that_fails_validation_is_not_submitted()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $draft = $this->makeDraft('draft', $accounting);

        // No fields at all, so every required field is missing.
        $this->actingAs($accounting)
            ->postJson("/api/bir/drafts/{$draft->id}/submit")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_editing_payroll_after_creating_a_draft_does_not_change_its_snapshot()
    {
        $accounting = User::factory()->create(['role' => 'accounting']);
        $payroll = $this->makeFinalizedPayroll();

        $id = $this->actingAs($accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->assertJson(['success' => true])
            ->json('data.id');

        // source_snapshot is $hidden, so it is read off the model rather than the API.
        $snapshot = BirFormDraft::find($id)->source_snapshot;
        $this->assertCount(1, $snapshot['rows']);
        $this->assertSame('13000.00', $snapshot['rows'][0]['gross_pay']);

        $payroll->gross_pay = 99999;
        $payroll->save();

        $this->assertSame('13000.00', BirFormDraft::find($id)->source_snapshot['rows'][0]['gross_pay']);
    }
}
