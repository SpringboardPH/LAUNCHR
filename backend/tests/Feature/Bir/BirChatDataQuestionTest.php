<?php

namespace Tests\Feature\Bir;

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

    public function test_conditions_on_any_field_and_never_a_list_without_them(): void
    {
        // From the screenshot: a condition is applied, and an empty answer says who comes closest.
        $this->asks('who has base salary higher than 500,000', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 500000]]])
            ->assertJsonPath('data.reply', 'There is no employee with a salary on file over ₱500,000.00. The highest is Teresa Taxable (EMP001) at ₱60,000.00 a month.');
        $this->asks('who has a salary higher than 17k', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 17000]]])
            ->assertJsonPath('data.reply', "2 of 3 employees with a salary on file over ₱17,000.00:\n- Mina Minwage (EMP003): salary on file ₱18,000.00 a month\n- Teresa Taxable (EMP001): salary on file ₱60,000.00 a month");
        // A number the message doesn't give, or no condition read at all: not answered as if there were none.
        $this->asks('who has a salary higher than 17k', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 20000]]])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);
        // With no condition from the model, a plain one is read from the message; one that isn't plain is never dropped.
        $this->assertStringStartsWith('There is no employee with a salary on file over ₱500,000.00.', $this->asks('who has base salary higher than 500,000', ['topic' => 'employees'])->json('data.reply'));
        $this->asks('who earns way more than most people?', ['topic' => 'employees'])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);

        $this->asks('who earns more than Noel?', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 'Noel']]])
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, "2 of 3 employees with a salary on file over Noel Nontaxable's (₱16,000.00):"));
        // With a period and no word for the rate, "earners" is what was paid.
        $this->asks('top 1 earner in 2025', ['topic' => 'employees', 'sort_by' => 'salary', 'limit' => 1, 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Going by the 2025 payroll, top 1 of 3 employees by compensation:\n- Teresa Taxable (EMP001): compensation ₱120,000.00");
        $this->asks('what is the average salary?', ['topic' => 'amounts', 'amount' => 'salary', 'measure' => 'average'])
            ->assertJsonPath('data.reply', 'The average salary on file for all 3 employees: ₱31,333.33 (rates as on file).');
        $this->assertStringStartsWith("3 of 3 employees hired before 2021:\n- Mina Minwage (EMP003): hired January 1, 2020", $this->asks('who was hired before 2021?', ['topic' => 'employees', 'filter' => 'hired', 'filters' => [['field' => 'hire_date', 'op' => 'lt', 'value' => '2021']]])->json('data.reply'));
    }

    public function test_when_unsure_what_is_asked_it_asks_first(): void
    {
        // An amount the message has no word for is the model's guess: which amount?
        $this->asks('who is the highest?', ['topic' => 'amounts', 'measure' => 'highest', 'amount' => 'compensation'])
            ->assertJsonPath('data.reply', 'Which amount do you mean: 1. compensation (what payroll paid), 2. tax withheld, or 3. salary on file (the rate)? Say 1, 2 or 3.')
            ->assertJsonPath('data.candidates.1', ['name' => 'Tax withheld', 'employee_id' => '2']);
        $this->assertStringStartsWith('The highest tax withheld in September 2025 (the latest month with counted payroll), among all 3 employees: Teresa Taxable (EMP001) ₱6,000.00.', $this->next('2')->json('data.reply'));

        // A salary rate has no period: the rate, or what was paid in it?
        $this->asks('what is the total salary in 2025?', ['topic' => 'amounts', 'amount' => 'salary', 'measure' => 'total', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'Do you mean 1. the salary rate on file, or 2. what payroll paid in 2025 (compensation)? Say 1 or 2.');
        $this->next('what was paid')->assertJsonPath('data.reply', 'Total compensation in 2025, for all 3 employees: ₱188,000.00.');

        // "high earners": the top few, or everyone in order?
        $this->asks('who are the high earners?', ['topic' => 'employees', 'sort_by' => 'compensation', 'order' => 'desc'])
            ->assertJsonPath('data.reply', 'How should I pick them: 1. the top 3 by compensation, or 2. everyone, highest first? Say 1 or 2. Or give an amount, for example "over 30,000".');
        $this->assertStringStartsWith('Going by the September 2025 payroll (the latest month with counted payroll), top 3 of 3 employees by compensation:', $this->next('1')->json('data.reply'));

        // A group the message has no word for: never quietly all employees.
        $this->asks('who are the big shots?', ['topic' => 'employees', 'group' => 'taxable'])
            ->assertJsonPath('data.reply', 'Do you mean 1. the employees who are taxable, or 2. all employees? Say 1 or 2.');
        $this->assertStringStartsWith('There are 3 employees:', $this->next('all of them')->json('data.reply'));

        // The model unsure: how it was read, then "yes"; "no" looks nothing up.
        $this->asks('filed ones?', ['topic' => 'forms', 'status' => 'finalized', 'confidence' => 'low'])
            ->assertJsonPath('data.reply', 'Just to check: do you want the saved forms that are filed? Say yes, or ask it another way.');
        $this->next('yes')->assertJsonPath('data.reply', 'No saved forms are filed.');
        $this->asks('filed ones?', ['topic' => 'forms', 'status' => 'finalized', 'confidence' => 'low']);
        $this->next('no')->assertJsonPath('data.reply', BirDataDoubt::NOT_IT);
        $this->assertCount(6, $this->model->calls(), 'the replies to the questions are read in PHP');
    }

    public function test_text_matches_names_together_ties_and_narrowing(): void
    {
        Employee::where('employee_id', 'EMP001')->update(['department' => 'Finance']);
        Employee::whereIn('employee_id', ['EMP002', 'EMP003'])->update(['department' => 'Administration']);

        // "admin" is Administration, as "contains" or as "is".
        foreach (['contains', 'eq'] as $op) {
            $this->assertStringStartsWith('2 of 3 employees whose department ', $this->asks('which employees work in admin?', ['topic' => 'employees', 'filters' => [['field' => 'department', 'op' => $op, 'value' => 'admin']]])->json('data.reply'));
        }
        // Two people named are compared side by side; two departments together are either one.
        $this->asks('compare Teresa and Noel salary', ['topic' => 'employees', 'show' => ['salary'], 'filters' => [['field' => 'name', 'op' => 'eq', 'value' => 'Teresa'], ['field' => 'name', 'op' => 'eq', 'value' => 'Noel']]])
            ->assertJsonPath('data.reply', "Salary on file side by side:\n- Noel Nontaxable (EMP002): ₱16,000.00 a month\n- Teresa Taxable (EMP001): ₱60,000.00 a month\nTeresa Taxable's is higher, by ₱44,000.00.");
        $this->assertStringStartsWith('3 of 3 employees whose department is "Finance" or "Administration":', $this->asks('who is in Finance or Administration?', ['topic' => 'employees', 'filters' => [['field' => 'department', 'op' => 'eq', 'value' => 'Finance'], ['field' => 'department', 'op' => 'eq', 'value' => 'Administration']]])->json('data.reply'));
        $this->asks('whose name starts with T?', ['topic' => 'employees', 'filters' => [['field' => 'name', 'op' => 'starts', 'value' => 'T']]])
            ->assertJsonPath('data.reply', "1 of 3 employees whose name starts with \"T\":\n- Teresa Taxable (EMP001): Staff");
        $this->asks('whose name starts with T?', ['topic' => 'employees', 'filters' => [['field' => 'name', 'op' => 'contains', 'value' => 'T']]])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);

        // "who got 13th month?": more than nothing; with no condition read, not everyone.
        $this->asks('who got 13th month in September 2025?', ['topic' => 'employees', 'tax_year' => 2025, 'tax_month' => 9, 'filters' => [['field' => 'thirteenth_month', 'op' => 'gt', 'value' => 0]]])
            ->assertJsonPath('data.reply', 'Going by the September 2025 payroll, there is no employee with 13th month and other benefits over ₱0.00.');
        $this->asks('who got 13th month in September 2025?', ['topic' => 'employees', 'tax_year' => 2025, 'tax_month' => 9])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);

        // Ties at the top are named together.
        $this->asks('who had the lowest tax withheld in 2025?', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'measure' => 'lowest', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'The lowest tax withheld in 2025, among all 3 employees: Noel Nontaxable (EMP002), Mina Minwage (EMP003), ₱0.00 each. Next: Teresa Taxable (EMP001) ₱12,000.00.');
        $this->assertStringStartsWith('The most recently hired of the 3 employees (3, tied):', $this->asks('who is the newest employee?', ['topic' => 'employees', 'sort_by' => 'hire_date', 'order' => 'desc'])->json('data.reply'));

        // A slip in a company word, and an employee ID that is not a TIN.
        $this->asks('wats the company adress', ['topic' => 'company', 'detail' => 'address'])->assertJsonPath('data.reply', "The company's registered address isn't set yet. An admin sets it in the BIR settings.");
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => Employee::where('employee_id', 'EMP003')->value('id')])->assertCreated();
        $this->asks("what's Mina's employee ID", ['topic' => 'employee', 'detail' => 'employee_id', 'employee' => 'Mina'])->assertJsonPath('data.reply', 'The employee ID on file for Mina Minwage (EMP003) is EMP003.');

        // "only the taxable ones" narrows the question before, keeping its condition.
        $this->asks('who has a salary over 15k?', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 15000]]]);
        $this->model->willReturn($this->intent(['topic' => 'employees', 'group' => 'taxable', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 15000]]]));
        $this->assertStringStartsWith('Going by the September 2025 payroll (the latest month with counted payroll), 1 of 3 employees who are taxable and with a salary on file over ₱15,000.00:', $this->next('only the taxable ones')->json('data.reply'));
    }

    public function test_ranges_places_several_groups_and_what_the_records_dont_hold(): void
    {
        // Several months: a range, or a quarter.
        $this->asks('total tax withheld from August to September 2025', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'measure' => 'total', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'Total tax withheld in August to September 2025, for all 3 employees: ₱12,000.00.');
        $this->assertStringStartsWith('Going by the July to September 2025 payroll, 1 of 3 employees is taxable:', $this->asks('who were taxable in Q3 2025?', ['topic' => 'employees', 'group' => 'taxable', 'tax_year' => 2025])->json('data.reply'));

        // A place in the order, sorting everyone, and the newest in Filipino.
        $this->asks('who earns the second most?', ['topic' => 'employees', 'sort_by' => 'salary', 'order' => 'desc'])
            ->assertJsonPath('data.reply', 'The second highest salary on file of the 3 employees: Mina Minwage (EMP003) ₱18,000.00 a month.');
        $this->assertStringStartsWith("3 of 3 employees:\n- Teresa Taxable (EMP001): salary on file ₱60,000.00 a month", $this->asks('list employees by salary highest to lowest', ['topic' => 'employees', 'sort_by' => 'salary', 'order' => 'desc'])->json('data.reply'));
        $this->assertStringStartsWith('The most recently hired of the 3 employees (3, tied):', $this->asks('sino ang pinakabagong empleyado', ['topic' => 'employees', 'sort_by' => 'hire_date', 'order' => 'desc'])->json('data.reply'));

        // Two groups in one question: each answered.
        $both = $this->asks('how many minimum wage earners and how many taxable?', ['topic' => 'employees', 'measure' => 'count', 'group' => 'minimum_wage'])->json('data.reply');
        $this->assertStringContainsString('1 of 3 employees is a minimum wage earner', $both);
        $this->assertStringContainsString('1 of 3 employees is taxable', $both);
        // Something the records don't measure: never another amount instead.
        $this->asks('who has the most overtime hours?', ['topic' => 'amounts', 'measure' => 'highest', 'amount' => 'compensation'])->assertJsonPath('data.reply', BirDataAnswers::CANT);

        // "is Noel taxable in 2025?" is a yes or a no, even when the model leaves the group out.
        $this->assertStringStartsWith('No. Going by the 2025 payroll', $this->asks('is Noel taxable in 2025?', ['topic' => 'employee', 'employee' => 'Noel', 'tax_year' => 2025])->json('data.reply'));
        // "she" on her 2316 is her, not everyone, even when the model was unsure.
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => Employee::where('employee_id', 'EMP001')->value('id')])->assertCreated()->json('data.id');
        // Sorted twice: by the box lookup (no box fits), then by the draft turn.
        $sorted = ['kind' => 'data_question', 'field' => null, 'question' => ['topic' => 'amounts', 'amount' => 'compensation', 'tax_year' => 2025, 'confidence' => 'low']];
        $this->model->willReturn($sorted)->willReturn($sorted);
        $this->assertStringStartsWith('Compensation for Teresa Taxable (EMP001) in 2025: ₱120,000.00.', $this->chat($id, 'how much did they give her in 2025?')->json('data.reply'));
        // With no one in view, "her" asks who.
        $this->asks('how much did they give her in 2025?', ['topic' => 'amounts', 'amount' => 'compensation', 'tax_year' => 2025])->assertJsonPath('data.reply', 'Which employee do you mean? Give their name or employee ID.');

        // "total?" after "what about their tax?": the tax.
        $this->asks('who were taxable in September 2025?', ['topic' => 'employees', 'group' => 'taxable', 'tax_year' => 2025, 'tax_month' => 9]);
        $this->model->willReturn($this->intent(['show' => ['tax_withheld']]));
        $this->next('what about their tax?');
        $this->model->willReturn($this->intent(['measure' => 'total']));
        $this->next('total?')->assertJsonPath('data.reply', 'Going by the September 2025 payroll, the total tax withheld for the 1 employee who is taxable: ₱6,000.00.');
    }

    public function test_comparisons_months_and_what_isnt_a_list(): void
    {
        Employee::where('employee_id', 'EMP002')->update(['hire_date' => '2019-05-01', 'status' => 'inactive', 'position' => 'Developer']);

        // Two people named: side by side, with which comes first, whatever the model labelled.
        $this->asks("who's been with us longer, Teresa or Noel?", ['topic' => 'unsupported'])
            ->assertJsonPath('data.reply', "Hire date side by side:\n- Noel Nontaxable (EMP002): hired May 1, 2019\n- Teresa Taxable (EMP001): hired January 1, 2020\nNoel Nontaxable has been here longer.");
        $this->asks("compare Teresa's 2025 tax with Noel's", ['topic' => 'amounts', 'amount' => 'tax_withheld', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Going by the 2025 payroll: Tax withheld side by side:\n- Noel Nontaxable (EMP002): ₱0.00\n- Teresa Taxable (EMP001): ₱12,000.00\nTeresa Taxable's is higher, by ₱12,000.00.");

        // Month by month, and the month at the top.
        $this->asks('total tax withheld per month in 2025', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'measure' => 'total', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', "Tax withheld month by month in 2025:\n- August: ₱6,000.00\n- September: ₱6,000.00\nTotal: ₱12,000.00.");
        $this->asks('which month in 2025 had the most compensation?', ['topic' => 'amounts', 'amount' => 'compensation', 'measure' => 'highest', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'The month of 2025 with the highest total compensation is August and September: ₱94,000.00 each.');

        // A group with an employment status keeps both; a plural position is the job.
        $this->assertStringStartsWith('Going by the September 2025 payroll (the latest month with counted payroll), there is no employee who is taxable and who is not active.', $this->asks("who's taxable among the inactive employees?", ['topic' => 'employees', 'group' => 'taxable', 'filter' => 'inactive'])->json('data.reply'));
        $this->assertStringStartsWith('1 of 3 employees whose position is "developers":', $this->asks('which employees are developers?', ['topic' => 'employees', 'filters' => [['field' => 'position', 'op' => 'eq', 'value' => 'developers']]])->json('data.reply'));

        // Not something to list: "on leave" isn't kept, "number one" names no amount.
        $this->asks('who is on leave today?', ['topic' => 'employees', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'on leave']]])->assertJsonPath('data.reply', BirDataAnswers::CANT);
        $this->assertStringStartsWith('Which amount do you mean:', $this->asks("who's number one?", ['topic' => 'employees'])->json('data.reply'));
    }

    public function test_hire_order_details_for_everyone_and_months_over_an_amount(): void
    {
        Employee::where('employee_id', 'EMP002')->update(['hire_date' => '2019-05-01']);
        Employee::where('employee_id', 'EMP003')->update(['hire_date' => '2021-03-01']);

        // Compared with a person's hire date; that person is not the subject.
        $this->asks('who was hired after Noel?', ['topic' => 'employee', 'employee' => 'Noel', 'filters' => [['field' => 'hire_date', 'op' => 'gt', 'value' => 'Noel']]])
            ->assertJsonPath('data.reply', fn (string $r) => str_starts_with($r, '2 of 3 employees hired after Noel Nontaxable (May 1, 2019):'));
        $this->asks('who was hired after Noel?', ['topic' => 'employee', 'employee' => 'Noel'])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);
        // "second newest", read as about one employee with no one named.
        $this->asks("who's the second newest employee?", ['topic' => 'employee', 'detail' => 'name', 'sort_by' => 'hire_date', 'order' => 'desc'])
            ->assertJsonPath('data.reply', 'The second most recently hired of the 3 employees: Teresa Taxable (EMP001) January 1, 2020.');
        // Details for everyone, read from the message when the model gives up.
        $this->assertStringStartsWith("3 of 3 employees:\n- Mina Minwage (EMP003): Staff, hired March 1, 2021", $this->asks("show everyone's position and hire date", ['topic' => 'unsupported'])->json('data.reply'));
        // Months over an amount.
        $this->asks('which months in 2025 had more than 5k tax withheld?', ['topic' => 'amounts', 'amount' => 'tax_withheld', 'per' => 'month', 'tax_year' => 2025, 'filters' => [['field' => 'tax_withheld', 'op' => 'gt', 'value' => 5000]]])
            ->assertJsonPath('data.reply', "Months of 2025 with tax withheld over ₱5,000.00 (2):\n- August: ₱6,000.00\n- September: ₱6,000.00");
        // An amount named but no condition on one: not everyone.
        $this->asks('who got 13th month in December 2025?', ['topic' => 'employees', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]])->assertJsonPath('data.reply', BirDataAnswers::UNREAD);
        // "yes" to numbered choices picks none: asked again.
        $this->asks("who's the richest?", ['topic' => 'amounts', 'measure' => 'highest']);
        $this->next('yes')->assertJsonPath('data.reply', 'Which one? Say 1, 2 or 3.');
        $this->assertStringStartsWith('The highest salary on file among all 3 employees: Teresa Taxable (EMP001)', $this->next('3')->json('data.reply'));
    }

    public function test_averages_per_month_counts_by_department_every_month_and_who_else(): void
    {
        Employee::where('employee_id', 'EMP001')->update(['department' => 'Finance', 'hire_date' => '2024-02-01']);
        Employee::whereIn('employee_id', ['EMP002', 'EMP003'])->update(['department' => 'Administration']);

        // One person's average a month, over the months with payroll.
        $this->asks('average monthly compensation of Teresa in 2025', ['topic' => 'amounts', 'amount' => 'compensation', 'measure' => 'average', 'employee' => 'Teresa', 'tax_year' => 2025])
            ->assertJsonPath('data.reply', 'Average monthly compensation for Teresa Taxable (EMP001) in 2025: ₱60,000.00 (over 2 months with payroll).');
        // "hired in 2024" is the hire date's year, not the payroll's.
        $this->assertStringStartsWith('Going by the September 2025 payroll (the latest month with counted payroll), 1 of 3 employees who are taxable and hired in 2024:', $this->asks('taxable employees hired in 2024', ['topic' => 'employees', 'group' => 'taxable', 'filter' => 'hired', 'tax_year' => 2024])->json('data.reply'));
        // Counted by department, with nothing to ask about.
        $this->asks('which department has the most employees?', ['topic' => 'amounts', 'measure' => 'highest'])->assertJsonPath('data.reply', 'The department with the most employees: Administration (2).');
        // In every month, not in total.
        $this->assertStringStartsWith('Going by the 2025 payroll, 1 of 3 employees who had tax withheld in every month of 2025 with payroll:', $this->asks('who had tax withheld every month of 2025?', ['topic' => 'employees', 'filter' => 'tax_withheld', 'tax_year' => 2025])->json('data.reply'));
        // Details for everyone when the model read one employee with no one named.
        $this->assertStringStartsWith("3 of 3 employees:\n- Mina Minwage (EMP003): Administration, no TIN on file", $this->asks('show TIN and department of everyone', ['topic' => 'employee', 'detail' => 'tin'])->json('data.reply'));

        // On her 2316: a month of her pay, and "who else" leaves her out.
        $id = $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => Employee::where('employee_id', 'EMP001')->value('id')])->assertCreated()->json('data.id');
        $this->model->willReturn(['kind' => 'data_question', 'field' => null, 'question' => ['topic' => 'amounts', 'amount' => 'tax_withheld', 'tax_year' => 2025, 'tax_month' => 9]]);
        $this->assertStringStartsWith('Tax withheld for Teresa Taxable (EMP001) in September 2025: ₱6,000.00.', $this->chat($id, 'how much tax was withheld from her in September 2025?')->json('data.reply'));
        $this->model->willReturn(['kind' => 'data_question', 'field' => null, 'question' => ['topic' => 'employees', 'filters' => [['field' => 'department', 'op' => 'contains', 'value' => 'Finance']]]]);
        $this->assertStringStartsWith('No one else: of the employees whose department contains "Finance", there is only Teresa Taxable (EMP001).', $this->chat($id, 'who else works in Finance?')->json('data.reply'));
    }

    public function test_combined_average_one_persons_details_and_lists_of_forms(): void
    {
        Employee::where('employee_id', 'EMP003')->update(['position' => 'Driver']);

        // Added up, against the average, two details, and a yes or no about one person.
        $this->assertStringStartsWith('Combined salary on file of Noel Nontaxable (EMP002) and Teresa Taxable (EMP001): ₱76,000.00 a month.', $this->asks('combined salary of Teresa and Noel', ['topic' => 'amounts', 'amount' => 'salary', 'measure' => 'total'])->json('data.reply'));
        $this->assertStringStartsWith('2 of 3 employees with a salary on file under the average (₱31,333.33):', $this->asks('whose salary is below the average?', ['topic' => 'employees', 'filters' => [['field' => 'salary', 'op' => 'lt', 'value' => 'average']]])->json('data.reply'));
        $this->asks("what is Mina's salary and position?", ['topic' => 'employee', 'detail' => 'salary', 'employee' => 'Mina'])->assertJsonPath('data.reply', 'Mina Minwage (EMP003): salary on file ₱18,000.00 a month, Driver.');
        $this->asks("is Noel's salary more than 15k?", ['topic' => 'employees', 'filters' => [['field' => 'name', 'op' => 'eq', 'value' => 'Noel'], ['field' => 'salary', 'op' => 'gt', 'value' => 15000]]])
            ->assertJsonPath('data.reply', 'Yes. Noel Nontaxable (EMP002): salary on file ₱16,000.00 a month.');
        $this->asks("is Mina's salary over 20k?", ['topic' => 'employee', 'employee' => 'Mina', 'detail' => 'salary', 'filters' => [['field' => 'salary', 'op' => 'gt', 'value' => 20000]]])
            ->assertJsonPath('data.reply', 'No. Mina Minwage (EMP003): salary on file ₱18,000.00 a month.');

        // "hired" with no year said adds no condition.
        $this->assertStringStartsWith('The most recently hired of the 3 employees (3, tied):', $this->asks('sino ang pinakabagong hire', ['topic' => 'employees', 'filter' => 'hired', 'sort_by' => 'hire_date', 'order' => 'desc'])->json('data.reply'));
        // "only those …" narrows the question before.
        $this->asks('who are the taxable employees?', ['topic' => 'employees', 'group' => 'taxable']);
        $this->model->willReturn($this->intent(['topic' => 'employees', 'filters' => [['field' => 'hire_date', 'op' => 'gt', 'value' => '2019']]]));
        $this->assertStringContainsString('who are taxable and hired after 2019', $this->next('only those hired after 2019')->json('data.reply'));

        // Many forms with their status: a list, never "whose 2316?"; "did we file" is filed.
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2025', 'employee_id' => Employee::where('employee_id', 'EMP001')->value('id')])->assertCreated();
        $this->assertStringStartsWith('1 saved form (2316):', $this->asks('list all 2316s with their status', ['topic' => 'forms'])->json('data.reply'));
        $this->asks('how many forms did we file in 2025?', ['topic' => 'forms', 'measure' => 'count', 'tax_year' => 2025])->assertJsonPath('data.reply', 'No saved forms (for 2025) are filed.');
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

    public function test_what_is_not_a_question_about_the_records(): void
    {
        // "Mina Minwage" answering "which employee?" for a 2316 is the answer, whatever the label.
        $this->model->willReturn(['kind' => 'form_request', 'form_type' => '2316', 'tax_year' => 2025, 'tax_month' => null, 'employee_query' => null, 'confidence' => 'high', 'clarification' => null]);
        $this->send('2316 for 2025')->assertJsonPath('data.reply', 'Could you tell me which employee?');
        $this->model->willReturn($this->intent(['topic' => 'employee', 'employee' => 'Mina Minwage']));
        $this->next('Mina Minwage')->assertJsonPath('data.reply', 'Understood: a 2316 for Mina Minwage (EMP003), covering 2025.');

        // A month with no 1601-C saved: the payroll answers, not "there is no 1601-C".
        $this->assertStringStartsWith('Going by the August 2025 payroll, 1 of 3 employees is taxable:', $this->asks('list the taxable employees for August 2025', ['topic' => 'employees', 'group' => 'taxable', 'tax_year' => 2025, 'tax_month' => 8])->json('data.reply'));
        // "her" with no one in view asks who; a role these records don't hold can't be answered.
        $this->asks('what is her TIN?', ['topic' => 'employee', 'detail' => 'tin'])->assertJsonPath('data.reply', 'Which employee do you mean? Give their name or employee ID.');
        $this->asks('who is our CEO?', ['topic' => 'employee', 'detail' => 'position'])->assertJsonPath('data.reply', BirDataAnswers::CANT);
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
