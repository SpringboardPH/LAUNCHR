<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirExplanationService;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Week 6 done-when: explanations name the actual source records and never recompute a
 * figure, and a general tax question is declined politely.
 *
 * The model only sorts a message (FakeLlmClient plays it, with the label queued). Every
 * reply checked here is written by PHP from the draft's records and the schema's help text.
 */
class BirChatExplanationTest extends TestCase
{
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = User::factory()->create(['role' => 'accounting', 'name' => 'Carlo Reyes']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);

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

    public function test_what_does_this_mean_reads_the_help_text_of_the_open_question_then_asks_it_again(): void
    {
        $id = $this->draft2316();
        $this->model->willReturn(['kind' => 'explain_meaning', 'field' => null]);

        $guidance = Form2316Schema::byKey()['employee_rdo_code']['guidance'];

        $reply = $this->chat($id, 'employee_rdo_code', 'ano ibig sabihin nito?')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question.field', 'employee_rdo_code')
            ->json('data.reply');

        $this->assertStringStartsWith("\"Item 5, RDO Code\": {$guidance}", $reply);
        $this->assertStringEndsWith('"Item 5, RDO Code" is needed for this form. What is it?', $reply, 'The open question is asked again.');
    }

    public function test_where_a_payroll_figure_came_from_names_the_records_saved_with_the_draft(): void
    {
        $id = $this->draft2316();
        $draft = BirFormDraft::findOrFail($id);
        $stored = $draft->fields['taxes_withheld_present']['value'];
        $recordIds = array_column($draft->source_snapshot['rows'], 'id');

        $this->model->willReturn(['kind' => 'explain_source', 'field' => 'taxes_withheld_present']);
        $reply = $this->chat($id, 'employee_rdo_code', 'saan galing yung tax withheld?')->json('data.reply');

        $this->assertStringContainsString('₱' . number_format((float) $stored, 2), $reply);
        $this->assertStringContainsString('2 finalized payroll records for Bir Tester', $reply);
        $this->assertStringContainsString('Sep 1 to Sep 15, 2026', $reply);
        foreach ($recordIds as $recordId) {
            $this->assertStringContainsString("record #{$recordId}", $reply);
        }
    }

    public function test_an_explanation_never_recomputes_a_figure_after_payroll_changes(): void
    {
        $id = $this->draft2316();
        $stored = BirFormDraft::findOrFail($id)->fields['taxes_withheld_present']['value'];

        Payroll::query()->update(['deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 9999]]);

        $this->model->willReturn(['kind' => 'explain_source', 'field' => 'taxes_withheld_present']);
        $reply = $this->chat($id, 'employee_rdo_code', 'where did the tax withheld come from?')->json('data.reply');

        $this->assertStringContainsString('₱' . number_format((float) $stored, 2), $reply, 'The figure on the draft, not a new sum.');
        $this->assertStringNotContainsString('19,998', $reply);
        $this->assertStringContainsString("later payroll changes don't affect it", $reply);
    }

    public function test_an_overridden_figure_says_who_changed_it_and_what_it_replaced(): void
    {
        $id = $this->draft2316();
        $original = BirFormDraft::findOrFail($id)->fields['taxes_withheld_present']['value'];
        $this->actingAs($this->accounting)
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['taxes_withheld_present' => '650.00']])
            ->assertOk();

        $this->model->willReturn(['kind' => 'explain_source', 'field' => 'taxes_withheld_present']);
        $reply = $this->chat($id, 'employee_rdo_code', 'why is item 25A 650?')->json('data.reply');

        $this->assertStringContainsString('is ₱650.00. It was typed in by Carlo Reyes on', $reply);
        $this->assertStringContainsString('The figure it replaced was ₱' . number_format((float) $original, 2) . '.', $reply);
    }

