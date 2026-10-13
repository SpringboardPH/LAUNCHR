<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirEmployeeList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The 1601-C employee list. A new 1601-C freezes each listed employee's name and TIN on
 * source_snapshot.people beside Al's _meta rows, so a later rename or TIN change doesn't alter it.
 */
class BirEmployeeListTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

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
        return $this->actingAs(User::factory()->create(['role' => 'accounting']))
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
