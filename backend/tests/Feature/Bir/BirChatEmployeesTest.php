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
 * The 1601-C employee list in the chat (BirChatEmployees, on Dev B's BirEmployeeList): who is
 * on a 1601-C and each one's pay and tax, as frozen on the draft when it was made. Read in PHP,
 * so these also check the model is never asked.
 */
class BirChatEmployeesTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    private Employee $maria;

    private Employee $jose;

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
        $this->jose = $this->employee('EMP002', 'Jose', 'Reyes', '123-456-789-00000', 20000, 1000);
    }

    public function test_the_employees_on_a_1601c_are_listed_with_totals(): void
    {
        $id = $this->draft1601C();

        $reply = $this->send('who is on the September 2026 1601-C?')->json('data.reply');
        $this->assertSame(
            "The September 2026 1601-C (form #{$id}, original, a draft) covers 2 employees:\n"
            . "- SANTOS, MARIA (920-919-920-00001): 2 payrolls, compensation ₱30,000.00, tax withheld ₱600.00\n"
            . "- REYES, JOSE (123-456-789-00000): 2 payrolls, compensation ₱40,000.00, tax withheld ₱2,000.00\n"
            . "Total: compensation ₱70,000.00, tax withheld ₱2,600.00.\n"
            . 'Their figures add up to the form.',
            $reply,
        );

        // By number, and on the open form (where the open question is asked again after).
        $this->assertSame($reply, $this->send("list the employees on form #{$id}")->json('data.reply'));
        $this->assertStringStartsWith($reply . "\n\n", $this->chat($id, 'employee list')->json('data.reply'));
        $this->assertSame([], $this->model->calls());
    }

    public function test_one_employees_part_is_given_by_name(): void
    {
        $id = $this->draft1601C();

        $this->send("how much tax was withheld from Maria Santos on form #{$id}?")
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', "Maria Santos's part of the September 2026 1601-C (form #{$id}, original, a draft), from 2 payrolls, TIN 920-919-920-00001 (taxable):\n"
                . "- Compensation (item 14): ₱30,000.00\n"
                . "- SSS, PhilHealth and Pag-IBIG (employee share) (item 19): ₱1,400.00\n"
                . "- Taxable compensation (item 22): ₱28,600.00\n"
                . '- Tax withheld (item 25): ₱600.00');

        // On the open 1601-C the person is enough.
        $this->assertStringStartsWith("Jose Reyes's part of the September 2026 1601-C", $this->chat($id, 'how much was Jose Reyes paid?')->json('data.reply'));
        $this->assertStringStartsWith("Jose Reyes's part of the September 2026 1601-C", $this->send('what is Jose Reyes\'s tax on the September 2026 1601-C?')->json('data.reply'));
    }

    public function test_someone_not_on_the_list_and_a_figure_that_differs_are_said(): void
    {
        $id = $this->draft1601C();
        $ana = Employee::create([
            'employee_id' => 'EMP003', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana.cruz@example.com',
            'position' => 'Staff', 'hire_date' => '2026-10-01', 'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly',
        ]);
        $this->send("how much was Ana Cruz paid on form #{$id}?")
            ->assertJsonPath('data.reply', "Ana Cruz has no counted payroll on the September 2026 1601-C (form #{$id}, original, a draft), so they are not on its employee list.");

        // Someone changed item 25 on the form: the list says by how much.
        $this->actingAs($this->accounting)->putJson("/api/bir/drafts/{$id}", ['fields' => ['total_taxes_withheld' => '2700.00']])->assertOk();
        $this->assertStringEndsWith(
            "Their figures don't all add up to the form (a changed figure, item 20, or rounding can explain it):\n- Item 25 on the form is ₱100.00 more than the employees' total of ₱2,600.00.",
            $this->send("employees on form #{$id}")->json('data.reply'),
        );
    }

    public function test_a_correction_uses_the_list_it_was_made_from_and_an_old_form_says_it_has_none(): void
    {
        $id = $this->draft1601C();
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/revise")->assertCreated()->json('data.id');
        $this->assertStringStartsWith("The September 2026 1601-C (form #{$id}v2 (#{$v2}), amendment 1, a draft) covers 2 employees:", $this->send("who is on form #{$id}v2?")->json('data.reply'));

        $snapshot = BirFormDraft::findOrFail($id)->source_snapshot;
        unset($snapshot['meta']['employees']);
        BirFormDraft::whereKey($id)->update(['source_snapshot' => $snapshot]);
        $this->send("who is on form #{$id}?")
            ->assertJsonPath('data.reply', "The September 2026 1601-C (form #{$id}, original, filed) was made before the employee list existed, so I don't have its employees. A new draft for that month has them.");
    }

    public function test_other_questions_are_left_alone(): void
    {
        $id = $this->draft1601C();
        $other = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $this->maria->id])->assertCreated()->json('data.id');

        // A box on the 1601-C, and a person's 2316, are still read as before.
        $this->assertStringStartsWith("On the September 2026 1601-C (form #{$id}, original, a draft), \"Item 25", $this->send("what is item 25 on form #{$id}?")->json('data.reply'));
        $this->assertStringStartsWith("On Maria Santos' 2316 for 2026 (form #{$other}", $this->send("what is item 5 on Maria Santos's 2316 for 2026?")->json('data.reply'));
        // A 2316 has no employee list.
        $this->assertStringNotContainsString('covers', (string) $this->send("who is on form #{$other}?")->json('data.reply'));
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

    private function draft1601C(): int
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated()->json('data.id');
    }

    private function employee(string $code, string $first, string $last, string $tin, int $gross, int $tax): Employee
    {
        $employee = Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower("{$first}.{$last}") . '@example.com', 'position' => 'Staff', 'hire_date' => '2020-01-01',
            'salary' => $gross * 2, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => $tin,
        ]);
        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
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
