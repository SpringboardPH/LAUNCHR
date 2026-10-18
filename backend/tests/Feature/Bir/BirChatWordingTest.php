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
 * name or a year.
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

        foreach (['₱2,600.00', '₱ 2600', 'PHP 2,600.00', 'P2600', '2,600.00'] as $amount) {
            $this->send("change item 25 on form #{$id} to {$amount}")
                ->assertJsonPath('data.answer', ['field' => 'total_taxes_withheld', 'value' => '2600.00']);
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

    // ---- helpers

    private function send(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message, 'reset' => true])->assertOk();
    }

    private function chat(int $id, string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $id, 'message' => $message])->assertOk();
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
