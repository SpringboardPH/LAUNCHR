<?php

namespace Tests\Feature\Bir;

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
 * Misspellings, from the misspelling test round on the cloud model: names (with "si", "ni",
 * "pala" around them, or one name alone), months the model didn't read, command words, "itm",
 * and box names. Each is read in PHP; a month PHP guesses is shown for the user to check.
 */
class BirChatTypoTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private FakeLlmClient $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $this->model = new FakeLlmClient;
        $this->app->instance(LlmClientInterface::class, $this->model);
        foreach (['company_tin' => '987-654-321-000', 'company_name' => 'Springboard Inc.', 'employer_type' => 'main'] as $key => $value) {
            SystemSettings::set($key, $value);
        }

        $this->employee('EMP001', 'Teresa', 'Taxable', '301-111-222-00000');
        $this->employee('EMP002', 'Noel', 'Nontaxable', '302-222-333-00000');
        $this->employee('EMP003', 'Dana', 'Lopez', '303-333-444-00000');
    }

    public function test_misspelled_names_are_found_and_short_ones_must_match(): void
    {
        $resolver = new BirEmployeeResolver;
        $code = fn (string $query) => $resolver->resolve($query)['employee']?->employee_id;

        foreach (['ni si Noel', 'si Noel', 'Noel pala', 'kay Noel po', 'Noel Nontaxabl', 'noel nontaxable pala'] as $query) {
            $this->assertSame('EMP002', $code($query), $query);
        }
        $this->assertSame('EMP001', $code('Teressa'), 'one name alone, one slip');
        $this->assertSame('EMP001', $code('Taxabel'), 'a last name alone, one slip');
        $this->assertSame('EMP003', $code('Daan'), 'a 4-letter name with two letters swapped');
        $this->assertSame(BirEmployeeResolver::NOT_FOUND, $resolver->resolve('Dina')['status'], 'a 4-letter name must otherwise match');

        // In a question about pay, a capitalised name with a slip is asked about first; "yes" answers.
        $id = $this->draft1601C('2026-09');
        $this->send("how much was Teressa paid on form #{$id}?")
            ->assertJsonPath('data.reply', 'I couldn\'t find "Teressa". Did you mean Teresa Taxable (EMP001)? Say yes, or give the right name or employee ID.')
            ->assertJsonPath('data.candidates.0.employee_id', 'EMP001');
        $this->assertStringStartsWith("Teresa Taxable's part of the September 2026 1601-C (form #{$id}", $this->next('yes')->json('data.reply'));
        $this->send("magkano ang sahod ni Daan sa form #{$id}?");
        $this->assertStringStartsWith("Dana Lopez's part", $this->next('Dana Lopez')->json('data.reply'));
    }

    public function test_a_name_spelled_close_to_someone_is_asked_about_never_picked(): void
    {
        $intent = fn (?string $who) => ['form_type' => '2316', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => $who, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null];

        // A request: "Teresita Taxable" may be someone not on file.
        $this->model->willReturn($intent('Teresa Taxabel'));
        $this->send('2316 for Teresa Taxabel 2026')->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', 'I couldn\'t find "Teresa Taxabel". Did you mean Teresa Taxable (EMP001)? Say yes, or give the right name or employee ID.');
        $this->next('yes')->assertJsonPath('data.reply', 'Understood: a 2316 for Teresa Taxable (EMP001), covering 2026.');
        $this->model->willReturn($intent('Teresa Taxabel'));
        $this->send('2316 for Teresa Taxabel 2026');
        $this->next('no')->assertJsonPath('data.understood', false)->assertJsonPath('data.reply', BirEmployeeResolver::NOT_THEM);
        // An exact name, an employee ID or a TIN still goes straight to the card.
        $this->model->willReturn($intent('Teresa Taxable'));
        $this->send('2316 for Teresa Taxable 2026')->assertJsonPath('data.reply', 'Understood: a 2316 for Teresa Taxable (EMP001), covering 2026.');
        $this->model->willReturn($intent('EMP002'));
        $this->send('2316 for EMP002 2026')->assertJsonPath('data.reply', 'Understood: a 2316 for Noel Nontaxable (EMP002), covering 2026.');

        // Reading and changing a form by a near name ask too; nothing is changed until the person is confirmed.
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => Employee::where('employee_id', 'EMP001')->value('id')])->assertCreated()->json('data.id');
        $this->assertStringStartsWith('I couldn\'t find "Teresa Taxabel". Did you mean Teresa Taxable (EMP001)?', $this->send("what is item 5 on teresa taxabel's 2316 for 2026?")->json('data.reply'));
        $this->assertStringContainsString("form #{$id}", $this->next('yes')->json('data.reply'));
        $this->send("change item 5 on teresa taxabel's 2316 for 2026 to 044")->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, 'I couldn\'t find'));
        // A command word before a name isn't a second name: "open Teresa's 2316" is hers.
        $this->assertStringStartsWith("Now on form #{$id}", $this->send("open Teresa's 2316 for 2026")->json('data.reply'));
        $this->send("change item 5 on teresa taxabel's 2316 for 2026 to 044");
        // Confirmed, a change on a form that isn't open still needs its number, as always.
        $this->next('yes')->assertJsonPath('data.answer', null)->assertJsonPath('data.reply', fn (string $r) => str_contains($r, "Reply \"form #{$id}\""));
        $this->next("form #{$id}")->assertJsonPath('data.answer', ['field' => 'employee_rdo_code', 'value' => '044']);
    }

    public function test_a_misspelled_month_the_model_missed_is_guessed_for_checking(): void
    {
        $none = ['form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => null, 'employee_query' => null, 'kind' => 'form_request', 'confidence' => 'high', 'clarification' => null];

        foreach ([['1601-C for Agosot 2026', '08'], ['1601-C for Mrch 2026', '03'], ['1601-C ng Hulio 2026', '07']] as [$message, $month]) {
            $this->model->willReturn($none);
            $this->send($message)->assertJsonPath('data.reply', "Understood: a 1601-C covering 2026-{$month}. Please check this is right before creating the draft.");
        }
        // A 4-letter slip confirms the model's month without a check.
        $this->model->willReturn(['tax_month' => 3] + $none);
        $this->send('1601-C for Mrch 2026')->assertJsonPath('data.reply', 'Understood: a 1601-C covering 2026-03.');
        // Never a guess for a 2316, nor between two months.
        $this->model->willReturn(['form_type' => '2316', 'employee_query' => 'Noel Nontaxable', 'tax_month' => null] + $none);
        $this->send('2316 for Noel Nontaxable Mrch 2026')->assertJsonPath('data.reply', 'Understood: a 2316 for Noel Nontaxable (EMP002), covering 2026.');
        $this->model->willReturn($none);
        $this->send('1601-C for Junee or Julyy 2026')->assertJsonPath('data.reply', 'Could you tell me the month? I prepare one 1601-C at a time.');
    }

    public function test_slips_in_command_words_and_in_item(): void
    {
        $id = $this->draft1601C('2026-09');

        $this->assertStringStartsWith("Now on form #{$id}", $this->send("opn form #{$id}")->json('data.reply'));
        $this->send("change itm 4 on form #{$id} to 2")->assertJsonPath('data.answer', ['field' => 'sheets_attached', 'value' => 2]);
        $this->assertStringStartsWith('There is nothing to undo', $this->send('udno')->json('data.reply'));
        $this->assertSame([], $this->model->calls());
    }

    public function test_a_slip_in_a_box_name(): void
    {
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => Employee::where('employee_id', 'EMP001')->value('id')])->assertCreated()->json('data.id');

        $this->assertStringContainsString('"Item 19, Gross Compensation Income', $this->send("what is the gross compnsation on form #{$id}?")->json('data.reply'));
        $this->assertStringContainsString('Withheld', $this->send("what is the tax witheld on form #{$id}?")->json('data.reply'));
    }

    // ---- helpers

    private function send(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message, 'reset' => true])->assertOk();
    }

    /** A message in the same conversation (no reset). */
    private function next(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message])->assertOk();
    }

    private function draft1601C(string $period): int
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => $period])->assertCreated()->json('data.id');
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
}
