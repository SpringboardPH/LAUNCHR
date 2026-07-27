<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee earns nothing for a cutoff that ends before their hire date.
 * Monthly base pay is a flat salary/periods, so without a hire-date gate a
 * new hire would be paid a full half-month for a period they weren't employed.
 */
class PrehireNoPayrollTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonthly(string $suffix, string $hireDate): Employee
    {
        return Employee::create([
            'employee_id' => "EMP-HIRE-$suffix",
            'first_name'  => 'New',
            'last_name'   => $suffix,
            'email'       => "hire-$suffix@example.com",
            'position'    => 'Tester',
            'hire_date'   => $hireDate,
            'salary'      => 20000,
            'rate_type'   => 'monthly',
            'status'      => 'active',
        ]);
    }

    public function test_no_payroll_for_cutoff_entirely_before_hire_date(): void
    {
        $employee = $this->makeMonthly('AFTER', '2026-06-30');

        $admin = User::factory()->create(['role' => 'admin']);
        // Cutoff Jun 11–25 is entirely before the June 30 hire date.
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-06-11', 'cutoff_end' => '2026-06-25',
        ])->assertOk();

        $this->assertNull(
            Payroll::where('employee_id', $employee->id)->first(),
            'Employee hired after the cutoff must not get a payroll'
        );
    }

    public function test_regenerating_removes_a_stale_prehire_draft(): void
    {
        $employee = $this->makeMonthly('STALE', '2026-06-30');

        // Simulate a previously-generated erroneous draft for the pre-hire cutoff.
        Payroll::create([
            'employee_id'  => $employee->id,
            'cutoff_start' => '2026-06-11',
            'cutoff_end'   => '2026-06-25',
            'base_salary'  => 20000,
            'daily_rate'   => 0,
            'gross_pay'    => 10000,
            'net_pay'      => 10000,
            'status'       => 'draft',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-06-11', 'cutoff_end' => '2026-06-25',
        ])->assertOk();

        $this->assertNull(
            Payroll::where('employee_id', $employee->id)->first(),
            'Stale pre-hire draft should be removed on regenerate'
        );
    }
}
