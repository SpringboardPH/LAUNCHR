<?php

namespace Tests\Feature\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirDataAnswers;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\BirExplanationService;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * Questions about the records, from the user-side test document ("who are the taxable
 * employees?" was declined as tax advice): the model labels a data_question and fills in its
 * parts; PHP checks them against the message and answers from payroll, employees and forms.
 */
class BirChatDataQuestionTest extends TestCase
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
        SystemSettings::set('company_tin', '987-654-321-000');

        // August and September 2025: Teresa over P250,000 a year, Noel under it, Mina on the MWE list.
        $this->employee('EMP001', 'Teresa', 'Taxable', 30000, 3000, '301-111-222-00000');
        $this->employee('EMP002', 'Noel', 'Nontaxable', 8000, 0, '302-222-333-00000');
        $mina = $this->employee('EMP003', 'Mina', 'Minwage', 9000, 0, null);
        SystemSettings::set('bir_mwe_employee_ids', [$mina->id], null, 'json');
    }

    public function test_who_is_taxable_goes_by_the_latest_month_or_the_period_asked(): void
    {
        $this->asks('who are the taxable employees', ['topic' => 'employees', 'group' => 'taxable'])
            ->assertJsonPath('data.understood', false)
            ->assertJsonPath('data.reply', "Going by the September 2025 payroll (the latest month with counted payroll), 1 of 3 employees is taxable:\n- Teresa Taxable (EMP001): compensation ₱60,000.00, tax withheld ₱6,000.00");
        $this->asks('sino ang hindi taxable sa August 2025', ['topic' => 'employees', 'group' => 'not_taxable', 'tax_year' => 2025, 'tax_month' => 8])
            ->assertJsonPath('data.reply', "Going by the August 2025 payroll, 2 of 3 employees are not taxable (minimum wage earners and those at ₱250,000 a year or less):\n- Noel Nontaxable (EMP002): compensation ₱16,000.00, tax withheld ₱0.00\n- Mina Minwage (EMP003): compensation ₱18,000.00, tax withheld ₱0.00");
        // A year goes by the year's pay, as the 2316 does.
        $this->assertStringStartsWith('Going by the 2025 payroll, 1 of 3 employees is a minimum wage earner:', $this->asks('who were minimum wage earners in 2025?', ['topic' => 'employees', 'group' => 'minimum_wage', 'tax_year' => 2025])->json('data.reply'));
        // The model's group needs a word for it: "total tax withheld" names no group.
        $this->asks('total tax withheld in 2025', ['topic' => 'amounts', 'group' => 'taxable', 'amount' => 'tax_withheld', 'measure' => 'total', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'Total tax withheld in 2025, for all 3 employees: ₱12,000.00.');
        // A year or a person the message doesn't give is dropped.
        $this->assertStringStartsWith('Going by the September 2025 payroll (the latest month', $this->asks('who are the taxable employees', ['topic' => 'employees', 'group' => 'taxable', 'tax_year' => 2024, 'employee' => 'Maria'])->json('data.reply'));
    }

    public function test_one_employee_their_group_and_why_and_their_record(): void
    {
        $this->asks('is Teresa taxable?', ['topic' => 'employee', 'group' => 'taxable', 'employee' => 'Teresa'])
            ->assertJsonPath('data.reply', 'Yes. Going by the September 2025 payroll (the latest month with counted payroll), Teresa Taxable is taxable: their taxable compensation for the month, ₱58,600.00, comes to ₱703,200.00 a year, which is over ₱250,000. Tax withheld: ₱6,000.00.');
        $this->assertStringStartsWith('No. Going by', $this->asks('is Noel taxable?', ['topic' => 'employee', 'group' => 'taxable', 'employee' => 'Noel'])->json('data.reply'));
        $this->assertStringEndsWith('Noel Nontaxable is exempt (₱250,000 a year or less): their taxable compensation for the month, ₱14,600.00, comes to ₱175,200.00 a year, which is ₱250,000 or less. Tax withheld: ₱0.00.', $this->asks('why is Noel not taxable?', ['topic' => 'employee', 'group' => 'not_taxable', 'employee' => 'Noel'])->json('data.reply'));
        $this->asks('is Mina a minimum wage earner?', ['topic' => 'employee', 'group' => 'minimum_wage', 'employee' => 'Mina'])
            ->assertJsonPath('data.reply', fn (string $r) => str_contains($r, 'they are on the minimum wage earner list in the BIR settings'));

        $this->asks("what is Teresa's TIN", ['topic' => 'employee', 'detail' => 'tin', 'employee' => 'Teresa'])->assertJsonPath('data.reply', 'The TIN on file for Teresa Taxable (EMP001) is 301-111-222-00000.');
        $this->asks('when was Noel hired', ['topic' => 'employee', 'detail' => 'hire_date', 'employee' => 'Noel'])->assertJsonPath('data.reply', 'Noel Nontaxable (EMP002) was hired on January 1, 2020.');
        $this->asks('which employees have no TIN?', ['topic' => 'employees', 'filter' => 'no_tin'])->assertJsonPath('data.reply', "1 of 3 employees has no TIN on file:\n- Mina Minwage (EMP003), Staff");
        $this->asks('what is our company TIN?', ['topic' => 'company', 'detail' => 'tin'])->assertJsonPath('data.reply', "The company's TIN is 987-654-321-00000.");
        // A detail the message has no word for is not the answer: "who is our CEO?" isn't the company's name.
        $this->asks('who is our CEO?', ['topic' => 'company', 'detail' => 'name'])->assertJsonPath('data.reply', BirDataAnswers::CANT);
    }

    public function test_totals_highest_and_one_persons_amount(): void
    {
        $this->asks('who had the highest tax withheld in 2025?', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'measure' => 'highest', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'The highest tax withheld in 2025, among all 3 employees: Teresa Taxable (EMP001) ₱12,000.00. Next: Noel Nontaxable (EMP002) ₱0.00, Mina Minwage (EMP003) ₱0.00.');
        $this->asks('how much did we pay Mina in 2025?', ['topic' => 'amounts', 'amount' => 'compensation', 'employee' => 'Mina', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'Compensation for Mina Minwage (EMP003) in 2025: ₱36,000.00.');
        $this->asks('how much tax was withheld from Teresa in 2024?', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'employee' => 'Teresa', 'tax_year' => 2024])
            ->assertJsonPath('data.reply', 'Teresa Taxable (EMP001) has no counted payroll in 2024. Their latest is in September 2025.');
    }

    public function test_saved_forms_and_forms_not_made_yet(): void
    {
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2025-09'])->assertCreated()->json('data.id');

        // "filed" is the finalized status, said by the model either way.
        $this->asks('which forms are filed?', ['topic' => 'forms', 'status' => 'filed'])->assertJsonPath('data.reply', 'No saved forms are filed.');
        $this->asks('which forms are still drafts?', ['topic' => 'forms', 'status' => 'draft'])
            ->assertJsonPath('data.reply', "1 saved form is still a draft:\n- form #{$id}: the September 2025 1601-C, original, a draft");
        $this->asks('what still needs to be done for 2025?', ['topic' => 'missing_forms', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Months of 2025 with counted payroll but no 1601-C yet (1): August.\n\n3 of 3 employees paid in 2025 have no 2316 for it yet:\n- Mina Minwage (EMP003)\n- Noel Nontaxable (EMP002)\n- Teresa Taxable (EMP001)\n\nSaved for 2025 but not filed yet (1):\n- form #{$id}: the September 2025 1601-C, original, a draft");
    }

    public function test_a_close_name_is_asked_about_then_yes_or_no(): void
    {
        $question = ['topic' => 'employee', 'group' => 'taxable', 'employee' => 'Teressa'];
        $this->asks('is Teressa taxable?', $question)
            ->assertJsonPath('data.reply', 'I couldn\'t find "Teressa". Did you mean Teresa Taxable (EMP001)? Say yes, or give the right name or employee ID.')
            ->assertJsonPath('data.candidates.0.employee_id', 'EMP001');
        $this->assertStringStartsWith('Yes. Going by the September 2025 payroll', $this->next('yes')->json('data.reply'));

        $this->asks('is Teressa taxable?', $question);
        $this->next('no')->assertJsonPath('data.reply', BirEmployeeResolver::NOT_THEM);
        $this->assertStringStartsWith('No. Going by', $this->next('Noel Nontaxable')->json('data.reply'));
        $this->assertCount(2, $this->model->calls(), 'yes, no and the name are read in PHP');
    }

    public function test_a_follow_up_changes_only_what_it_says(): void
    {
        $this->asks('who were taxable in September 2025?', ['topic' => 'employees', 'group' => 'taxable', 'tax_year' => 2025, 'tax_month' => 9]);
        // The model gives only the month; the rest is the question before.
        $this->model->willReturn($this->intent(['tax_month' => 8]));
        $this->assertStringStartsWith('Going by the August 2025 payroll, 1 of 3 employees is taxable:', $this->next('and in August?')->json('data.reply'));
        // A year alone is the whole year, by the 2316's test: two months' pay is under P250,000 for 2025.
        $this->model->willReturn($this->intent(['tax_year' => 2025]));
        $this->next('and for 2025?')->assertJsonPath('data.reply', 'Going by the 2025 payroll, none of 3 employees are taxable.');
        $this->model->willReturn($this->intent(['group' => 'minimum_wage']));
        $this->assertStringStartsWith('Going by the 2025 payroll, 1 of 3 employees is a minimum wage earner:', $this->next('how about the minimum wage earners?')->json('data.reply'));
        // Labelled a request, a follow-up that names no form is still the question.
        $this->model->willReturn(['kind' => 'form_request', 'form_type' => null, 'tax_year' => 2025, 'tax_month' => 8, 'employee_query' => null, 'confidence' => 'low', 'clarification' => null]);
        $this->assertStringStartsWith('Going by the August 2025 payroll, 1 of 3 employees is a minimum wage earner:', $this->next('how about August 2025?')->json('data.reply'));
        $this->assertStringContainsString('data_question: {"topic":"employees"', $this->model->calls()[4]['system']);
    }

    public function test_tax_advice_is_still_declined_and_with_the_model_down_plain_questions_work(): void
    {
        $this->model->willReturn(['kind' => 'tax_question'] + $this->intent([]));
        $this->send('what tax rate applies to 300k?')->assertJsonPath('data.reply', BirExplanationService::DECLINE);

        $this->model->willFail();
        $this->send('who are the minimum wage earners?')->assertJsonPath('data.reply', fn (string $r) => str_contains($r, '1 of 3 employees is a minimum wage earner'));
        $this->model->willFail();
        $this->assertStringStartsWith('There are 3 employees:', $this->send('list all employees')->json('data.reply'));
    }

    public function test_a_question_about_the_records_with_a_form_open(): void
    {
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2025-08'])->assertCreated()->json('data.id');

        // The open form's own list answers "which employees are taxable?", in PHP.
        $this->chat($id, 'which employees are taxable?')
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, 'The August 2025 1601-C (form #') && str_contains($r, "covers 3 employees; 1 is taxable:\n- TAXABLE, TERESA"));
        // Anything else goes by the form's period.
        $this->model->willReturn(['kind' => 'data_question', 'field' => null, 'question' => ['topic' => 'amounts', 'amount' => 'tax_withheld', 'measure' => 'total']]);
        $this->assertStringStartsWith("Total tax withheld in August 2025 (this form's period), for all 3 employees: ₱6,000.00.", $this->chat($id, 'what is the total tax withheld?')->json('data.reply'));
    }

    // ---- helpers

    /** A message the model labels a data_question with these parts. */
    private function asks(string $message, array $question): TestResponse
    {
        $this->model->willReturn($this->intent($question));

        return $this->send($message);
    }

    private function intent(array $question): array
    {
        return ['kind' => 'data_question', 'form_type' => null, 'tax_year' => null, 'tax_month' => null, 'employee_query' => null, 'confidence' => 'high', 'clarification' => null, 'question' => $question];
    }

    private function send(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message, 'reset' => true])->assertOk();
    }

    /** A message in the same conversation (no reset). */
    private function next(string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['message' => $message])->assertOk();
    }

    private function chat(int $id, string $message): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/chat', ['draft_id' => $id, 'message' => $message])->assertOk();
    }

    /** Two cutoffs a month in August and September 2025, $gross and $tax each. */
    private function employee(string $code, string $first, string $last, float $gross, float $tax, ?string $tin): Employee
    {
        $employee = Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower("{$first}.{$last}") . '@example.com', 'position' => 'Staff', 'hire_date' => '2020-01-01',
            'salary' => $gross * 2, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => $tin,
        ]);
        foreach ([['2025-08-01', '2025-08-15'], ['2025-08-16', '2025-08-31'], ['2025-09-01', '2025-09-15'], ['2025-09-16', '2025-09-30']] as [$start, $end]) {
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
