<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Week 5 done-when: a 2316 needing three missing values can be completed entirely
 * through the prompts, and an invalid answer is refused with a message the user can
 * act on.
 *
 * Each test plays the frontend's part exactly: POST /bir/chat with draft_id for the
 * question, POST it again with the user's words, then save the returned answer
 * through PUT /bir/drafts/{id}. The chat itself never saves anything.
 */
class BirChatDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = User::factory()->create(['role' => 'accounting']);

        // The company's own details, so the only gaps left are the employee's.
        foreach ([
            'company_tin' => '987-654-321-000',
            'company_name' => 'Springboard Inc.',
            'company_address' => '1 Ayala Avenue, Makati',
            'company_zip' => '1226',
            'employer_type' => 'main',
        ] as $key => $value) {
            SystemSettings::set($key, $value);
        }
    }

    public function test_a_2316_needing_three_values_is_completed_entirely_through_the_chat(): void
    {
        $id = $this->draft2316('2025-03-01');

        $this->ask($id)->assertJsonPath('data.question.field', 'employee_rdo_code');
        $this->answerAndSave($id, 'employee_rdo_code', '049');

        $this->ask($id)->assertJsonPath('data.question.field', 'employee_registered_address');
        $this->answerAndSave($id, 'employee_registered_address', '12 Mabini St., Quezon City');

        $this->ask($id)->assertJsonPath('data.question.field', 'employee_birthdate');
        $this->answerAndSave($id, 'employee_birthdate', 'May 31, 1990');

        $this->ask($id)
            ->assertJsonPath('data.done', true)
            ->assertJsonPath('data.question', null)
            ->assertJsonPath('data.reply', 'That is everything this form needs from you. Check the preview, then use Submit to send it for review.');

        $fields = BirFormDraft::findOrFail($id)->fields;
        $this->assertSame('049', $fields['employee_rdo_code']['value']);
        $this->assertSame('12 Mabini St., Quezon City', $fields['employee_registered_address']['value']);
        $this->assertSame('1990-05-31', $fields['employee_birthdate']['value']);
        foreach (['employee_rdo_code', 'employee_registered_address', 'employee_birthdate'] as $key) {
            $this->assertSame('user', $fields[$key]['origin'], "{$key} is marked as supplied by a person.");
            $this->assertFalse($fields[$key]['edited'], "{$key} answered a question; it did not override a figure.");
        }

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/submit")->assertOk();
    }

    public function test_an_invalid_answer_is_refused_with_a_message_and_the_same_question_stays_open(): void
    {
        $id = $this->draft2316('2025-03-01');
        $this->answerAndSave($id, 'employee_rdo_code', '049');
        $this->answerAndSave($id, 'employee_registered_address', '12 Mabini St., Quezon City');

        $this->chat($id, 'employee_birthdate', '31/05/1990')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question.field', 'employee_birthdate')
            ->assertJsonPath('data.reply', 'Please enter the date as MM/DD/YYYY, for example 05/31/1990.');

        $this->assertNull(BirFormDraft::findOrFail($id)->fields['employee_birthdate']['value'], 'Nothing is saved.');
    }

    public function test_an_approximate_amount_is_asked_again_rather_than_rounded(): void
    {
        $id = $this->draft2316('2026-07-01');
        $this->answerAndSave($id, 'employee_rdo_code', '049');
        $this->answerAndSave($id, 'employee_registered_address', '12 Mabini St., Quezon City');
        $this->answerAndSave($id, 'employee_birthdate', '05/31/1990');
        $this->answerAndSave($id, 'has_previous_employer', 'oo');
        $this->answerAndSave($id, 'previous_employer_tin', '111-222-333-000');
        $this->answerAndSave($id, 'previous_employer_name', 'Acme Corp.');

        $this->chat($id, 'taxable_income_previous_employer', 'around 150k')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', 'Please give the exact amount, for example 150000.00.');

        $reply = $this->chat($id, 'taxable_income_previous_employer', '₱150,000')
            ->assertJsonPath('data.answer.value', '150000.00')
            ->json('data.reply');
        $this->assertStringStartsWith('Got it: ', $reply);
        $this->assertStringEndsWith(': ₱150,000.00.', $reply, 'The amount is read back for the user to check.');
    }

    public function test_a_yes_to_a_previous_employer_asks_for_their_details_and_says_why(): void
    {
        $id = $this->draft2316('2026-07-01');
        $this->answerAndSave($id, 'employee_rdo_code', '049');
        $this->answerAndSave($id, 'employee_registered_address', '12 Mabini St., Quezon City');
        $this->answerAndSave($id, 'employee_birthdate', '05/31/1990');

        $this->ask($id)
            ->assertJsonPath('data.question.field', 'has_previous_employer')
            ->assertJsonPath('data.question.reason', 'condition');
        $this->answerAndSave($id, 'has_previous_employer', 'opo');

        $reply = $this->ask($id)
            ->assertJsonPath('data.question.field', 'previous_employer_tin')
            ->json('data.reply');
        $this->assertStringContainsString('was yes', $reply);
    }

    public function test_an_answer_to_a_question_no_longer_open_is_not_applied_to_the_next_one(): void
    {
        $id = $this->draft2316('2025-03-01');
        $this->answerAndSave($id, 'employee_rdo_code', '049');

        // The RDO code was already filled (say, in the preview); this message must not
        // become the registered address.
        $this->chat($id, 'employee_rdo_code', '050')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question.field', 'employee_registered_address');
    }

    public function test_filling_a_draft_works_with_the_language_model_down(): void
    {
        $this->app->instance(LlmClientInterface::class, (new FakeLlmClient)->willFail());
        $id = $this->draft2316('2025-03-01');

        $this->chat($id, 'employee_rdo_code', '049')->assertOk()->assertJsonPath('data.answer.value', '049');
    }

    public function test_a_locked_form_is_not_filled_in(): void
    {
        $id = $this->draft2316('2025-03-01');
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);

        $this->ask($id)
            ->assertJsonPath('data.done', true)
            ->assertJsonPath('data.reply', "This form is finalized, so it can't be changed here.");
    }

    public function test_an_unknown_draft_is_a_404(): void
    {
        $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => 999])->assertNotFound();
    }

    private function ask(int $id): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $id])->assertOk();
    }

    private function chat(int $id, string $field, string $message): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', ['draft_id' => $id, 'field' => $field, 'message' => $message])
            ->assertOk();
    }

    /** What the frontend does with one answer: have it read, then save what came back. */
    private function answerAndSave(int $id, string $field, string $message): void
    {
        $answer = $this->chat($id, $field, $message)->assertJsonPath('data.answer.field', $field)->json('data.answer');

        $this->actingAs($this->accounting)
            ->putJson("/api/bir/drafts/{$id}", ['fields' => [$answer['field'] => $answer['value']]])
            ->assertOk();
    }

    private function draft2316(string $hireDate): int
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-CHAT-' . str_replace('-', '', $hireDate),
            'first_name' => 'Bir',
            'last_name' => 'Tester',
            'email' => "chat-{$hireDate}@example.com",
            'position' => 'Staff',
            'hire_date' => $hireDate,
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
            'tin_number' => '123-456-789-000',
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

        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');
    }
}
