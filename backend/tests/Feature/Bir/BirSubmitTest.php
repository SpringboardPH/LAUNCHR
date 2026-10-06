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
 * POST /bir/drafts/{id}/submit refuses a draft with any severity=error entry (422) and stores
 * the result either way. Warnings never block. Drafts are made the way the app makes them:
 * POST to create, PUT to answer.
 */
class BirSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_errors_is_refused_and_keeps_its_errors(): void
    {
        $this->september2026Payroll();
        $id = $this->create1601C();

        $response = $this->submit($id)->assertStatus(422)->assertJson(['success' => false]);

        $draft = BirFormDraft::findOrFail($id);
        $errors = array_filter($draft->validation_errors, fn (array $entry) => $entry['severity'] === 'error');
        $this->assertNotEmpty($errors);
        $this->assertSame('Draft has ' . count($errors) . ' errors; fix them before submitting', $response->json('message'));
        $this->assertEquals($draft->validation_errors, $response->json('data.validation_errors'), 'The draft comes back with its errors.');
        $this->assertSame('draft', $draft->status);
    }

    public function test_a_draft_with_no_entries_is_submitted(): void
    {
        $this->september2026Payroll();
        $id = $this->create1601C();
        $this->answerEveryError($id);

        $this->submit($id)->assertOk()->assertJson(['success' => true]);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('pending', $draft->status);
        $this->assertSame([], $draft->validation_errors);
    }

    public function test_a_draft_with_only_a_warning_is_submitted_and_keeps_the_warning(): void
    {
        // No payroll for the month: empty_period is a warning, so it doesn't block.
        $id = $this->create1601C();
        $this->answerEveryError($id);

        $this->submit($id)->assertOk();

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('pending', $draft->status);
        $this->assertSame(['empty_period'], array_column($draft->validation_errors, 'code'));
        $this->assertSame('warning', $draft->validation_errors[0]['severity']);
    }

    public function test_a_finalized_form_is_refused_without_being_validated(): void
    {
        $id = $this->create1601C();
        // Query-builder update: only the status changes, without going through the workflow.
        BirFormDraft::whereKey($id)->update(['status' => 'finalized', 'validation_errors' => '[]']);

        $this->submit($id)
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'Cannot move a form in finalized status to pending']);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('finalized', $draft->status);
        $this->assertSame([], $draft->validation_errors, 'A finalized form is left as filed.');
    }

    public function test_a_rejected_draft_is_validated_again_when_resubmitted(): void
    {
        $this->september2026Payroll();
        $id = $this->create1601C();
        $this->answerEveryError($id);
        $this->submit($id)->assertOk();

        $this->actingAs($this->accounting())
            ->postJson("/api/bir/drafts/{$id}/reject", ['reason' => 'Recheck the return'])
            ->assertOk();
        // Clearing a required answer returns it to pending, so it is missing again.
        $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['is_amended' => null]])
            ->assertOk();

        $this->submit($id)->assertStatus(422);

        $draft = BirFormDraft::findOrFail($id);
        $this->assertSame('draft', $draft->status);
        $this->assertContains('is_amended', array_column($draft->validation_errors, 'field'));
    }

    private function submit(int $draftId): TestResponse
    {
        return $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$draftId}/submit");
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
            $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$draftId}", ['fields' => $answers])->assertOk();
        }

        $this->fail('Errors were still reported after ten rounds of answers.');
    }

    private function september2026Payroll(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BIR-S1',
            'first_name' => 'Bir',
            'last_name' => 'Submitter',
            'email' => 'bir-submitter@example.com',
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
