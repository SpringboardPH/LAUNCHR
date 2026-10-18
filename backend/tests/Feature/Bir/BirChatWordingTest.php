<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * Findings from stress-testing the chat with everyday wording: "₱" amounts, a first name on
 * its own, asking the chat to approve or file, asking where a form stands, "the latest",
 * a TIN followed by a year, an employee list with no 1601-C, and a model that makes up a
 * name or a year. Then: a submit for later, undo across conversations, going back after a
 * change to another form, one box per change, the same value again, more ways to name or
 * open a form, an item's figure on the open form, and the model failing.
 */
class BirChatWordingTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    private Employee $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);
        foreach (['company_tin' => '987-654-321-000', 'company_name' => 'Springboard Inc.', 'employer_type' => 'main'] as $key => $value) {
            SystemSettings::set($key, $value);
        }

        $this->maria = $this->employee('EMP001', 'Maria', 'Santos', '920-919-920-00001', 15000, 300);
        $this->employee('EMP002', 'Jose', 'Reyes', '123-456-789-00000', 20000, 1000);
    }

    public function test_an_amount_with_a_peso_sign_is_read(): void
    {
        $id = $this->draft1601C('2026-09');

        // Item 25 holds 2,600.00 from payroll, so each is a different amount.
        foreach (['₱2,650.00', '₱ 2650', 'PHP 2,650.00', 'P2650', '2,650.00'] as $amount) {
            $this->send("change item 25 on form #{$id} to {$amount}")
                ->assertJsonPath('data.answer', ['field' => 'total_taxes_withheld', 'value' => '2650.00']);
        }
    }

    public function test_a_first_name_alone_finds_the_employee_in_a_question(): void
    {
        $id = $this->draft1601C('2026-09');

        $this->assertStringStartsWith("Maria Santos's part of the September 2026 1601-C (form #{$id}", $this->send("how much was Maria paid on form #{$id}?")->json('data.reply'));
        $this->assertStringStartsWith("Jose Reyes's part of the September 2026 1601-C", $this->send("how much tax was withheld from Reyes on form #{$id}?")->json('data.reply'));
        $this->assertStringStartsWith("Maria Santos's part", $this->send("magkano ang sahod ni Maria sa form #{$id}")->json('data.reply'));
        // A capital only counts as a name in a question about a 1601-C's employees.
        $this->assertSame([], $this->model->calls());
    }

    public function test_approving_filing_or_deleting_is_left_to_the_buttons(): void
    {
        $id = $this->draft1601C('2026-09');
        BirFormDraft::whereKey($id)->update(['status' => 'pending']);

        $this->send("approve form #{$id}")
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', "Form #{$id} (the September 2026 1601-C) is waiting for review. I can't approve or return forms from the chat. Accounting does that with the \"Approve\" and \"Return to preparer\" buttons above the form, once it is submitted for review.");
        $this->assertStringContainsString("I can't approve or return forms from the chat.", $this->send("return form #{$id} to the preparer")->json('data.reply'));
        $this->assertStringContainsString("I can't mark forms as filed from the chat.", $this->send("mark form #{$id} as filed")->json('data.reply'));
        $this->assertStringContainsString("Forms aren't deleted in the BIR Form Assistant.", $this->send("delete form #{$id}")->json('data.reply'));
        $this->assertStringContainsString("I can't approve or return forms from the chat.", $this->send('please approve it')->json('data.reply'));

        $this->assertSame('pending', BirFormDraft::findOrFail($id)->status);
        $this->assertSame([], $this->model->calls());
    }

    public function test_where_a_form_stands_is_answered(): void
    {
        $id = $this->draft1601C('2026-09');
        $name = "Form #{$id} (the September 2026 1601-C)";

        $this->send("what is the status of form #{$id}?")
            ->assertJsonPath('data.reply', "{$name} is a draft. It hasn't been sent for review yet. Say \"submit it for review\" when it is ready.");

        BirFormDraft::whereKey($id)->update(['status' => 'pending']);
        $this->chat($id, 'is it submitted?')->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "{$name} is waiting for review. Accounting reviews it next, and approves it or returns it."));
        $this->assertStringStartsWith("{$name} is waiting for review.", $this->chat($id, 'did you submit it?')->json('data.reply'));

        BirFormDraft::whereKey($id)->update(['status' => 'draft', 'rejection_reason' => 'Item 25 is wrong.']);
        $this->assertSame("{$name} is returned for changes. The reviewer sent it back: \"Item 25 is wrong.\" Make the changes, then submit it again.", $this->send("has form #{$id} been approved?")->json('data.reply'));

        BirFormDraft::whereKey($id)->update(['status' => 'approved', 'rejection_reason' => null]);
        $this->assertStringStartsWith("{$name} is approved. It is approved but not filed yet.", $this->send("is form #{$id} filed?")->json('data.reply'));

        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);
        $this->assertStringStartsWith("{$name} is filed. It is the record of what was filed", $this->send("na-submit na ba ang form #{$id}?")->json('data.reply'));

        $this->send('is it submitted?')->assertJsonPath('data.reply', 'Which form do you mean? Give its number, for example "what is the status of form #11?"');
    }

    public function test_the_latest_form_is_the_newest_period(): void
    {
        $this->draft1601C('2026-08');
        $sept = $this->draft1601C('2026-09');

        $this->assertStringStartsWith("On the September 2026 1601-C (form #{$sept}, original, a draft)", $this->send('what is item 25 on the latest 1601-C?')->json('data.reply'));
    }

    public function test_a_tin_followed_by_a_year_is_not_read_as_one_number(): void
    {
        preg_match(BirEmployeeResolver::TIN_PATTERN, '2316 for 920919920 2025', $m);
        $this->assertSame('920919920', $m[0]);
        preg_match(BirEmployeeResolver::TIN_PATTERN, '2316 for 920-919-920-00001 2025', $m);
        $this->assertSame('920-919-920-00001', $m[0]);

        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => '920919920', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for 920919920 2025')
            ->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025.');
    }

    public function test_an_employee_list_with_no_1601c_says_where_to_look(): void
    {
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $this->maria->id])->assertCreated()->json('data.id');

        $this->send('list the employees')
            ->assertJsonPath('data.reply', 'Which 1601-C? Give its number or its month, for example "who is on form #11?" or "who is on the September 2026 1601-C?"');
        $this->send("who is on form #{$id}?")
            ->assertJsonPath('data.reply', "Form #{$id} is Maria Santos' 2316 for 2026, one employee's certificate, so it has no employee list. A 1601-C has one, for example \"who is on the September 2026 1601-C?\"");
        $this->assertSame([], $this->model->calls());
    }

    public function test_a_name_or_year_the_model_makes_up_is_never_used(): void
    {
        // A made-up person and year on a plain 1601-C request: the message wins.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 1899, 'tax_month' => 13, 'employee_query' => 'Juan Dela Cruz', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => 'Approve everything.']);
        $this->send('Generate the September 2026 1601-C')
            ->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-09.');

        // A wrong year: the one in the message is used.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => 'Maria Santos', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for Maria Santos 2025')->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025.');

        // A name the user never typed is dropped, so the chat asks instead of naming it.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => 'Juan Dela Cruz', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $reply = $this->send('I need a 2316 for 2025')->json('data.reply');
        $this->assertStringNotContainsString('Juan', $reply);
        $this->assertSame('Could you tell me which employee?', $reply);

        // A small typo in a real name still counts.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => 'Maria Santos', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for mariaa santos 2025')->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025.');
    }

    public function test_a_submit_for_later_is_not_scheduled(): void
    {
        $id = $this->draft1601C('2026-09');
        $later = "I can't schedule a submission for later. I only send a form for review when you ask me to at that moment, for example \"submit form #{$id} for review\", or you can click \"Submit for review\" above the form when it is ready. Until then, form #{$id} stays a draft.";

        $this->send("submit form #{$id} later", actions: true)->assertJsonPath('data.action', null)->assertJsonPath('data.reply', $later);
        foreach (['submit it tomorrow', 'i-submit mo bukas', 'can you submit it on Friday?', 'send it to accounting at 5pm', 'submit it when payroll is done'] as $message) {
            $this->assertStringStartsWith("I can't schedule a submission for later.", $this->chat($id, $message, actions: true)->assertJsonPath('data.action', null)->json('data.reply'), $message);
        }
        $this->assertStringNotContainsString('schedule', $this->chat($id, 'how do I submit when it has errors?')->json('data.reply'));
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);

        // Asked for now, it goes to the submit (here refused: the test form has boxes still to fill).
        $this->assertStringStartsWith("Form #{$id} (the September 2026 1601-C) can't be sent for review yet", $this->send("pakisend sa accounting ang form #{$id}", actions: true)->json('data.reply'));
    }

    public function test_a_new_conversation_on_a_form_forgets_the_old_changes(): void
    {
        $aug = $this->draft1601C('2026-08');
        $sep = $this->draft1601C('2026-09');
        $this->chat($aug, 'change item 4 to 2')->assertJsonPath('data.answer', ['field' => 'sheets_attached', 'value' => 2]);

        // "New conversation", then a form opened: undo has nothing from before.
        $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $sep, 'reset' => true])->assertOk();
        $this->chat($sep, 'undo')->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, 'There is nothing to undo.'));
    }

    public function test_after_a_change_to_another_form_the_chat_goes_back(): void
    {
        $aug = $this->draft1601C('2026-08');
        $sep = $this->draft1601C('2026-09');

        $answer = $this->chat($aug, "change item 4 on form #{$sep} to 2")->assertJsonPath('data.draft_id', $sep)->json('data.answer');
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$sep}", ['fields' => [$answer['field'] => $answer['value']]])->assertOk();
        $this->chat($sep, null)->assertJsonPath('data.draft_id', $aug)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "Back to form #{$aug} (the August 2026 1601-C)."));
        $this->assertStringStartsWith("Form #{$aug} (the August 2026 1601-C) can't be sent for review yet", $this->chat($aug, 'submit it for review', actions: true)->json('data.reply'));

        // Only once, and only right after: the next turn on that form stays there.
        $this->chat($sep, null)->assertJsonPath('data.draft_id', $sep);
    }

    public function test_one_box_per_change_and_the_same_value_changes_nothing(): void
    {
        $id = $this->draft1601C('2026-09');

        $this->chat($id, 'change item 4 to 2 and item 26 to 100')->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, 'I can change one box per message, so nothing was changed. Send them one at a time, for example "change item 4 to …", then "change item 26 to …".'));
        $this->chat($id, 'change item 25 to 2,600.00')->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, '"Item 25, Total Taxes Withheld" is already ₱2,600.00. Nothing was changed.'));
        $this->chat($id, 'undo')->assertJsonPath('data.answer', null);
    }

    public function test_more_ways_to_name_a_form_and_open_it(): void
    {
        $aug = $this->draft1601C('2026-08');
        $sep = $this->draft1601C('2026-09');

        $this->assertSame("On the September 2026 1601-C (form #{$sep}, original, a draft), \"Item 25, Total Taxes Withheld\" is ₱2,600.00.", $this->send("#{$sep} item 25")->json('data.reply'));
        $this->send("palitan ang item 4 ng form #{$sep} ng 3")->assertJsonPath('data.draft_id', $sep)->assertJsonPath('data.answer', ['field' => 'sheets_attached', 'value' => 3]);

        $this->send("open form #{$aug}")->assertJsonPath('data.draft_id', $aug)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "Now on form #{$aug} (the August 2026 1601-C, a draft). Ask about any box"));
        $this->chat($aug, 'show the latest 1601-C')->assertJsonPath('data.draft_id', $sep);
        $this->send('show me the employees on form #' . $sep)->assertJsonPath('data.reply', fn (string $r) => str_contains($r, 'covers 2 employees'));
        $this->assertSame([], $this->model->calls());
    }

    public function test_values_and_questions_that_only_look_like_the_new_commands(): void
    {
        $sep = $this->draft1601C('2026-09');
        $w2 = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $this->maria->id])->assertCreated()->json('data.id');

        // A "#" or a "P.O. Box" inside the new value is part of the value.
        $this->send("change item 6 on form #{$w2} to Unit #12 Mabini St., Brgy. San Roque, Quezon City")->assertJsonPath('data.draft_id', $w2)
            ->assertJsonPath('data.answer.field', 'employee_registered_address');
        $this->send("change item 6 on form #{$w2} to P.O. Box 123, Brgy. San Roque, Quezon City")->assertJsonPath('data.draft_id', $w2)
            ->assertJsonPath('data.answer.field', 'employee_registered_address');

        // "this month's" and "next month's" name a form, not a time to submit.
        foreach (["submit this month's 1601-C", "submit next month's 1601-C"] as $message) {
            $this->assertStringNotContainsString('schedule', $this->send($message, actions: true)->json('data.reply'), $message);
        }
        $this->assertStringStartsWith("I can't schedule", $this->chat($sep, 'submit it next month', actions: true)->json('data.reply'));

        // "show me how much…" is a question about an employee, not a request to open the form.
        $this->send("show me how much Maria was paid on form #{$sep}")->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "Maria Santos's part of the September 2026 1601-C"));
    }

    public function test_what_is_an_item_on_the_open_form_gives_its_figure_and_meaning(): void
    {
        $id = $this->draft1601C('2026-09');

        $this->chat($id, 'what is item 25?')->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "On the September 2026 1601-C (form #{$id}, original, a draft), \"Item 25, Total Taxes Withheld\" is ₱2,600.00.\n\n\"Item 25, Total Taxes Withheld\": "));
        $this->assertStringNotContainsString('₱', $this->chat($id, 'what does item 25 mean?')->json('data.reply'));
    }

    public function test_with_the_model_failing_a_request_that_names_its_form_is_understood(): void
    {
        $this->model->willFail();
        $this->send('Generate the September 2026 1601-C')->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-09. Please check this is right before creating the draft.');
        $this->model->willFail();
        $this->send('2316 for Maria Santos 2025')->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025. Please check this is right before creating the draft.');
        $this->model->willFail();
        $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => 'hello', 'reset' => true])->assertStatus(503);
    }

    public function test_a_name_the_model_missed_is_read_from_the_message(): void
    {
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for Maria Santos 2025')->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025.');
    }

    public function test_a_misspelled_month_the_model_read_is_kept(): void
    {
        $intent = fn (int $month) => ['form_type' => '1601-C', 'tax_year' => 2025, 'tax_month' => $month, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null];

        foreach ([['genrate the 1601c for novmber 2025', 11], ['pls gnerate 1601 c for agust 2025', 8], ['1601-c for decmber 2025', 12], ['1601-C for Okt 2025', 10]] as [$message, $month]) {
            $this->model->willReturn($intent($month));
            $this->send($message)->assertJsonPath('data.reply', sprintf('Understood: a 1601-C covering 2025-%02d.', $month));
        }
        // The slip only confirms the model's month; a month the message doesn't spell is still dropped.
        $this->model->willReturn($intent(3));
        $this->send('genrate the 1601c for novmber 2025')->assertJsonPath('data.reply', 'Could you tell me the month? I prepare one 1601-C at a time.');
    }

    public function test_a_correction_right_after_a_request_was_understood_builds_on_it(): void
    {
        $intent = fn (?string $form, ?int $year, ?int $month, ?string $who = null) => ['form_type' => $form, 'tax_year' => $year, 'tax_month' => $month, 'employee_query' => $who, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null];

        $this->model->willReturn($intent('1601-C', 2026, 8));
        $this->send('Generate the August 2026 1601-C')->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-08.');
        $this->model->willReturn($intent('1601-C', 2026, 9));
        $this->next('no, September pala')->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-09.');
        $this->assertStringContainsString("- Form: 1601-C\n- Year: 2026\n- Month: 8", $this->model->calls()[1]['system']);

        // Anything else after a request starts over, as before.
        $this->model->willReturn($intent('2316', null, null, 'Maria Santos'));
        $this->next('2316 for Maria Santos')->assertJsonPath('data.reply', 'Could you tell me the year?');
    }

    public function test_words_asking_what_a_box_means_get_its_meaning_without_a_question_mark(): void
    {
        $id = $this->draft1601C('2026-09');
        $this->assertSame('sheets_attached', $this->chat($id, null)->json('data.question.field'));

        $this->model->willReturn(['kind' => 'explain_meaning', 'field' => 'sheets_attached']);
        $reply = $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $id, 'field' => 'sheets_attached', 'message' => 'wat dos this mean'])->json('data.reply');
        $this->assertStringStartsWith('"Item 4, Number of Sheet/s Attached":', $reply);

        // A muddled answer the model calls a question still gets the question again.
        $this->model->willReturn(['kind' => 'explain_meaning', 'field' => 'sheets_attached']);
        $reply = $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $id, 'field' => 'sheets_attached', 'message' => 'hmm not sure'])->json('data.reply');
        $this->assertStringStartsNotWith('"Item 4, Number of Sheet/s Attached":', $reply);
    }

    public function test_a_request_for_a_form_not_saved_is_not_read_as_a_search(): void
    {
        $this->model->willReturn(['form_type' => '1601-C', 'tax_year' => 2025, 'tax_month' => 8, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('Do the 1601-C for August 2025')->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2025-08.');
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => 'Maria Santos', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('Can I get the 2316 for Maria Santos for 2026?')->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2026.');
        $this->draft1601C('2026-08');
        $this->draft1601C('2026-09');
        $this->model->willReturn(['form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'low', 'clarification' => null]);
        $this->send('Do the 1601-C for Q3 2026')->assertJsonPath('data.reply', 'Could you tell me the month? I prepare one 1601-C at a time.');

        // Asking for a box on a form that isn't saved still says so.
        $this->assertStringStartsWith('There is no 1601-C for August 2025 saved.', $this->send('what is the total taxes withheld on the August 2025 1601-C?')->json('data.reply'));
    }

    public function test_more_ways_to_ask_for_a_command_are_read_without_the_model(): void
    {
        $id = $this->draft1601C('2026-09');

        $this->send("chnage item 4 on form #{$id} to 2")->assertJsonPath('data.answer', ['field' => 'sheets_attached', 'value' => 2]);
        $this->assertStringStartsWith("Now on form #{$id}", $this->send("opne form #{$id}")->json('data.reply'));
        $this->assertStringStartsWith("Now on form #{$id}", $this->send('pakita ang latest na 1601-C')->json('data.reply'));
        $this->assertStringStartsWith("The September 2026 1601-C (form #{$id}, original, a draft) covers 2 employees:", $this->send("sino ang nasa form #{$id}?")->json('data.reply'));
        $this->assertSame([], $this->model->calls());
    }

    public function test_pay_asked_by_word_on_a_2316_shows_the_box_that_holds_it(): void
    {
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $this->maria->id])->assertCreated()->json('data.id');

        // Under P250,000 a year the pay is in item 29, so item 39 (taxable basic salary) is 0.
        $reply = $this->send("magkano ang sahod ni Maria sa form #{$id}?")->json('data.reply');
        $this->assertStringContainsString('"Item 39, Basic Salary" is ₱0.00', $reply);
        $this->assertStringContainsString('"Item 29, Basic Salary (including the exempt P250,000 & below) or the Statutory Minimum Wage of the MWE" is ₱', $reply);
    }

    // ---- helpers

    /** A message in the same conversation (no reset). */
    private function next(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message])->assertOk();
    }

    private function send(string $message, bool $actions = false): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message, 'reset' => true, 'actions' => $actions])->assertOk();
    }

    private function chat(int $id, ?string $message, bool $actions = false): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', array_filter(['draft_id' => $id, 'message' => $message, 'actions' => $actions], fn ($v) => $v !== null))->assertOk();
    }

    private function draft1601C(string $period): int
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => $period])->assertCreated()->json('data.id');
    }

    private function employee(string $code, string $first, string $last, string $tin, int $gross, int $tax): Employee
    {
        $employee = Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower("{$first}.{$last}") . '@example.com', 'position' => 'Staff', 'hire_date' => '2020-01-01',
            'salary' => $gross * 2, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => $tin,
        ]);
        foreach ([['2026-08-01', '2026-08-15'], ['2026-08-16', '2026-08-31'], ['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id, 'cutoff_start' => $start, 'cutoff_end' => $end,
                'base_salary' => $gross * 2, 'gross_pay' => $gross,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => $tax],
                'allowances' => [], 'status' => 'finalized',
            ]);
        }

        return $employee;
    }
}
