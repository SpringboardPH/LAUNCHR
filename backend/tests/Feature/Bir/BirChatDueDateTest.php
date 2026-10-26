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
 * A form's due date in the chat (BirChatDueDate): asked, changed, put back, and shown on the
 * form for the BIR Forms to-do. Read and changed in PHP, so the model is never asked.
 */
class BirChatDueDateTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        \Carbon\Carbon::setTestNow('2026-10-05 09:00:00');

        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);
        SystemSettings::set('company_tin', '987-654-321-000');

        $employee = Employee::create([
            'employee_id' => 'EMP001', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com',
            'position' => 'Staff', 'hire_date' => '2020-01-01', 'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly',
        ]);
        Payroll::create([
            'employee_id' => $employee->id, 'cutoff_start' => '2026-09-01', 'cutoff_end' => '2026-09-30', 'base_salary' => 30000, 'gross_pay' => 30000,
            'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300], 'allowances' => [], 'status' => 'finalized',
        ]);
        $this->id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated()->json('data.id');
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_forms_due_date_is_asked_changed_and_put_back(): void
    {
        $form = "Form #{$this->id} (the September 2026 1601-C)";
        $this->send("when is form #{$this->id} due?")->assertJsonPath('data.reply', "{$form} is due on October 10, 2026. That is in 5 days.");

        $this->send("change the due date of form #{$this->id} to October 20, 2026")
            ->assertJsonPath('data.reply', "{$form} is now due on October 20, 2026 (it was October 10, 2026). Say \"reset the due date of form #{$this->id}\" to go back to the usual one.");
        $this->assertSame('2026-10-20', BirFormDraft::find($this->id)->due_date->toDateString());
        $this->actingAs($this->accounting)->getJson("/api/bir/drafts/{$this->id}")->assertJsonPath('data.due_date', '2026-10-20');
        $this->send("what's the deadline of form #{$this->id}")
            ->assertJsonPath('data.reply', "{$form} is due on October 20, 2026. That is in 15 days. This date was set by hand; the usual one is October 10, 2026.");

        // Described, not numbered: "September 2026" is the form, the date after "to" its new due date.
        $this->send('move the deadline of the September 2026 1601-C to 10/15/2026')->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "{$form} is now due on October 15, 2026"));
        // With the form open, no number and no year: the year of the date it replaces.
        $this->chat('set the due date to Oct 25')->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "{$form} is now due on October 25, 2026"));

        $this->send("change the due date of form #{$this->id} to September 15, 2026")
            ->assertJsonPath('data.reply', 'A form can only be due after its period ends (September 30, 2026). Please give a later date.');
        $this->send("reset the due date of form #{$this->id}")->assertJsonPath('data.reply', "{$form} is back to its usual due date, October 10, 2026.");
        $this->assertNull(BirFormDraft::find($this->id)->due_date);
        $this->assertSame([], $this->model->calls(), 'answered without the model');
    }

    public function test_a_filed_form_keeps_its_due_date_and_the_rule_is_given_without_a_form(): void
    {
        BirFormDraft::find($this->id)->update(['status' => 'finalized']);
        $this->send("change the due date of form #{$this->id} to October 20, 2026")
            ->assertJsonPath('data.reply', "Form #{$this->id} (the September 2026 1601-C) is already filed, so its due date stays October 10, 2026.");

        $this->assertStringStartsWith('A 1601-C is due on the 10th of the month after the one it covers', $this->send("what's the deadline for filing the 1601-C?")->json('data.reply'));
        $this->assertStringStartsWith("Which form's due date?", $this->send('change the due date to October 20, 2026')->json('data.reply'));
        // A box with "due" in its name is still the box.
        $this->assertStringContainsString('Item 31, Tax Still Due', $this->send("what is the tax still due on form #{$this->id}")->json('data.reply'));
    }

    private function send(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message, 'reset' => true])->assertOk();
    }

    private function chat(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $this->id, 'message' => $message])->assertOk();
    }
}
