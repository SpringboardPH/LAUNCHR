<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirDataAnswers;
use App\Services\BIR\BirDataDoubt;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\BirExplanationService;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * More questions about the records (BirChatDataQuestionTest has the first ones), from the later
 * rounds of live testing: conditions the model leaves out, several statuses, the pay basis, and
 * questions it labels as tax advice that the records answer.
 */
class BirChatDataQuestionMoreTest extends TestCase
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

    public function test_conditions_read_from_the_message_and_several_statuses(): void
    {
        // The pay basis, when the model gives no condition for it.
        $this->assertStringStartsWith('3 of 3 employees whose pay basis is "monthly":', $this->asks('how many employees are monthly paid?', ['topic' => 'employees', 'measure' => 'count'])->json('data.reply'));
        // A threshold on monthly totals, read from the message; and "75k" written by the model.
        $this->asks('which months in 2025 had more than 90k compensation?', ['topic' => 'amounts', 'amount' => 'compensation', 'per' => 'month', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Months of 2025 with compensation over ₱90,000.00 (2):\n- August: ₱94,000.00\n- September: ₱94,000.00");
        $this->asks('which months in 2025 had less than 75k compensation?', ['topic' => 'amounts', 'amount' => 'compensation', 'per' => 'month', 'tax_year' => 2025, 'filters' => [['field' => 'compensation', 'op' => 'lt', 'value' => '75k']]])
            ->assertJsonPath('data.reply', 'No month of 2025 had compensation under ₱75,000.00.');

        // Two statuses at once.
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2025-09'])->assertCreated();
        $this->assertStringStartsWith('1 saved form is approved or a draft:', $this->asks('list the forms that are drafts or approved', ['topic' => 'forms', 'status' => 'draft'])->json('data.reply'));
    }

    public function test_a_figure_from_the_records_labelled_tax_advice_is_answered(): void
    {
        // "the average tax of everyone": the model says tax_question and gives no question; the message's words answer it.
        $this->model->willReturn(['kind' => 'tax_question'] + $this->intent([]));
        $this->send('what is the average tax withheld of everyone in September 2025?')
            ->assertJsonPath('data.reply', 'Going by the September 2025 payroll, the average tax withheld for all 3 employees: ₱2,000.00.');
        // Advice stays advice.
        $this->model->willReturn(['kind' => 'tax_question'] + $this->intent([]));
        $this->send('what is the average tax rate I should apply?')->assertJsonPath('data.reply', BirExplanationService::DECLINE);
    }

    public function test_median_company_contacts_and_one_person_month_by_month(): void
    {
        // A median the model can't label: the message's words. Salaries on file are 60k, 16k and 18k.
        $this->asks("what's the median salary", ['topic' => 'unsupported'])
            ->assertJsonPath('data.reply', 'The median salary on file for all 3 employees: ₱18,000.00 (rates as on file).');
        SystemSettings::set('company_email', 'payroll@example.com');
        $this->asks("what's the company email", ['topic' => 'company', 'detail' => 'email'])
            ->assertJsonPath('data.reply', "The company's email address is payroll@example.com.");
        // "each month" for one employee: theirs, month by month; never everyone's.
        $this->asks('how much did Teresa make each month in 2025', ['topic' => 'amounts', 'amount' => 'compensation', 'employee' => 'Teresa', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Compensation for Teresa Taxable (EMP001) month by month in 2025:\n- August: ₱60,000.00\n- September: ₱60,000.00\nTotal: ₱120,000.00.");
    }

    public function test_hire_year_place_of_work_and_form_counts(): void
    {
        Employee::where('employee_id', 'EMP001')->update(['hire_date' => '2024-03-01', 'department' => 'Finance']);
        Employee::where('employee_id', 'EMP002')->update(['hire_date' => '2024-06-01']);
        // "in 2024" bounds the hire date, though the model gave only ">= 2024-01-01".
        $this->asks('who joined most recently in 2024', ['topic' => 'employees', 'sort_by' => 'hire_date', 'order' => 'desc', 'filters' => [['field' => 'hire_date', 'op' => 'gte', 'value' => '2024-01-01']]])
            ->assertJsonPath('data.reply', "The most recently hired of the 2 employees hired in 2024:\n- Noel Nontaxable (EMP002): hired June 1, 2024");
        // A yes or no about where someone works, labelled unsupported.
        $this->asks('is Teresa in Administration', ['topic' => 'unsupported'])
            ->assertJsonPath('data.reply', 'No. Teresa Taxable (EMP001) is in Finance, not Administration.');
        // "the staff" read as a department: the position, since no department is so.
        $this->asks('total compensation of the staff in September 2025', ['topic' => 'amounts', 'measure' => 'total', 'amount' => 'compensation', 'tax_year' => 2025, 'tax_month' => 9, 'filters' => [['field' => 'department', 'op' => 'eq', 'value' => 'staff']]])
            ->assertJsonPath('data.reply', 'Going by the September 2025 payroll, the total compensation for the 3 employees whose position is "staff": ₱94,000.00.');

        // Counting forms is a question about the records, never a box on one of them.
        foreach (['2025-08', '2025-09'] as $period) {
            $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => $period])->assertCreated()->json('data.id');
        }
        $this->assertStringStartsWith('2 saved forms (1601-C) are still drafts', $this->asks('how many 1601-Cs are drafts in total', ['topic' => 'forms', 'measure' => 'count', 'status' => 'draft'])->json('data.reply'));
        $this->assertStringEndsWith('No one has reviewed it yet.', $this->send("who reviewed form #{$id}")->json('data.reply'));
    }

    public function test_a_follow_up_on_those_keeps_the_condition_and_asks_nothing(): void
    {
        $this->asks('who has a salary over 17k', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 17000]]]);
        // "of those" narrows the list just given; the year is the group's, so nothing is asked.
        $this->model->willReturn($this->intent(['topic' => 'employees', 'group' => 'exempt_250k', 'tax_year' => 2025]));
        $reply = $this->next('which of those are exempt in 2025')->json('data.reply');
        $this->assertStringStartsWith('Going by the 2025 payroll, 1 of 3 employees who are exempt (₱250,000 a year or less) and with a salary on file over ₱17,000.00:', $reply);
        $this->assertStringContainsString('Teresa Taxable', $reply);
        $this->assertStringNotContainsString('Mina', $reply);
    }

    public function test_records_over_forms_follow_up_why_and_dates(): void
    {
        $teresa = Employee::where('employee_id', 'EMP001')->value('id');
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => $teresa])->assertCreated();
        // Her salary is the rate on file, not a box on her 2316, when no form is named or open.
        $this->asks('salary of Teresa?', ['topic' => 'employee', 'employee' => 'Teresa', 'detail' => 'salary'])
            ->assertJsonPath('data.reply', 'The salary on file for Teresa Taxable (EMP001) is ₱60,000.00 a month.');
        // A bonus labelled tax advice: her 13th month and other benefits.
        $this->model->willReturn(['kind' => 'tax_question'] + $this->intent([]));
        $this->assertStringStartsWith('13th month and other benefits for Teresa Taxable (EMP001) in 2025:', $this->send("what's Teresa's bonus in 2025")->json('data.reply'));
        // An average per month is one figure, not the months listed.
        $this->asks('average compensation per month of Teresa in 2025', ['topic' => 'amounts', 'amount' => 'compensation', 'employee' => 'Teresa', 'tax_year' => 2025, 'per' => 'month'])
            ->assertJsonPath('data.reply', 'Average monthly compensation for Teresa Taxable (EMP001) in 2025: ₱60,000.00 (over 2 months with payroll).');

        // "why" after a yes or no: the reason, never "tax advice".
        $this->asks('is Noel taxable in 2025', ['topic' => 'employee', 'employee' => 'Noel', 'group' => 'taxable', 'tax_year' => 2025]);
        $this->model->willReturn(['kind' => 'tax_question'] + $this->intent([]));
        $this->assertStringStartsWith('Going by the 2025 payroll, Noel Nontaxable is exempt', $this->next('why')->json('data.reply'));

        // "not in sales" with sales a position: not "sales figures".
        Employee::where('employee_id', 'EMP002')->update(['position' => 'Sales Associate', 'hire_date' => '2024-06-01']);
        $this->assertStringStartsWith('2 of 3 employees whose position isn\'t "sales":', $this->asks('employees not in sales', ['topic' => 'employees', 'filters' => [['field' => 'department', 'op' => 'neq', 'value' => 'sales']]])->json('data.reply'));
        // "got hired last" is about hiring, not an amount got.
        $this->asks('who got hired last', ['topic' => 'amounts', 'measure' => 'highest'])
            ->assertJsonPath('data.reply', "The most recently hired of the 3 employees:\n- Noel Nontaxable (EMP002): hired June 1, 2024");

        // "when was it submitted?": the day, from the form's log.
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2025-09'])->assertCreated()->json('data.id');
        BirFormDraft::find($id)->update(['status' => 'pending']);
        $this->assertStringContainsString('It was submitted for review on ', $this->send("when was form #{$id} submitted")->json('data.reply'));
    }

    public function test_a_box_of_a_named_employees_2316_and_which_year(): void
    {
        $teresa = Employee::where('employee_id', 'EMP001')->value('id');
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => $teresa])->assertCreated();
        // No form named: her 2316's boxes; "13th month" is both its boxes, the non-taxable part and the taxable excess.
        $reply = $this->send('whats the 13th month pay of Teresa')->json('data.reply');
        $this->assertStringStartsWith("On Teresa Taxable's 2316 for 2025", $reply);
        $this->assertStringContainsString('"Item 34, 13th Month Pay', $reply);
        $this->assertStringContainsString('"Item 48, Taxable 13th Month', $reply);
        // A box named, not asked as a question; and "her" never picks her TIN over the box named.
        $this->assertStringContainsString('"Item 19, Gross Compensation', $this->send("Teresa's gross compensation on her 2316")->json('data.reply'));
        $this->assertStringContainsString('"Item 34, 13th Month Pay', $this->send("what is Teresa's 13th month on her 2316")->json('data.reply'));
        // A month named is her payroll, not her 2316; net pay is not a figure the records keep.
        $this->asks("Teresa's compensation in September 2025", ['topic' => 'amounts', 'amount' => 'compensation', 'employee' => 'Teresa', 'tax_year' => 2025, 'tax_month' => 9])
            ->assertJsonPath('data.reply', 'Compensation for Teresa Taxable (EMP001) in September 2025: ₱60,000.00.');
        $this->asks("what's Teresa's net pay in September 2025", ['topic' => 'amounts', 'amount' => 'compensation', 'employee' => 'Teresa', 'tax_year' => 2025, 'tax_month' => 9])
            ->assertJsonPath('data.reply', BirDataAnswers::CANT);
        // Her salary is still the rate on file.
        $this->asks('salary of Teresa', ['topic' => 'employee', 'employee' => 'Teresa', 'detail' => 'salary'])
            ->assertJsonPath('data.reply', 'The salary on file for Teresa Taxable (EMP001) is ₱60,000.00 a month.');

        // A 2316 for two years: which year, and "2025" answers it.
        Payroll::create(['employee_id' => $teresa, 'cutoff_start' => '2026-01-01', 'cutoff_end' => '2026-01-15', 'base_salary' => 60000, 'gross_pay' => 30000,
            'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 3000], 'allowances' => [], 'status' => 'finalized']);
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $teresa])->assertCreated();
        $this->assertStringStartsWith('Teresa Taxable has 2316s for 2025 and 2026. Which year do you mean?', $this->send('what is item 28 of Teresa')->json('data.reply'));
        $this->assertStringStartsWith("On Teresa Taxable's 2316 for 2025", $this->next('2025')->json('data.reply'));
    }

    public function test_whose_tin_names_the_employee_not_a_box(): void
    {
        // Teresa has a 2316, whose TIN boxes (items 3, 12, 16) once made "whose TIN" ask which box.
        $teresa = Employee::where('employee_id', 'EMP001')->value('id');
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => $teresa])->assertCreated();
        $this->send('who has TIN 301-111-222-00000')->assertJsonPath('data.reply', 'TIN 301-111-222-00000 belongs to Teresa Taxable (EMP001).');
        $this->send('whose TIN is 301111222')->assertJsonPath('data.reply', 'TIN 301111222 belongs to Teresa Taxable (EMP001).');
        $this->send('which employee has the tin 302-222-333-00000?')->assertJsonPath('data.reply', 'TIN 302-222-333-00000 belongs to Noel Nontaxable (EMP002).');
        $this->send('kanino ang TIN na 302 222 333')->assertJsonPath('data.reply', 'TIN 302 222 333 belongs to Noel Nontaxable (EMP002).');
        $this->send('who has TIN 555-555-555-000')->assertJsonPath('data.reply', 'No employee has the TIN 555-555-555-000. Please check the number.');
        $this->assertSame([], $this->model->calls(), 'answered without the model');
        // Asking for her TIN's box is still a box of her 2316.
        $this->assertStringContainsString('"Item 3, TIN" is 301-111-222-00000', $this->send("what's item 3 on Teresa's 2316")->json('data.reply'));
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
