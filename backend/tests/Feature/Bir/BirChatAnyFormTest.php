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
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The supervisor's changes, one group each:
 *   - a box on any saved form can be read, whatever its status;
 *   - a box on any form can be changed without opening it ("edit 2316 item #6 for maria
 *     santos, change it to …"), applied at once, and "undo" puts it back;
 *   - a 2316 can be asked for by TIN ("2316 for 920-919-920-00001 2024");
 *   - the chat knows whether a form can still be changed: a filed form is never changed,
 *     only corrected in a new version (the button, or "yes" in the chat), and "done" sends
 *     a form for review.
 * All of it is read in PHP, so these tests also check the model is never asked.
 */
class BirChatAnyFormTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private const ADDRESS = '12 Mabini St., Brgy. San Roque, Quezon City';

    /** How the form keeps it: text on a form is stored in capitals (FieldFormat::normalize, Dev A). */
    private const STORED = '12 MABINI ST., BRGY. SAN ROQUE, QUEZON CITY';

    private User $accounting;

    private FakeLlmClient $model;

    private Employee $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = User::factory()->create(['role' => 'accounting', 'name' => 'Carlo Reyes']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);

        foreach ([
            'company_tin' => '987-654-321-000', 'company_name' => 'Springboard Inc.',
            'company_address' => '1 Ayala Avenue, Brgy. San Lorenzo, Makati City', 'company_zip' => '1226', 'employer_type' => 'main',
        ] as $key => $value) {
            SystemSettings::set($key, $value);
        }

        $this->maria = $this->employee('EMP001', 'Maria', 'Santos', '920-919-920-00001');
    }

    // ---- Reading a box on any saved form

    public function test_a_box_is_read_from_any_saved_form_whatever_its_status(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        $this->save($id, ['employee_registered_address' => self::ADDRESS]);
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);

        $this->send("what is item 6 on Maria Santos's 2316 for 2026?", reset: true)
            ->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$id}, version 1, approved), \"Item 6, Registered Address\" is " . self::STORED . '.');

        // By TIN, any word order, and a box with several parts.
        $reply = $this->send('show item 4 of the 2316 2026 for 920-919-920-00001')->json('data.reply');
        $this->assertStringContainsString('"Item 4, Employee\'s Name — Last Name" is SANTOS.', $reply);
        $this->assertStringContainsString('"Item 4, Employee\'s Name — Middle Name" is empty.', $reply);

        // "Where did it come from?" on that form gets its source, not just its meaning.
        $this->assertStringContainsString('It comes from payroll: 2 finalized payroll records for Maria Santos', $this->send("where did item 25A on Maria's 2316 for 2026 come from?")->json('data.reply'));

        $c = $this->draft1601C('2026-09');
        $this->send('how much is item 14 on the September 2026 1601-C?')
            ->assertJsonPath('data.reply', "On the September 2026 1601-C (form #{$c}, version 1, a draft), \"Item 14, Total Amount of Compensation\" is ₱30,000.00.");

        // A question about what an item means, with no form named, is still the help text.
        $this->assertStringStartsWith('"Item 14, Total Amount of Compensation": ', $this->send('what is item 14 on the 1601-C?')->json('data.reply'));

        $this->assertSame([], $this->model->calls());
    }

    public function test_two_forms_with_the_same_details_are_listed_never_guessed(): void
    {
        // What user-side testing found: two drafts for the same person and year, one changed by
        // hand, and the chat read the other one. They are two forms, told apart by number.
        $old = $this->draft2316($this->maria, '2026');
        $new = $this->draft2316($this->maria, '2026');
        $this->save($new, ['employee_rdo_code' => '049']);

        $this->send("what is item 5 on maria santos's 2316 for 2026?", reset: true)
            ->assertJsonPath('data.reply', "More than one form fits. Which one do you mean?\n- form #{$new}: Maria Santos' 2316 for 2026, version 1, a draft\n- form #{$old}: Maria Santos' 2316 for 2026, version 1, a draft\nSay its number, for example \"form #{$new}\".");
        $this->send("#{$new}")
            ->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$new}, version 1, a draft), \"Item 5, RDO Code\" is 049.");

        // By number, "form #" or "draft #" (never read as an item number).
        $this->send("what is item 5 on form #{$old}?")->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$old}, version 1, a draft), \"Item 5, RDO Code\" is empty.");
        $this->assertStringEndsWith('is 049.', $this->send("what is item 5 on draft #{$new}")->json('data.reply'));

        // By status.
        BirFormDraft::whereKey($old)->update(['status' => 'finalized']);
        $this->assertStringContainsString("(form #{$old}, version 1, filed)", $this->send("what is item 5 on maria santos's filed 2316 for 2026?")->json('data.reply'));
        $this->assertStringContainsString("(form #{$new}, version 1, a draft)", $this->send("what is item 5 on the draft 2316 for maria santos 2026?")->json('data.reply'));
        $this->send("what is item 5 on maria santos's pending 2316 for 2026?")
            ->assertJsonPath('data.reply', "There is no pending 2316 for Maria Santos for 2026 saved. Ask for it to prepare one, or check the details.");

        // A number that doesn't fit the rest, or no such form.
        $this->send("what is item 5 on form #{$old} for 2025?")
            ->assertJsonPath('data.reply', "Form #{$old} is Maria Santos' 2316 for 2026 (form #{$old}, version 1, filed), which doesn't match the rest of what you said. Which form do you mean?");
        $this->send('what is item 5 on form #999?')->assertJsonPath('data.reply', 'There is no form #999. Which form do you mean?');

        $this->assertSame([], $this->model->calls());
    }

    public function test_versions_and_returned_forms_are_told_apart(): void
    {
        $v1 = $this->draft1601C('2026-09');
        BirFormDraft::whereKey($v1)->update(['status' => 'finalized']);
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$v1}/revise")->assertCreated()->json('data.id');
        $this->save($v2, ['sheets_attached' => 3]);

        // An amendment not filed yet also says what the filed version holds.
        $this->send('what is item 4 on amendment 1 of the September 2026 1601-C?', reset: true)
            ->assertJsonPath('data.reply', "On the September 2026 1601-C (form #{$v2}, version 2, a draft), \"Item 4, Number of Sheet/s Attached\" is 3.\nThe filed version (form #{$v1}, version 1) has empty.");
        $this->assertStringContainsString("(form #{$v1}, version 1, filed)", $this->send('what is item 4 on the original September 2026 1601-C?')->json('data.reply'));
        $this->assertStringContainsString("(form #{$v2}, version 2", $this->send('what is item 4 on the latest September 2026 1601-C?')->json('data.reply'));
        $this->assertStringContainsString("(form #{$v2}, version 2", $this->send('what is item 4 on version 2 of the September 2026 1601-C?')->json('data.reply'));

        BirFormDraft::whereKey($v2)->update(['rejection_reason' => 'Check item 26.']);
        $this->send('what is item 4 on the September 2026 1601-C?')
            ->assertJsonPath('data.reply', "More than one form fits. Which one do you mean?\n- form #{$v2}: the September 2026 1601-C, version 2, returned for changes\n- form #{$v1}: the September 2026 1601-C, version 1, filed\nSay its number, for example \"form #{$v2}\".");
        $this->send('the filed one')->assertJsonPath('data.reply', fn (string $r) => str_contains($r, "(form #{$v1}, version 1, filed)"));
        $this->assertStringContainsString("(form #{$v2}, version 2, returned for changes)", $this->send('what is item 4 on the returned September 2026 1601-C?')->json('data.reply'));
        $this->assertStringContainsString("(form #{$v2}, version 2, returned for changes)", $this->send('what is item 4 on the returned 1601-C?')->json('data.reply'), 'Only drafts the reviewer sent back.');
    }

    public function test_a_missing_year_or_person_is_asked_and_a_short_answer_finishes_the_question(): void
    {
        $this->draft2316($this->maria, '2025');
        $id = $this->draft2316($this->maria, '2026');
        $this->save($id, ['employee_rdo_code' => '049']);
        $cruz = $this->employee('EMP002', 'Maria', 'Cruz', '111-222-333-000');

        $this->assertStringStartsWith("More than one form fits. Which one do you mean?\n- form #{$id}: Maria Santos' 2316 for 2026", $this->send("what's in item 5 on maria santos's 2316?", reset: true)->json('data.reply'));
        $this->send('2026')
            ->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$id}, version 1, a draft), \"Item 5, RDO Code\" is 049.");

        $this->draft2316($cruz, '2026');
        $this->send("what's in item 5 on the 2316 for maria 2026?")
            ->assertJsonPath('data.reply', 'More than one person matches that: Maria Santos (EMP001), Maria Cruz (EMP002). Which one did you mean?')
            ->assertJsonCount(2, 'data.candidates');
        $this->send('EMP001')->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$id}, version 1, a draft), \"Item 5, RDO Code\" is 049.");

        $this->send('what is in item 5 on the 2316 for 2024 for maria santos')
            ->assertJsonPath('data.reply', 'There is no 2316 for Maria Santos for 2024 saved. Ask for it to prepare one, or check the details.');
        $this->send('what is in item 5 of the 2316 for 555-555-555-000 2026')
            ->assertJsonPath('data.reply', 'No employee has the TIN 555-555-555-000. Please check the number.');
    }

    public function test_a_box_is_read_by_its_name_and_a_tie_is_asked_not_guessed(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        $this->save($id, ['employee_birthdate' => '1990-05-31']);
        $on = "On Maria Santos' 2316 for 2026 (form #{$id}, version 1, a draft), ";

        $this->send("what is the company TIN on Maria Santos's 2316 for 2026?", reset: true)
            ->assertJsonPath('data.reply', $on . '"Item 12, TIN" is 987-654-321-00000.');
        $this->send("what's her birthday on maria santos's 2316 for 2026?")
            ->assertJsonPath('data.reply', $on . '"Item 7, Date of Birth (MM/DD/YYYY)" is 05/31/1990.');
        $this->assertStringContainsString('"Item 4, Employee\'s Name — First Name" is MARIA.', $this->send("show the employee's name on the 2316 for 920-919-920-00001 2026")->json('data.reply'));

        // Three boxes are called "TIN": the chat lists them, and "item 3" finishes the question.
        $this->send("what is the TIN on maria santos's 2316 for 2026?")
            ->assertJsonPath('data.reply', "Which one do you mean? On the 2316 that could be:\n- item 3: the employee's Taxpayer Identification Number\n- item 12: the company's TIN\n- item 16: the previous employer's TIN\nSay the item number, for example \"item 3\".");
        $this->send('item 3')->assertJsonPath('data.reply', $on . '"Item 3, TIN" is 920-919-920-00001.');

        // On the open form, a value question by name; a box nobody has a name for is left to the model's list.
        $this->assertMatchesRegularExpression('/^On Maria Santos\' 2316 for 2026 \(form #\d+, version 1, a draft\), "Item 24, Tax Due" is ₱[\d,]+\.\d\d\./u', $this->chat($id, null, 'magkano ang tax due?')->json('data.reply'));
        $this->assertSame([], $this->model->calls(), 'Box names are read in PHP.');

        $this->model->willReturn(['kind' => 'explain_source', 'field' => 'taxes_withheld_present']);
        $this->assertStringContainsString('"Item 25A, Amount of Taxes Withheld — Present Employer" is ₱', $this->send("what is the thingamajig on maria santos's 2316 for 2026?")->json('data.reply'));
        // A request with no box in it is never read as a lookup, so a new form can still be asked for.
        $calls = count($this->model->calls());
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => 'maria santos', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for maria santos 2026?', reset: true)->assertJsonPath('data.understood', true);
        $this->assertCount($calls + 1, $this->model->calls(), 'Asked once, as a request.');

        $this->model->willReturn(['kind' => 'tax_question', 'field' => null]);
        $this->assertStringStartsWith("I can't give tax advice", $this->send("how much is maria santos earning on her 2316 for 2026?")->json('data.reply'));
    }

    // ---- Changing a box: the open form, or another by its number; and undo

    public function test_a_form_that_is_not_open_is_only_changed_by_its_number(): void
    {
        $id = $this->draft2316($this->maria, '2026');

        // Details alone: the chat names the form that fits and asks for its number.
        $this->send('edit 2316 item #6 for maria santos 2026, change it to ' . self::ADDRESS, reset: true)
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "To change a form that isn't open here, I need its number. That sounds like form #{$id}: Maria Santos' 2316 for 2026, version 1, a draft. Reply \"form #{$id}\" to change it.");
        $res = $this->send("#{$id}")
            ->assertJsonPath('data.draft_id', $id)
            ->assertJsonPath('data.answer', ['field' => 'employee_registered_address', 'value' => self::STORED]);
        $this->assertSame("On Maria Santos' 2316 for 2026 (form #{$id}, version 1, a draft): Changed \"Item 6, Registered Address\" to " . self::STORED . '. Say "undo" to put it back.', $res->json('data.reply'));
        $this->save($id, $res->json('data.answer')); // what the chat panel does with an answer

        // With the number it is changed straight away, and the form named after the value is not part of it.
        $this->send("change item 5 to 049 on form #{$id}")
            ->assertJsonPath('data.draft_id', $id)
            ->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => '049']);
        $this->save($id, ['employee_rdo_code' => '049']);

        // Two forms fit: both are listed, and the number picks one.
        $other = $this->draft2316($this->maria, '2026');
        $this->send('change item 5 on maria santos 2316 2026 to 051', reset: true)
            ->assertJsonPath('data.reply', "To change a form that isn't open here, I need its number. These fit:\n- form #{$other}: Maria Santos' 2316 for 2026, version 1, a draft\n- form #{$id}: Maria Santos' 2316 for 2026, version 1, a draft\nSay its number, for example \"form #{$other}\".");
        $this->send("form #{$other}")->assertJsonPath('data.draft_id', $other)->assertJsonPath('data.answer.value', '051');

        // The form open in the chat needs no number.
        $this->chat($id, null, 'change item 5 to 052')->assertJsonPath('data.draft_id', $id)->assertJsonPath('data.answer.value', '052');
        $this->chat($id, null, 'change item 5 on maria santos 2316 2026 to 053')->assertJsonPath('data.draft_id', $id)->assertJsonPath('data.answer.value', '053');

        $this->assertSame([], $this->model->calls());
    }

    public function test_undo_takes_back_the_chats_changes_newest_first(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        $this->save($id, $this->send('change item 6 on form #' . $id . ' to ' . self::ADDRESS, reset: true)->json('data.answer'));
        $this->save($id, $this->send("change item 5 on form #{$id} to 049")->json('data.answer'));

        $this->chat($id, null, 'undo')
            ->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => null])
            ->assertJsonPath('data.reply', 'Undid the change to "Item 5, RDO Code". It is empty again.');
        $this->save($id, ['employee_rdo_code' => null]);
        $this->chat($id, null, 'undo item 6')
            ->assertJsonPath('data.answer', ['field' => 'employee_registered_address', 'value' => null]);
        $this->assertStringStartsWith(
            'There is nothing to undo. I can undo changes made here in the chat; changes made on the form itself have "Restore calculated" there.',
            $this->chat($id, null, 'undo')->json('data.reply'),
        );
    }

    public function test_undo_brings_back_a_calculated_figure_and_never_overwrites_a_later_change(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        $tax = BirFormDraft::findOrFail($id)->fields['taxes_withheld_present']['value'];

        $this->save($id, $this->chat($id, null, 'change item 25A to 2,900')->json('data.answer'));
        $this->chat($id, null, 'undo')
            ->assertJsonPath('data.answer', ['field' => 'taxes_withheld_present', 'value' => null])
            ->assertJsonPath('data.reply', 'Undid the change to "Item 25A, Amount of Taxes Withheld — Present Employer". It is back to the calculated figure, ₱' . number_format((float) $tax, 2) . '.');
        $this->save($id, ['taxes_withheld_present' => null]);
        $this->assertSame($tax, BirFormDraft::findOrFail($id)->fields['taxes_withheld_present']['value']);

        // An answer to one of the chat's questions can be undone too, unless it changed since.
        $this->chat($id, 'employee_rdo_code', '049')->assertJsonPath('data.answer.value', '049');
        $this->save($id, ['employee_rdo_code' => '050']); // changed on the form after the chat saved it
        $this->chat($id, null, 'undo')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"Item 5, RDO Code" has been changed again since (it is now 050), so I left it as it is.' . "\n\n" . $this->chat($id, null, null)->json('data.reply'));
    }

    public function test_whether_a_form_can_be_changed_follows_its_status_and_role(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        $hr = User::factory()->create(['role' => 'hr']);

        BirFormDraft::whereKey($id)->update(['status' => 'pending']);
        $this->actingAs($hr)->postJson('/api/bir/chat', ['message' => "change item 5 on form #{$id} to 049", 'reset' => true])
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 is waiting for review, and only accounting can change it now. You can still ask what any box holds.");
        $this->send("change item 5 on form #{$id} to 049", reset: true)
            ->assertJsonPath('data.answer.value', '049');

        BirFormDraft::whereKey($id)->update(['status' => 'approved']);
        $this->send("change item 5 on form #{$id} to 049")
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 (form #{$id}) is approved and waiting to be filed, so it can't be changed. Once it is marked as filed, a correction can be started.");
    }

    // ---- A filed form: corrected in a new version, never changed

    public function test_a_filed_2316_points_to_the_button_or_offers_to_start_a_correction(): void
    {
        $id = $this->draft2316($this->maria, '2026');
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);
        $change = "change item 6 on form #{$id} to " . self::ADDRESS;

        // A chat panel that can't start a correction is only pointed to the button.
        $this->send($change, reset: true)
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 is filed, so it can't be changed. To fix it, open it and click \"Start a correction\".");

        // One that can is offered both; "yes" asks it to start the correction.
        $this->send($change, actions: true)
            ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 is filed, so it can't be changed. To fix it, open it and click \"Start a correction\", or reply yes and I'll start a correction (version 2) and make the change there.");
        $this->send('yes', actions: true)
            ->assertJsonPath('data.action', ['type' => 'revise', 'draft_id' => $id])
            ->assertJsonPath('data.reply', null);

        // The panel starts it through the workflow, then the chat makes the change there.
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/revise")->assertCreated()->json('data.id');
        $this->chat($v2, null, null, actions: true)
            ->assertJsonPath('data.draft_id', $v2)
            ->assertJsonPath('data.answer', ['field' => 'employee_registered_address', 'value' => self::STORED])
            ->assertJsonPath('data.reply', "Started correction version 2 (form #{$v2}) from Maria Santos' 2316 for 2026. The filed version stays as it is. Changed \"Item 6, Registered Address\" to " . self::STORED . '. Tell me any other changes, and say done when the correction is finished.');
        $this->save($v2, ['employee_registered_address' => self::ADDRESS]);
        $this->assertNull(BirFormDraft::findOrFail($id)->fields['employee_registered_address']['value'], 'The filed version is untouched.');

        // Asked again about the filed form: its open correction is named, and its number makes the change.
        $this->send("change item 5 on form #{$id} to 049", reset: true)
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Form #{$id} (Maria Santos' 2316 for 2026) is filed, so it can't be changed. Its correction, form #{$v2} (version 2, a draft), is still open. Reply \"form #{$v2}\" to make the change there.");
        $this->send("#{$v2}")->assertJsonPath('data.draft_id', $v2)->assertJsonPath('data.answer.value', '049');

        // "no" to an offer changes nothing.
        $this->save($v2, ['employee_rdo_code' => '049']);
        BirFormDraft::whereKey($v2)->update(['status' => 'finalized']);
        $this->send("change item 6 on form #{$v2} to " . self::ADDRESS, actions: true);
        $this->send('no', actions: true)->assertJsonPath('data.reply', 'Okay, nothing was changed.')->assertJsonPath('data.action', null);

        $this->assertSame([], $this->model->calls());
    }

    public function test_a_filed_1601c_counts_its_amendments_up_to_the_monthly_limit(): void
    {
        $v1 = $this->draft1601C('2026-09');
        BirFormDraft::whereKey($v1)->update(['status' => 'finalized']);
        $this->send("change item 4 on form #{$v1} to 2", reset: true, actions: true)
            ->assertJsonPath('data.reply', "The September 2026 1601-C is filed, so it can't be changed. It has 5 of 5 amendments left. To fix it, open it and click \"Start a correction\", or reply yes and I'll start amendment 1 of 5 and make the change there.");

        // "yes": the panel starts amendment 1 through revise, then the chat makes the change.
        $this->send('yes', actions: true)->assertJsonPath('data.action', ['type' => 'revise', 'draft_id' => $v1]);
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$v1}/revise")->assertCreated()->json('data.id');
        $this->chat($v2, null, null, actions: true)
            ->assertJsonPath('data.answer', ['field' => 'sheets_attached', 'value' => 2])
            ->assertJsonPath('data.reply', "Started amendment 1 (form #{$v2}) from the September 2026 1601-C. The filed version stays as it is. Changed \"Item 4, Number of Sheet/s Attached\" to 2. Tell me any other changes, and say done when the amendment is finished.");

        // Amendment 5 filed: nothing more can be offered, and revise refuses a sixth.
        BirFormDraft::whereKey($v2)->delete();
        BirFormDraft::whereKey($v1)->update(['version' => 6]);
        $this->send("change item 4 on form #{$v1} to 2", actions: true)
            ->assertJsonPath('data.reply', "The September 2026 1601-C is filed and has already been amended 5 times, the most allowed for one month, so it can't be changed or amended again.");
        $this->send('yes', actions: true)->assertJsonPath('data.action', null);
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$v1}/revise")->assertStatus(400);
    }

    public function test_done_sends_the_form_for_review_once_nothing_blocks_it(): void
    {
        $id = $this->draft2316($this->maria, '2026');

        $reply = $this->chat($id, null, 'done', actions: true)->assertJsonPath('data.action', null)->json('data.reply');
        $this->assertStringStartsWith("It can't be sent for review yet: ", $reply);

        $this->save($id, ['employee_rdo_code' => '049', 'employee_registered_address' => self::ADDRESS, 'employee_birthdate' => '1990-05-31']);
        $this->chat($id, null, 'done')
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', 'Nothing is blocking it. Click "Submit for review" above the preview to send it for review.');
        $this->chat($id, null, 'tapos na', actions: true)
            ->assertJsonPath('data.action', ['type' => 'submit', 'draft_id' => $id])
            ->assertJsonPath('data.reply', "Sent 2316 #{$id} for review.");

        BirFormDraft::whereKey($id)->update(['status' => 'pending']);
        $this->chat($id, null, 'done', actions: true)
            ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 is already waiting for review, so there is nothing to submit.");
    }

    // ---- A 2316 by TIN

    public function test_a_2316_is_asked_for_by_tin_and_year(): void
    {
        // What a model might do with a TIN: return it as the name, or nothing at all.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2024, 'tax_month' => null, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for 920-919-920-00001 2024', reset: true)
            ->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.intent.employee_id', $this->maria->id)
            ->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2024.');

        // Digits run together, no branch code, either order.
        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => '920919920', 'kind' => 'other', 'confidence' => 'low', 'clarification' => null]);
        $this->send('pa-gawa ng 2316 2025 tin 920 919 920', reset: true)
            ->assertJsonPath('data.intent.employee_id', $this->maria->id);

        $this->model->willReturn(['form_type' => '2316', 'tax_year' => 2024, 'tax_month' => null, 'employee_query' => '555-555-555-000', 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for 555-555-555-000 2024', reset: true)
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', 'No employee has the TIN 555-555-555-000. Please check the number, or give their name or employee ID.');
    }

    // ---- helpers

    private function send(string $message, bool $reset = false, bool $actions = false): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', array_filter(['message' => $message, 'reset' => $reset ?: null, 'actions' => $actions ?: null]))
            ->assertOk();
    }

    private function chat(int $id, ?string $field, ?string $message, bool $actions = false): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', array_filter(['draft_id' => $id, 'field' => $field, 'message' => $message, 'actions' => $actions ?: null]))
            ->assertOk();
    }

    /** Saves answers through PUT, as the chat panel does with data.answer. */
    private function save(int $id, array $fields): void
    {
        if (array_key_exists('field', $fields)) {
            $fields = [$fields['field'] => $fields['value']];
        }
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => $fields])->assertOk();
    }

    private function employee(string $code, string $first, string $last, string $tin): Employee
    {
        $employee = Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower("{$first}.{$last}") . '@example.com', 'position' => 'Staff', 'hire_date' => '2020-01-01',
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => $tin,
        ]);

        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id, 'cutoff_start' => $start, 'cutoff_end' => $end,
                'base_salary' => 30000, 'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [], 'status' => 'finalized',
            ]);
        }

        return $employee;
    }

    private function draft2316(Employee $employee, string $year): int
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => $year, 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function draft1601C(string $period): int
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => $period])
            ->assertCreated()
            ->json('data.id');
    }
}
