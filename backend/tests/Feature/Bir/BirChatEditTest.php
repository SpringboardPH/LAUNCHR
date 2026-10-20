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
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * Findings from user-side testing, one test each:
 *   - "2316 for maria santos" took this year; it now asks for the year.
 *   - "july" after "a 1601-C for 2026" got the model's own wrong question; the model now
 *     never words a reply.
 *   - "change TIN to …" was saved as the address being asked; a change is never an answer.
 *   - Boxes can be changed through the chat by item number, with safeguards.
 *   - "in form 2316 what is #12 the TIN" was declined; any box of either form is explained,
 *     with or without a draft.
 * Change requests and item questions are read in PHP before the model is asked, so these
 * tests also check the model isn't called for them.
 */
class BirChatEditTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    protected function setUp(): void
    {
        parent::setUp();
        // Their 2026 forms are for a year that is over, so the chat doesn't first ask item 2 "To" (BirChatPeriodToTest).
        \Carbon\Carbon::setTestNow('2027-01-15 09:00:00');

        $this->accounting = User::factory()->create(['role' => 'accounting', 'name' => 'Carlo Reyes']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);

        foreach ([
            'company_tin' => '987-654-321-000',
            'company_name' => 'Springboard Inc.',
            'company_address' => '1 Ayala Avenue, Brgy. San Lorenzo, Makati City',
            'company_zip' => '1226',
            'employer_type' => 'main',
        ] as $key => $value) {
            SystemSettings::set($key, $value);
        }
    }

    public function test_a_year_the_user_did_not_give_is_asked_not_assumed(): void
    {
        $this->model->willReturn($this->request(['form_type' => '2316', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => 'maria santos']));

        $this->send('2316 for maria santos', reset: true)
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', 'Could you tell me the year?');
    }

    public function test_the_model_never_words_a_reply_when_it_is_unsure(): void
    {
        $this->model->willReturn($this->request(['tax_month' => null]));
        $this->send('I need a 1601-C for 2026', reset: true)
            ->assertJsonPath('data.reply', 'Could you tell me the month? I prepare one 1601-C at a time.');

        // What Ollama did: a form already known, then its own question about which form.
        $this->model->willReturn($this->request([
            'form_type' => null, 'tax_year' => null, 'tax_month' => 7,
            'confidence' => 'low', 'clarification' => 'Please specify if you need a 1601-C or 2316 form for July 2026.',
        ]));
        $this->send('july')
            ->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.intent.period', '2026-07')
            ->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-07. Please check this is right before creating the draft.');
    }

    public function test_the_summary_names_the_employee_found_not_as_typed(): void
    {
        $this->employee('EMP001', 'Maria', 'Santos', '2021-03-01');
        $this->model->willReturn($this->request(['form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => 'maria santos']));

        $this->send('2316 for maria santos for 2025', reset: true)
            ->assertJsonPath('data.intent.employee_name', 'Maria Santos (EMP001)')
            ->assertJsonPath('data.reply', 'Understood: a 2316 for Maria Santos (EMP001), covering 2025.');
    }

    public function test_a_change_command_is_never_saved_as_the_answer_being_asked(): void
    {
        $id = $this->draft2316();
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['employee_rdo_code' => '049']])->assertOk();
        $this->model->willReturn(['kind' => 'answer', 'field' => null]);

        // "TIN" names three boxes: the chat asks which, and the item number finishes the change.
        $reply = $this->chat($id, 'employee_registered_address', 'change TIN to 987-654-321-676')
            ->assertJsonPath('data.answer', null)
            ->json('data.reply');
        $this->assertStringStartsWith("Which one do you mean? On the 2316 that could be:\n- item 3: the employee's Taxpayer Identification Number\n- item 12: the company's TIN", $reply);
        $this->chat($id, null, 'item 12')
            ->assertJsonPath('data.answer', ['field' => 'present_employer_tin', 'value' => '987-654-321-00676']);

        // Afterwards the open question is asked again, and an answer to it is still read as one.
        $this->chat($id, null, null)->assertJsonPath('data.question.field', 'employee_registered_address');
        $this->assertSame([], $this->model->calls(), 'Read in PHP: the model is not asked.');
    }

    public function test_a_box_is_changed_by_its_item_number_and_saved_like_the_change_button(): void
    {
        $id = $this->draft2316();

        $reply = $this->chat($id, 'employee_rdo_code', 'change item 12 to 987-654-321-676')
            ->assertJsonPath('data.answer', ['field' => 'present_employer_tin', 'value' => '987-654-321-00676'])
            ->json('data.reply');
        $this->assertSame('Changed "Item 12, TIN" from 987-654-321-00000 to 987-654-321-00676. This replaces the calculated figure, and the change is recorded with your name. Say "undo" to put it back.', $reply);

        // The caller saves it through PUT, which records who changed it and what it replaced.
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['present_employer_tin' => '987-654-321-00676']])->assertOk();
        $entry = BirFormDraft::findOrFail($id)->fields['present_employer_tin'];
        $this->assertTrue($entry['edited']);
        $this->assertSame('987-654-321-00000', $entry['system_value']);

        $this->chat($id, 'employee_rdo_code', 'palitan ang item 5 ng 049')
            ->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => '049'])
            ->assertJsonPath('data.reply', 'Changed "Item 5, RDO Code" to 049. Say "undo" to put it back.');

        $this->chat($id, null, 'gawing 21a ang item 5')
            ->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => '21A']);

        $tax = BirFormDraft::findOrFail($id)->fields['taxes_withheld_present']['value'];
        $this->chat($id, null, 'change item 25A to 2,900')
            ->assertJsonPath('data.answer', ['field' => 'taxes_withheld_present', 'value' => '2900.00'])
            ->assertJsonPath('data.reply', 'Changed "Item 25A, Amount of Taxes Withheld — Present Employer" from ₱' . number_format((float) $tax, 2) . ' to ₱2,900.00. This replaces the calculated figure, and the change is recorded with your name. Say "undo" to put it back.');

        $this->assertSame([], $this->model->calls());
    }

    public function test_a_change_is_refused_rather_than_guessed(): void
    {
        $id = $this->draft2316();
        $open = '"Item 5, RDO Code" is needed for this form. What is it? Write it as a 3-character RDO code like 043 or 21A.';

        $cases = [
            'change item 12' => 'What should "Item 12, TIN" be? For example: "change item 12 to …".',
            'change item 12 to oo' => '"TIN" must be a 14-digit TIN like 123-456-789-00000 (9 digits and a 5-digit branch code). Nothing was changed.',
            'change item 4 to Cruz' => 'Item 4 has more than one part: Employee\'s Name — Last Name, Employee\'s Name — First Name, Employee\'s Name — Middle Name. Which one? For example: "change item 4 middle name to …".',
            'change item 99 to 5' => 'The 2316 has no item 99 that can be changed here.',
            'change item 55 to Juan' => 'The 2316 has no item 55 that can be changed here.',
        ];
        foreach ($cases as $message => $reply) {
            $this->chat($id, 'employee_rdo_code', $message)
                ->assertJsonPath('data.answer', null)
                ->assertJsonPath('data.reply', "{$reply}\n\n{$open}");
        }

        $this->chat($id, 'employee_rdo_code', 'change item 4 middle name to Cruz')
            ->assertJsonPath('data.answer', ['field' => 'employee_middle_name', 'value' => 'Cruz']);
    }

    public function test_a_locked_form_or_no_form_is_never_changed(): void
    {
        $id = $this->draft2316();
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);

        $this->chat($id, null, 'change item 5 to 049')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Bir Tester's 2316 for 2026 is filed, so it can't be changed. To fix it, open it and click \"Start a correction\".");

        $this->send('change TIN to 987-654-321-676', reset: true)
            ->assertJsonPath('data.reply', BirExplanationService::OPEN_A_FORM);
    }

    public function test_any_box_of_either_form_is_explained_without_a_draft(): void
    {
        $this->model->willReturn($this->request(['kind' => 'tax_question'])); // what Ollama said; never asked
        $help = fn (string $schema, string $key) => $schema::byKey()[$key]['guidance'];

        $this->send('in form 2316 what is #12 the TIN', reset: true)
            ->assertJsonPath('data.reply', '"Item 12, TIN": ' . $help(Form2316Schema::class, 'present_employer_tin'));
        $this->send('what is item 14 on the 1601-C?')
            ->assertJsonPath('data.reply', '"Item 14, Total Amount of Compensation": ' . $help(Form1601CSchema::class, 'total_compensation'));
        $this->send('what is item 12?')
            ->assertJsonPath('data.reply', BirExplanationService::WHICH_FORM);
        $this->send('what is item 99 on the 2316?')
            ->assertJsonPath('data.reply', 'The 2316 has no item 99.');
        $this->send('where did item 25A on the 2316 come from?')
            ->assertJsonPath('data.reply', '"Item 25A, Amount of Taxes Withheld — Present Employer": ' . $help(Form2316Schema::class, 'taxes_withheld_present') . "\n\n" . BirExplanationService::SOURCE_NEEDS_DRAFT);

        $parts = $this->send('what is item 4 on the 2316?')->json('data.reply');
        $this->assertSame(3, substr_count($parts, '"Item 4, Employee\'s Name'), 'Every part of item 4.');

        $this->assertSame([], $this->model->calls(), 'Read in PHP: the model is not asked.');
    }

    public function test_items_are_explained_on_a_draft_including_the_other_form(): void
    {
        $id = $this->draft2316();
        $open = '"Item 5, RDO Code" is needed for this form. What is it? Write it as a 3-character RDO code like 043 or 21A.';

        $this->chat($id, 'employee_rdo_code', 'what is item 14 on the 1601-C?')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"Item 14, Total Amount of Compensation": ' . Form1601CSchema::byKey()['total_compensation']['guidance'] . "\n\n{$open}");

        $reply = $this->chat($id, 'employee_rdo_code', 'where did item 25A come from?')->json('data.reply');
        $this->assertStringContainsString('It comes from payroll: 2 finalized payroll records for Bir Tester', $reply);
        $this->assertStringEndsWith($open, $reply);

        $this->assertSame([], $this->model->calls());
    }

    public function test_questions_say_how_to_write_a_formatted_answer_and_values_end_with_one_full_stop(): void
    {
        $id = $this->draft2316();

        $this->chat($id, null, null)
            ->assertJsonPath('data.reply', '"Item 5, RDO Code" is needed for this form. What is it? Write it as a 3-character RDO code like 043 or 21A.');
        $this->chat($id, 'employee_rdo_code', 'AAAA')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', '"RDO Code" must be a 3-character RDO code like 043 or 21A.');
        $this->chat($id, 'employee_rdo_code', '049')->assertJsonPath('data.answer.value', '049');

        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['employee_rdo_code' => '049']])->assertOk();
        $this->chat($id, 'employee_registered_address', '12 Mabini St., Brgy. San Roque, Quezon City.')
            ->assertJsonPath('data.reply', 'Got it: Registered Address: 12 MABINI ST., BRGY. SAN ROQUE, QUEZON CITY.');
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => 8, 'employee_query' => null,
            'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null,
        ], $overrides);
    }

    private function send(string $message, bool $reset = false): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', array_filter(['message' => $message, 'reset' => $reset ?: null]))
            ->assertOk();
    }

    private function chat(int $id, ?string $field, ?string $message): TestResponse
    {
        return $this->actingAs($this->accounting)
            ->postJson('/api/bir/chat', array_filter(['draft_id' => $id, 'field' => $field, 'message' => $message]))
            ->assertOk();
    }

    private function employee(string $code, string $first, string $last, string $hired): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first) . '@example.com', 'position' => 'Staff', 'hire_date' => $hired,
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => '123-456-789-000',
        ]);
    }

    private function draft2316(): int
    {
        $employee = $this->employee('EMP-EDIT', 'Bir', 'Tester', '2025-03-01');

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
