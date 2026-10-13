<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirEmployeeList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The 1601-C employee list. A new 1601-C freezes each listed employee's name and TIN on
 * source_snapshot.people beside Al's _meta rows, so a later rename or TIN change doesn't alter it.
 * GET /bir/drafts/{id}/employees (contract §6) serves that frozen list, its totals, and the items
 * where the totals differ from the form; a revision uses its parent's list.
 */
class BirEmployeeListTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private const ROW_KEYS = [
        'employee_id', 'name', 'tin', 'category', 'payroll_count', 'compensation', 'mwe_basic', 'mwe_premium',
        'thirteenth', 'ee', 'taxable', 'exempt_250k', 'tax_withheld',
    ];

    public function test_a_1601c_freezes_each_employee_s_name_and_tin(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $maria = $this->employee('Maria', 'Santos', null);
        $this->payroll($andres, 26000);
        $this->payroll($maria, 13000);

        $id = $this->make1601C();
        $snapshot = BirFormDraft::find($id)->source_snapshot;

        $this->assertSame([
            $andres->id => ['name' => 'VILLANUEVA, ANDRES', 'tin' => '123-456-789-00000'],
            $maria->id => ['name' => 'SANTOS, MARIA', 'tin' => null],
        ], $this->sorted($snapshot['people']));
        $this->assertEqualsCanonicalizing(array_column($snapshot['meta']['employees'], 'employee_id'), array_keys($snapshot['people']));

        $andres->update(['first_name' => 'Andy', 'last_name' => 'Cruz', 'tin_number' => '999-888-777-000']);
        $maria->update(['tin_number' => '111-222-333-000']);

        $this->assertSame($snapshot['people'], BirFormDraft::find($id)->source_snapshot['people'], 'The names and TINs are frozen.');
    }

    public function test_a_soft_deleted_employee_is_still_frozen(): void
    {
        $gone = $this->employee('Pedro', 'Reyes', '222-333-444-000');
        $this->payroll($gone, 20000);
        $gone->delete();

        $snapshot = BirFormDraft::find($this->make1601C())->source_snapshot;

        $this->assertSame([$gone->id => ['name' => 'REYES, PEDRO', 'tin' => '222-333-444-00000']], $this->sorted($snapshot['people']));
    }

    public function test_an_employee_deleted_outright_gets_nulls(): void
    {
        $this->assertSame(
            [999999 => ['name' => null, 'tin' => null]],
            BirEmployeeList::people(['employees' => [['employee_id' => 999999]]])
        );
    }

    public function test_a_month_with_no_payroll_freezes_no_one(): void
    {
        $snapshot = BirFormDraft::find($this->make1601C())->source_snapshot;

        $this->assertSame([], $snapshot['meta']['employees']);
        $this->assertSame([], $snapshot['people']);
    }

    public function test_a_2316_has_no_people(): void
    {
        $employee = $this->employee('Andres', 'Villanueva', '123456789000');

        $id = $this->actingAs(User::factory()->create(['role' => 'accounting']))
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');

        $this->assertArrayNotHasKey('people', BirFormDraft::find($id)->source_snapshot);
    }

    public function test_the_list_is_al_s_rows_with_the_frozen_names_and_adds_up_to_the_form(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $maria = $this->employee('Maria', 'Santos', null);
        $this->payroll($andres, 26000);
        $this->payroll($andres, 26000, '2026-09-16', '2026-09-30');
        $this->payroll($maria, 13000);
        $id = $this->make1601C();
        $draft = BirFormDraft::find($id);

        $response = $this->list($id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Employee list retrieved')
            ->assertJsonPath('data.draft_id', $id)
            ->assertJsonPath('data.form_type', '1601-C')
            ->assertJsonPath('data.period', '2026-09')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.differences', []);

        // Al's rows from the snapshot, in his order, with the frozen name and TIN added.
        $names = [$andres->id => ['VILLANUEVA, ANDRES', '123-456-789-00000'], $maria->id => ['SANTOS, MARIA', null]];
        $expected = array_map(function (array $row) use ($names) {
            $out = [
                'employee_id' => $row['employee_id'],
                'name' => $names[$row['employee_id']][0],
                'tin' => $names[$row['employee_id']][1],
                'category' => $row['category'],
                'payroll_count' => $row['payroll_count'],
            ];
            foreach (array_keys(BirEmployeeList::ITEMS) as $column) {
                $out[$column] = $row[$column];
            }

            return $out;
        }, $draft->source_snapshot['meta']['employees']);

        $this->assertSame($expected, $response->json('data.employees'));
        $this->assertSame([$andres->id, $maria->id], array_column($response->json('data.employees'), 'employee_id'));
        $this->assertSame(self::ROW_KEYS, array_keys($response->json('data.employees.0')));
        $this->assertSame(2, $response->json('data.employees.0.payroll_count'));

        $totals = $response->json('data.totals');
        $this->assertSame(array_keys(BirEmployeeList::ITEMS), array_keys($totals));
        foreach (BirEmployeeList::ITEMS as $column => [, $key]) {
            $this->assertSame($draft->fields[$key]['value'], $totals[$column], "Total {$column} is not {$key}.");
        }
        $this->assertSame('65000.00', $totals['compensation']);

        // Only the list leaves the snapshot: the payroll rows stay hidden.
        $this->assertStringNotContainsString('cutoff_start', $response->getContent());
        $this->assertStringNotContainsString('gross_pay', $response->getContent());
        $this->assertNull($response->json('data.rows'));
        $this->assertNull($response->json('data.meta'));
    }

    public function test_editing_payroll_or_the_employee_afterwards_changes_nothing(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $payroll = $this->payroll($andres, 26000);
        $id = $this->make1601C();
        $before = $this->list($id)->assertOk()->json('data');

        $payroll->update(['gross_pay' => 99999, 'deductions' => ['Withholding Tax' => 5000]]);
        $this->payroll($andres, 40000, '2026-09-16', '2026-09-30');
        $andres->update(['last_name' => 'Cruz', 'tin_number' => '999-888-777-000']);
        SystemSettings::set('bir_mwe_employee_ids', [$andres->id], null, 'json');

        $this->assertSame($before, $this->list($id)->assertOk()->json('data'));
    }

    public function test_an_override_on_item_14_and_an_answer_to_item_20_show_up_as_differences(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $this->payroll($andres, 26000);
        $id = $this->make1601C();
        $totals = $this->list($id)->json('data.totals');
        $this->assertSame('26000.00', $totals['compensation']);

        $this->actingAs($this->accounting())->putJson("/api/bir/drafts/{$id}", ['fields' => [
            'total_compensation' => '27000.00',
            'other_nontaxable_compensation' => '300.00',
            'other_nontaxable_compensation_desc' => 'Rice subsidy',
        ]])->assertOk();

        // Item 22 = 14 - 21, so it moves up with item 14 and down with item 20: +1000.00 - 300.00.
        $taxable = BirFormDraft::find($id)->fields['total_taxable_compensation']['value'];
        $this->list($id)
            ->assertOk()
            ->assertJsonPath('data.totals', $totals)
            ->assertJsonPath('data.differences', [
                ['item' => '14', 'key' => 'total_compensation', 'employees_total' => '26000.00', 'form_value' => '27000.00', 'difference' => '1000.00'],
                ['item' => '22', 'key' => 'total_taxable_compensation', 'employees_total' => $totals['taxable'], 'form_value' => $taxable, 'difference' => '700.00'],
            ]);
    }

    public function test_a_revision_shows_its_parent_s_list(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $this->payroll($andres, 26000);
        $v1 = $this->make1601C();
        $parent = $this->list($v1)->json('data');

        BirFormDraft::whereKey($v1)->update(['status' => 'finalized']);
        $v2 = $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$v1}/revise")->assertCreated()->json('data.id');
        $this->assertNull(BirFormDraft::find($v2)->source_snapshot, 'A revision has no snapshot of its own.');

        $this->list($v2)
            ->assertOk()
            ->assertJsonPath('data.draft_id', $v2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.employees', $parent['employees'])
            ->assertJsonPath('data.totals', $parent['totals'])
            ->assertJsonPath('data.differences', []);
    }

    public function test_a_month_with_no_payroll_has_no_employees_and_zero_totals(): void
    {
        $this->list($this->make1601C())
            ->assertOk()
            ->assertJsonPath('data.employees', [])
            ->assertJsonPath('data.totals', array_fill_keys(array_keys(BirEmployeeList::ITEMS), '0.00'))
            ->assertJsonPath('data.differences', []);
    }

    public function test_a_draft_made_before_the_list_existed_says_so(): void
    {
        $this->payroll($this->employee('Andres', 'Villanueva', '123456789000'), 26000);
        $id = $this->make1601C();
        $this->editSnapshot($id, function (array $snapshot) {
            unset($snapshot['meta']['employees'], $snapshot['people']);

            return $snapshot;
        });

        $this->list($id)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'draft_id' => $id, 'form_type' => '1601-C', 'period' => '2026-09', 'version' => 1,
                    'employees' => null, 'totals' => null, 'differences' => null,
                ],
                'message' => 'This form was made before the employee list existed. Make a new draft to see it.',
            ]);
    }

    public function test_a_draft_made_before_names_were_frozen_lists_rows_without_names(): void
    {
        $andres = $this->employee('Andres', 'Villanueva', '123456789000');
        $this->payroll($andres, 26000);
        $id = $this->make1601C();
        $this->editSnapshot($id, function (array $snapshot) {
            unset($snapshot['people']);

            return $snapshot;
        });

        $this->list($id)
            ->assertOk()
            ->assertJsonPath('data.employees.0.employee_id', $andres->id)
            ->assertJsonPath('data.employees.0.name', null)
            ->assertJsonPath('data.employees.0.tin', null)
            ->assertJsonPath('data.employees.0.compensation', '26000.00');
    }

    public function test_a_2316_is_refused(): void
    {
        $employee = $this->employee('Andres', 'Villanueva', '123456789000');
        $id = $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id])
            ->assertCreated()
            ->json('data.id');

        $this->list($id)
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'The employee list is only for a 1601-C']);
    }

    public function test_a_missing_draft_is_not_found(): void
    {
        $this->list(999999)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Draft not found']);
    }

    public function test_an_employee_is_refused(): void
    {
        $id = $this->make1601C();

        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->getJson("/api/bir/drafts/{$id}/employees")
            ->assertForbidden();
    }

    public function test_it_is_refused_while_the_switch_is_off(): void
    {
        $id = $this->make1601C();
        SystemSettings::set('bir_forms_enabled', false, null, 'boolean');

        $this->list($id)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'The BIR Form Assistant is not enabled']);
    }

    private function list(int $id): TestResponse
    {
        return $this->actingAs($this->accounting())->getJson("/api/bir/drafts/{$id}/employees");
    }

    private function accounting(): User
    {
        return User::factory()->create(['role' => 'accounting']);
    }

    /** Rewrites a draft's snapshot, to stand in for one made before a later change. */
    private function editSnapshot(int $id, callable $edit): void
    {
        $draft = BirFormDraft::find($id);
        $draft->source_snapshot = $edit($draft->source_snapshot);
        $draft->save();
    }

    /**
     * source_snapshot is a MySQL JSON column, which hands keys back in its own order (shortest first),
     * so the frozen people are compared by id, then key name.
     */
    private function sorted(array $people): array
    {
        ksort($people);

        return array_map(function (array $person) {
            ksort($person);

            return $person;
        }, $people);
    }

    private function make1601C(string $period = '2026-09'): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => $period])
            ->assertCreated()
            ->json('data.id');
    }

    private function employee(string $first, string $last, ?string $tin): Employee
    {
        $code = strtoupper(substr($last, 0, 3));

        return Employee::create([
            'employee_id' => "BIR-{$code}",
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower("{$first}.{$last}@example.com"),
            'position' => 'Staff',
            'hire_date' => '2026-01-01',
            'salary' => 26000,
            'status' => 'active',
            'rate_type' => 'monthly',
            'tin_number' => $tin,
        ]);
    }

    private function payroll(Employee $employee, float $gross, string $start = '2026-09-01', string $end = '2026-09-15'): Payroll
    {
        return Payroll::create([
            'employee_id' => $employee->id,
            'cutoff_start' => $start,
            'cutoff_end' => $end,
            'base_salary' => 26000,
            'gross_pay' => $gross,
            'deductions' => ['SSS EE Contribution' => 650, 'Withholding Tax' => 250],
            'allowances' => [],
            'status' => 'finalized',
        ]);
    }
}