    public function test_an_answer_given_in_the_chat_is_explained_as_entered_by_a_person(): void
    {
        $id = $this->draft2316();
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['employee_rdo_code' => '049']])->assertOk();

        $this->model->willReturn(['kind' => 'explain_source', 'field' => 'employee_rdo_code']);
        $reply = $this->chat($id, 'employee_registered_address', 'where did the RDO code come from?')->json('data.reply');

        $this->assertStringStartsWith('"Item 5, RDO Code" is 049. A person entered it while filling in this draft', $reply);
    }

    public function test_a_tax_question_while_filling_in_is_declined_then_the_open_question_is_asked_again(): void
    {
        $id = $this->draft2316();
        $this->model->willReturn(['kind' => 'tax_question', 'field' => null]);

        $this->chat($id, 'employee_rdo_code', 'how much is Maria Santos earning?')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question.field', 'employee_rdo_code')
            ->assertJsonPath('data.reply', BirExplanationService::DECLINE . "\n\n" . '"Item 5, RDO Code" is needed for this form. What is it?');
    }

    public function test_a_tax_question_before_any_draft_is_declined_and_not_remembered(): void
    {
        $this->model->willReturn([
            'form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => 'Maria Santos',
            'kind' => 'tax_question', 'confidence' => 'low', 'clarification' => 'Which month do you need the salary for?',
        ]);

        $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', ['message' => "how much is maria santos's salary", 'reset' => true])
            ->assertOk()
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', BirExplanationService::DECLINE);

        $this->assertSame([], cache()->get("bir:conversation:{$this->accounting->id}", []), 'Nothing from it is remembered.');
    }

    public function test_missing_details_are_asked_in_our_words_not_the_models(): void
    {
        $this->model->willReturn([
            'form_type' => '1601-C', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => null,
            'kind' => 'form_request', 'confidence' => 'low', 'clarification' => 'Which months of the salary do you want?',
        ]);

        $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', ['message' => 'a 1601-C for every month of 2025', 'reset' => true])
            ->assertJsonPath('data.reply', 'Could you tell me the month? I prepare one 1601-C at a time.');
    }

    public function test_a_box_the_model_made_up_falls_back_to_the_open_question(): void
    {
        $id = $this->draft2316();
        $this->model->willReturn(['kind' => 'explain_meaning', 'field' => 'not_a_real_box']);

        $reply = $this->chat($id, 'employee_rdo_code', 'what is this?')->json('data.reply');

        $this->assertStringStartsWith('"Item 5, RDO Code": ', $reply);
    }

    public function test_with_the_model_down_a_muddled_answer_is_simply_asked_again(): void
    {
        $id = $this->draft2316();
        $this->answer($id, 'employee_rdo_code', '049');
        $this->answer($id, 'employee_registered_address', '12 Mabini St.');

        $this->model->willFail();
        $this->chat($id, 'employee_birthdate', 'what?')
            ->assertJsonPath('data.reply', 'Please enter the date as MM/DD/YYYY, for example 05/31/1990.');
    }

    public function test_a_locked_form_can_still_be_explained(): void
    {
        $id = $this->draft2316();
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);
        $this->model->willReturn(['kind' => 'explain_meaning', 'field' => 'taxes_withheld_present']);

        $this->chat($id, null, 'what does item 25A mean?')
            ->assertJsonPath('data.question', null)
            ->assertJsonPath('data.reply', '"Item 25A, Amount of Taxes Withheld — Present Employer": ' . Form2316Schema::byKey()['taxes_withheld_present']['guidance']);
    }

    public function test_a_question_about_a_box_with_no_box_named_and_none_open_asks_which(): void
    {
        $id = $this->draft2316();
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);
        $this->model->willReturn(['kind' => 'explain_source', 'field' => null]);

        $this->chat($id, null, 'where did this come from?')->assertJsonPath('data.reply', BirExplanationService::WHICH_BOX);
    }

    public function test_a_year_that_has_not_happened_is_refused_with_the_reason(): void
    {
        $this->model->willReturn([
            'form_type' => '1601-C', 'tax_year' => 2030, 'tax_month' => 8, 'employee_query' => null,
            'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null,
        ]);

        $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', ['message' => '1601-C for August 2030', 'reset' => true])
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', "I can't prepare a form for 2030 because that year hasn't happened yet. Could you tell me the year?");
    }

    public function test_a_bare_period_asks_which_form_even_when_the_model_calls_it_a_tax_question(): void
    {
        // What Ollama did in testing (B5): a tax question, with the 1601-C guessed.
        $this->model->willReturn([
            'form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => 9, 'employee_query' => null,
            'kind' => 'tax_question', 'confidence' => 'high', 'clarification' => null,
        ]);

        $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', ['message' => 'September 2026', 'reset' => true])
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', 'Could you tell me which form?');
    }

    public function test_a_muddled_amount_gets_the_parsers_message_not_an_explanation(): void
    {
        // A mid-year hire with a previous employer, so item 22 (an amount) is the open question.
        $id = $this->draft2316('2026-03-02');
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => [
            'employee_rdo_code' => '049', 'employee_registered_address' => '12 Mabini St.', 'employee_birthdate' => '1990-05-31',
            'has_previous_employer' => true, 'previous_employer_tin' => '111-222-333-000', 'previous_employer_name' => 'ABC Corp',
        ]])->assertOk();

        // What Ollama did in testing (D4): "where did this come from?" about the open box.
        $this->model->willReturn(['kind' => 'explain_source', 'field' => null]);

        $this->chat($id, 'taxable_income_previous_employer', 'around 150k')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question.field', 'taxable_income_previous_employer')
            ->assertJsonPath('data.reply', 'Please give the exact amount, for example 150000.00.');
    }

    private function chat(int $id, ?string $field, string $message): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', array_filter(['draft_id' => $id, 'field' => $field, 'message' => $message]))
            ->assertOk();
    }

    private function answer(int $id, string $field, string $message): void
    {
        $answer = $this->chat($id, $field, $message)->json('data.answer');
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => [$answer['field'] => $answer['value']]])->assertOk();
    }

    private function draft2316(string $hired = '2025-03-01'): int
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-EXPLAIN', 'first_name' => 'Bir', 'last_name' => 'Tester',
            'email' => 'explain@example.com', 'position' => 'Staff', 'hire_date' => $hired,
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => '123-456-789-000',
        ]);

        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id, 'cutoff_start' => $start, 'cutoff_end' => $end,
                'base_salary' => 30000, 'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [], 'status' => 'finalized',
            ]);
        }

        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');
    }
}
