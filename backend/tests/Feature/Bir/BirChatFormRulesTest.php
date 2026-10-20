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
 * The workflow's rules, as the chat follows them (Dev B owns the rules; PUT enforces them):
 *   - a form waiting for review or approved can be changed by accounting only; anyone else
 *     is told so and asked nothing, instead of being handed an answer PUT would refuse (403);
 *   - an approved form that is changed goes back to draft, to be approved again;
 *   - a fixed box (1601-C item 13 No, item 13A blank) and a system-filled one (item 2) are
 *     never asked and never changed: the chat says why (PUT would say 422);
 *   - a form goes for review only when the user asks for it directly ("submit it for review",
 *     "pakisubmit"); "done" leaves it a draft (supervisor's rule).
 */
class BirChatFormRulesTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private const ADDRESS = '12 Mabini St., Brgy. San Roque, Quezon City';

    private User $accounting;

    private User $hr;

    private Employee $maria;

    protected function setUp(): void
    {
        parent::setUp();
        // Their 2026 forms are for a year that is over, so the chat doesn't first ask item 2 "To" (BirChatPeriodToTest).
        \Carbon\Carbon::setTestNow('2027-01-15 09:00:00');

        $this->accounting = User::factory()->create(['role' => 'accounting', 'name' => 'Carlo Reyes']);
        $this->hr = User::factory()->create(['role' => 'hr']);
        $this->app->instance(LlmClientInterface::class, new FakeLlmClient);

        foreach ([
            'company_tin' => '987-654-321-000', 'company_name' => 'Springboard Inc.',
            'company_address' => '1 Ayala Avenue, Brgy. San Lorenzo, Makati City', 'company_zip' => '1226', 'employer_type' => 'main',
        ] as $key => $value) {
            SystemSettings::set($key, $value);
        }

        $this->maria = Employee::create([
            'employee_id' => 'EMP001', 'first_name' => 'Maria', 'last_name' => 'Santos',
            'email' => 'maria.santos@example.com', 'position' => 'Staff', 'hire_date' => '2020-01-01',
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => '920-919-920-00001',
        ]);
        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $this->maria->id, 'cutoff_start' => $start, 'cutoff_end' => $end,
                'base_salary' => 30000, 'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [], 'status' => 'finalized',
            ]);
        }
    }

    // ---- Who may change which form

    public function test_whether_a_form_can_be_changed_follows_its_status_and_role(): void
    {
        $id = $this->draft2316();

        // A draft: anyone who reaches the chat.
        $this->send("change item 5 on form #{$id} to 049", as: $this->hr)->assertJsonPath('data.answer.value', '049');

        foreach (['pending' => 'waiting for review', 'approved' => 'approved'] as $status => $word) {
            BirFormDraft::whereKey($id)->update(['status' => $status]);
            $this->send("change item 5 on form #{$id} to 050", as: $this->hr)
                ->assertJsonPath('data.answer', null)
                ->assertJsonPath('data.reply', "Maria Santos' 2316 for 2026 (form #{$id}) is {$word}, and only accounting can change it now. You can still ask what any box holds.");
            $this->send("change item 5 on form #{$id} to 050")->assertJsonPath('data.answer.value', '050');
        }

        // Reading is still fine for HR.
        $this->send("what is item 5 on form #{$id}?", as: $this->hr)->assertJsonPath('data.reply', fn (string $r) => str_contains($r, 'RDO Code'));
    }

    public function test_hr_is_asked_nothing_on_a_form_under_review_or_approved(): void
    {
        $id = $this->draft2316();
        $this->chat($id, null, null, as: $this->hr)->assertJsonPath('data.question.field', 'employee_rdo_code');

        foreach (['pending' => 'waiting for review', 'approved' => 'approved'] as $status => $word) {
            BirFormDraft::whereKey($id)->update(['status' => $status]);
            $this->chat($id, null, null, as: $this->hr)
                ->assertJsonPath('data.question', null)
                ->assertJsonPath('data.answer', null)
                ->assertJsonPath('data.reply', "Form #{$id} is {$word}, and only accounting can change it now. You can still ask what a box means or where its figure came from.");
            $this->chat($id, 'employee_rdo_code', '049', as: $this->hr)->assertJsonPath('data.answer', null);

            // Accounting is still asked, and its answer goes through.
            $this->chat($id, null, null)->assertJsonPath('data.question.field', 'employee_rdo_code');
        }

        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);
        $this->chat($id, null, null)
            ->assertJsonPath('data.question', null)
            ->assertJsonPath('data.reply', "Form #{$id} is filed, so it can't be changed here. You can still ask what a box means or where its figure came from.");
    }

    public function test_hr_cannot_undo_on_a_form_that_was_approved_since(): void
    {
        $id = $this->draft2316();
        $answer = $this->send("change item 5 on form #{$id} to 049", reset: true, as: $this->hr)->json('data.answer');
        $this->save($id, $answer);
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);

        $this->send('undo', as: $this->hr)
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Form #{$id} is approved now, so the change to \"Item 5, RDO Code\" can't be undone here.");
    }

    public function test_an_approved_form_that_is_changed_goes_back_to_draft(): void
    {
        $id = $this->draft2316();
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);

        $answer = $this->send("change item 5 on form #{$id} to 049", reset: true)
            ->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => '049'])
            ->assertJsonPath('data.reply', "On Maria Santos' 2316 for 2026 (form #{$id}, original, approved): Changed \"Item 5, RDO Code\" to 049. It was approved, so this sends it back to a draft: submit it for review again to have it approved. Say \"undo\" to put it back.")
            ->json('data.answer');
        $this->save($id, $answer);
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    // ---- Boxes that are never changed

    public function test_fixed_and_system_filled_boxes_are_refused_with_the_reason(): void
    {
        $id = $this->draft1601C();

        $this->send("change item 13 on form #{$id} to yes", reset: true)
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"Item 13, Are there payees availing of tax relief under Special Law or International Tax Treaty?" is always No on this form, so it can\'t be changed.');
        $this->send("change item 13A on form #{$id} to tax treaty")
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"Item 13A, If yes, specify" is always blank on this form, so it can\'t be changed.');
        $this->send("change item 2 on form #{$id} to yes")
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"Item 2, Amended Return?" is filled in automatically: No on the original, Yes on an amended return. It can\'t be changed.');

        // The same on the open form.
        $this->chat($id, null, 'change item 2 to yes')->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, '"Item 2, Amended Return?" is filled in automatically'));
    }

    public function test_fixed_and_system_filled_boxes_are_never_asked_even_on_an_old_draft(): void
    {
        $id = $this->draft1601C();
        // A draft made before items 2 and 13A were filled in by the system: all three left empty.
        $draft = BirFormDraft::findOrFail($id);
        $fields = $draft->fields;
        foreach (['is_amended', 'has_tax_relief', 'tax_relief_details'] as $key) {
            $fields[$key] = ['value' => null, 'origin' => 'pending', 'edited' => false];
        }
        $draft->update(['fields' => $fields]);

        $kept = ['is_amended', 'has_tax_relief', 'tax_relief_details'];
        $first = $this->chat($id, null, null)->json('data.question.field');
        $this->assertNotNull($first);
        $this->assertNotContains($first, $kept);

        // Every other box answered: nothing is left to ask, though the three are still empty.
        foreach ($fields as $key => $entry) {
            if (!in_array($key, $kept, true) && ($entry['value'] ?? null) === null) {
                $fields[$key] = ['value' => 'X', 'origin' => 'user', 'edited' => false];
            }
        }
        $draft->update(['fields' => $fields]);
        $this->chat($id, null, null)->assertJsonPath('data.question', null);
    }

    // ---- Sending for review: only when asked directly

    public function test_done_leaves_the_form_a_draft_and_is_never_saved_as_an_answer(): void
    {
        $id = $this->draft2316();
        $this->chat($id, null, null)->assertJsonPath('data.question.field', 'employee_rdo_code');

        foreach (['done', 'tapos na', "I'm done", 'finished'] as $said) {
            $this->chat($id, 'employee_rdo_code', $said, actions: true)
                ->assertJsonPath('data.action', null)
                ->assertJsonPath('data.answer', null)
                ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "Okay. Form #{$id} stays a draft until you send it for review. When it is ready, say \"submit it for review\" or click \"Submit for review\"."));
        }
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_direct_request_sends_the_form_for_review_once_nothing_blocks_it(): void
    {
        $id = $this->draft2316();
        $name = "Form #{$id} (Maria Santos' 2316 for 2026)";

        $reply = $this->chat($id, null, 'submit it for review', actions: true)->assertJsonPath('data.action', null)->json('data.reply');
        $this->assertStringStartsWith("{$name} can't be sent for review yet: ", $reply);
        $this->assertStringContainsString('It stays a draft.', $reply);

        $this->save($id, ['employee_rdo_code' => '049', 'employee_registered_address' => self::ADDRESS, 'employee_birthdate' => '1990-05-31']);
        $this->chat($id, null, 'submit it for review')
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', "Nothing is blocking {$name}. Click \"Submit for review\" above the preview to send it for review.");

        // Any wording that asks for it directly, English or Filipino.
        foreach (['submit it for review', 'Please submit this form.', 'send this to accounting for approval', 'can you submit it?',
            'pakisubmit', 'i-submit mo na po', 'ipasa mo na for review', 'resubmit it'] as $said) {
            $this->chat($id, null, $said, actions: true)
                ->assertJsonPath('data.action', ['type' => 'submit', 'draft_id' => $id])
                ->assertJsonPath('data.reply', "Sent form #{$id} for review.");
        }

        BirFormDraft::whereKey($id)->update(['status' => 'pending']);
        $this->chat($id, null, 'submit it for review', actions: true)
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', "{$name} is already waiting for review, so there is nothing to submit.");
    }

    public function test_questions_and_not_yet_never_send_a_form_for_review(): void
    {
        $id = $this->draft2316();
        $this->save($id, ['employee_rdo_code' => '049', 'employee_registered_address' => self::ADDRESS, 'employee_birthdate' => '1990-05-31']);

        foreach (['how do I submit this?', "don't submit it yet", 'not yet, hold off on submitting', 'has it been submitted?',
            'is it ready for review?', 'show me the submitted forms', 'huwag mo munang i-submit'] as $said) {
            $this->chat($id, null, $said, actions: true)->assertJsonPath('data.action', null);
        }
        $this->assertSame('draft', BirFormDraft::findOrFail($id)->status);
    }

    public function test_a_form_that_is_not_open_is_sent_for_review_by_its_number(): void
    {
        $id = $this->draft2316();
        $this->save($id, ['employee_rdo_code' => '049', 'employee_registered_address' => self::ADDRESS, 'employee_birthdate' => '1990-05-31']);

        $this->send("submit form #{$id} for review", reset: true, actions: true)
            ->assertJsonPath('data.action', ['type' => 'submit', 'draft_id' => $id])
            ->assertJsonPath('data.reply', "Sent form #{$id} for review.");
        $this->send('submit it for review', actions: true)
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.reply', 'Which form should I send for review? Open it, or give its number, for example "submit form #11 for review".');
    }

    // ---- Tax advice

    public function test_tax_advice_is_declined_before_anything_is_looked_up(): void
    {
        $id = $this->draft2316();
        $decline = \App\Services\BIR\BirExplanationService::DECLINE;

        // With no form open, and on the open form: never read as a request for a box or a form.
        foreach (['how much tax should Maria Santos pay this year?', 'should we withhold more tax from Maria?',
            'compute her tax', 'magkano ang babayaran ni Maria na tax?', 'how do I lower our taxes?'] as $asked) {
            $this->send($asked, reset: true)->assertJsonPath('data.reply', $decline);
            $this->chat($id, null, $asked)->assertJsonPath('data.answer', null)
                ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, $decline));
        }

        // Reading what a form holds is still a lookup, not advice.
        $this->assertStringStartsWith("On Maria Santos' 2316 for 2026 (form #{$id}", $this->send("how much tax was withheld from Maria Santos on form #{$id}?", reset: true)->json('data.reply'));
        $this->assertStringStartsWith("On Maria Santos' 2316 for 2026 (form #{$id}", $this->send("what is item 5 on form #{$id}?", reset: true)->json('data.reply'));
    }

    // ---- helpers

    private function send(string $message, bool $reset = false, ?User $as = null, bool $actions = false): TestResponse
    {
        return $this->actingAs($as ?? $this->accounting)
            ->postJson('/api/bir/chat', array_filter(['message' => $message, 'reset' => $reset ?: null, 'actions' => $actions ?: null]))
            ->assertOk();
    }

    private function chat(int $id, ?string $field, ?string $message, bool $actions = false, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->accounting)
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

    private function draft2316(): int
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $this->maria->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function draft1601C(): int
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }
}
