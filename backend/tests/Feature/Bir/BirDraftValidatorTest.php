<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Schemas\Form1601CSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The required-fields rule, on drafts made the way the app makes them:
 * POST /bir/drafts to create, PUT /bir/drafts/{id} to answer.
 */
class BirDraftValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_1601c_reports_unanswered_questions_and_missing_company_settings(): void
    {
        $errors = $this->errorsByField($this->create1601C());

        foreach (['is_amended', 'sheets_attached'] as $key) {
            $this->assertSame('required', $errors[$key]['code'], $key);
            $this->assertSame('"' . Form1601CSchema::byKey()[$key]['label'] . '" is required.', $errors[$key]['message']);
        }

        // The test database has no company settings, so every settings field is a record gap.
        foreach (['company_tin', 'company_name', 'rdo_code'] as $key) {
            $this->assertSame('record_gap', $errors[$key]['code'], $key);
            $this->assertStringContainsString('should come from the company settings', $errors[$key]['message']);
        }
    }

    public function test_answering_yes_to_tax_relief_makes_its_details_required_and_says_why(): void
    {
        $id = $this->create1601C();
        $this->assertArrayNotHasKey('tax_relief_details', $this->errorsByField($id), 'Not before the question is answered.');

        $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['has_tax_relief' => true]])
            ->assertOk();

        $error = $this->errorsByField($id)['tax_relief_details'];
        $schema = Form1601CSchema::byKey();
        $this->assertSame('condition', $error['code']);
        $this->assertSame(
            "\"{$schema['tax_relief_details']['label']}\" is required because \"{$schema['has_tax_relief']['label']}\" is yes.",
            $error['message'],
        );
    }

    public function test_errors_point_at_draft_fields_in_form_order_and_clear_once_everything_is_answered(): void
    {
        $id = $this->create1601C();
        $schema = Form1601CSchema::byKey();

        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::findOrFail($id));
        $fieldKeys = array_keys(BirFormDraft::findOrFail($id)->fields);
        $reported = array_column($errors, 'field');
        $this->assertNotEmpty($reported);
        $this->assertSame([], array_diff($reported, $fieldKeys), 'Every error names a key in the draft.');
        $this->assertSame(array_values(array_intersect(array_keys($schema), $reported)), $reported, 'Errors come out in form order.');

        // Answer whatever is reported until nothing is. An answer can make another field
        // required, so this loops; ten rounds is far more than any form needs.
        for ($round = 0; $round < 10 && $reported !== []; $round++) {
            $answers = [];
            foreach ($reported as $key) {
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
            $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$id}", ['fields' => $answers])->assertOk();
            $reported = array_column($this->errorsByField($id), 'field');
        }

        $this->assertSame([], $reported);
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

    /** @return array<string, array{field: string, code: string, message: string}> */
    private function errorsByField(int $draftId): array
    {
        $errors = app(BirDraftValidator::class)->validate(BirFormDraft::with('employee')->findOrFail($draftId));

        return array_column($errors, null, 'field');
    }
}
