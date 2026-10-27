<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\StatutoryStamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyContributionBasisTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_uses_ordinary_earnings_not_undeclared_salary(): void
    {
        $dailyRate = 1000.0;
        $undeclared = 80000.0;
        $employee = Employee::create([
            'employee_id' => 'EMP-BASIS-1',
            'first_name' => 'Daily',
            'last_name' => 'Basis',
            'email' => 'daily-basis@example.com',
            'position' => 'Tester',
            'hire_date' => '2026-01-01',
            'salary' => $dailyRate,
            'undeclared_salary' => $undeclared,
            'rate_type' => 'daily',
            'status' => 'active',
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => '2026-01-05',
            'clock_in_time' => '09:00:00',
            'clock_out_time' => '18:00:00',
            'status' => 'completed',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-05',
            'cutoff_end' => '2026-01-05',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $stamp = StatutoryStamp::forDaily(1, $dailyRate, 0, 0, 0, 2);

        $this->assertEquals(1, $payroll->days_worked);
        $this->assertEquals($dailyRate * 2, (float) $payroll->contribution_basis);
        $this->assertEquals($stamp->basisPesos(), (float) $payroll->contribution_basis);
        $this->assertEquals($undeclared, (float) $payroll->undeclared_salary);
        $this->assertShareLine($payroll, StatutoryStamp::SSS_EE_LABEL, $stamp->shares()->sss);
        $this->assertShareLine($payroll, StatutoryStamp::PHILHEALTH_EE_LABEL, $stamp->shares()->philhealth);
        $this->assertShareLine($payroll, StatutoryStamp::PAGIBIG_EE_LABEL, $stamp->shares()->pagibig);
        $this->assertNotEquals(
            round(PayrollService::calculateSSS($undeclared, 2), 2),
            (float) $payroll->deductions[StatutoryStamp::SSS_EE_LABEL]
        );
    }

    public function test_generate_with_no_credited_work_stores_a_zero_basis_and_omits_shares(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BASIS-0',
            'first_name' => 'Idle',
            'last_name' => 'Basis',
            'email' => 'idle-basis@example.com',
            'position' => 'Tester',
            'hire_date' => '2026-01-01',
            'salary' => 1000,
            'undeclared_salary' => 80000,
            'rate_type' => 'daily',
            'status' => 'active',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-05',
            'cutoff_end' => '2026-01-05',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $deductions = $payroll->deductions ?? [];

        $this->assertEquals(0, $payroll->days_worked);
        $this->assertEquals(0.0, (float) $payroll->contribution_basis);
        $this->assertArrayNotHasKey(StatutoryStamp::SSS_EE_LABEL, $deductions);
        $this->assertArrayNotHasKey(StatutoryStamp::PHILHEALTH_EE_LABEL, $deductions);
        $this->assertArrayNotHasKey(StatutoryStamp::PAGIBIG_EE_LABEL, $deductions);
    }

    public function test_patch_update_cannot_change_contribution_basis_or_share_amounts(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-BASIS-LOCK',
            'first_name' => 'Locked',
            'last_name' => 'Shares',
            'email' => 'locked-shares@example.com',
            'position' => 'Tester',
            'hire_date' => '2026-01-01',
            'salary' => 1000,
            'rate_type' => 'daily',
            'status' => 'active',
        ]);

        $payroll = Payroll::create([
            'employee_id' => $employee->id,
            'cutoff_start' => '2026-01-01',
            'cutoff_end' => '2026-01-15',
            'base_salary' => 1000,
            'gross_pay' => 1000,
            'net_pay' => 805,
            'status' => 'draft',
            'deductions' => [
                StatutoryStamp::SSS_EE_LABEL => 50,
                StatutoryStamp::PHILHEALTH_EE_LABEL => 125,
                StatutoryStamp::PAGIBIG_EE_LABEL => 20,
                'Late' => 10,
            ],
        ]);
        $payroll->forceFill(['contribution_basis' => 2000])->save();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson("/api/payroll/{$payroll->id}", [
            'contribution_basis' => 99999,
            'deductions' => [
                ['label' => StatutoryStamp::SSS_EE_LABEL, 'amount' => 1],
                ['label' => StatutoryStamp::PHILHEALTH_EE_LABEL, 'amount' => 2],
                ['label' => StatutoryStamp::PAGIBIG_EE_LABEL, 'amount' => 3],
                ['label' => 'Late', 'amount' => 40],
            ],
        ])->assertOk();

        $payroll->refresh();

        $this->assertEquals(2000.0, (float) $payroll->contribution_basis);
        $this->assertEquals(50.0, (float) $payroll->deductions[StatutoryStamp::SSS_EE_LABEL]);
        $this->assertEquals(125.0, (float) $payroll->deductions[StatutoryStamp::PHILHEALTH_EE_LABEL]);
        $this->assertEquals(20.0, (float) $payroll->deductions[StatutoryStamp::PAGIBIG_EE_LABEL]);
        $this->assertEquals(40.0, (float) $payroll->deductions['Late']);
    }

    private function assertShareLine(Payroll $payroll, string $label, float $amount): void
    {
        $this->assertEquals(round($amount, 2), (float) $payroll->deductions[$label]);
    }
}
