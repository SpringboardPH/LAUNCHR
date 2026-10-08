<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Schemas\Form1601CSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /bir/drafts/{id}/approve validates first, like submit: the approver may have edited the
 * pending form since it was submitted, so it is checked again. Any severity=error entry is a
 * 422 and the form stays pending; warnings never block. The approver is never the preparer.
 */
class BirApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_form_edited_into_errors_is_refused_and_stays_pending(): void
    {
        $this->september2026Payroll();
        $id = $this->submittedClean1601C();
        // Clearing a required answer makes it missing again.
        $this->actingAs($this->approver())->putJson("/api/bir/drafts/{$id}", ['fields' => ['is_amended' => null]])->assertOk();

        $this->approve($id)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Draft has 1 error; fix it before approving']);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('pending', $draft->status);
        $this->assertNull($draft->approved_by);
        $this->assertContains('is_amended', array_column($draft->validation_errors, 'field'));
    }

    public function test_a_clean_pending_form_is_approved_by_the_approver(): void
    {
        $this->september2026Payroll();
        $id = $this->submittedClean1601C();

        $this->approve($id)->assertOk()->assertJson(['success' => true]);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('approved', $draft->status);
        $this->assertSame($this->approver()->id, $draft->approved_by);
    }

    public function test_a_pending_form_with_only_a_warning_is_approved(): void
    {
        // No payroll for the month: empty_period is a warning, so it doesn't block.
        $id = $this->submittedClean1601C();

        $this->approve($id)->assertOk();

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('approved', $draft->status);
        $this->assertSame(['empty_period'], array_column($draft->validation_errors, 'code'));
    }

    public function test_a_draft_is_refused_without_being_validated(): void
    {
        $id = $this->create1601C();

        $this->approve($id)
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'Cannot move a form in draft status to approved']);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame([], $draft->validation_errors, 'Not validated, so nothing stored.');
    }

    private function approve(int $draftId): TestResponse
    {
        return $this->actingAs($this->approver())->postJson("/api/bir/drafts/{$draftId}/approve");
    }

    /** A 1601-C with every error answered, submitted by the preparer, so it is pending. */
    private function submittedClean1601C(): int
    {
        $id = $this->create1601C();
        $this->answerEveryError($id);
        $this->actingAs($this->preparer())->postJson("/api/bir/drafts/{$id}/submit")->assertOk();

        return $id;
    }

    /**
     * Answers every field reported with severity error until none are left. An answer can make
     * another field required, so this loops; ten rounds is far more than any form needs.
     */
    private function answerEveryError(int $draftId): void
    {
        $schema = Form1601CSchema::byKey();

        for ($round = 0; $round < 10; $round++) {
            $draft = BirFormDraft::with('employee')->findOrFail($draftId);
            $errors = array_filter(app(BirDraftValidator::class)->validate($draft), fn (array $entry) => $entry['severity'] === 'error');
            if ($errors === []) {
                return;
            }

            $answers = [];
            foreach (array_column($errors, 'field') as $key) {
                $answers[$key] = match ($schema[$key]['type']) {
                    'boolean' => false,
                    'enum' => $schema[$key]['options'][0],
                    'decimal' => '0.00',
                    'integer' => 0,
                    'date' => '2026-01-01',
                    'month' => '09/2026',
                    default => 'X',
                };
            }
            $this->actingAs($this->preparer())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $answers])->assertOk();
        }

        $this->fail('Errors were still reported after ten rounds of answers.');
    }

    private function september2026Payroll(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BIR-A1',
            'first_name' => 'Bir',
            'last_name' => 'Approver',
            'email' => 'bir-approver@example.com',
            'position' => 'Staff',
            'hire_date' => '2025-01-06',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);

        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id,
                'cutoff_start' => $start,
                'cutoff_end' => $end,
                'base_salary' => 30000,
                'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [],
                'status' => 'finalized',
            ]);
        }
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->preparer())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    /** Prepares and submits. HR, since the approver is never the preparer (contract §5). */
    private function preparer(): User
    {
        return User::firstWhere('role', 'hr') ?? User::factory()->create(['role' => 'hr']);
    }

    private function approver(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
