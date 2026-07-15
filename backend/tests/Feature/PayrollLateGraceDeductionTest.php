<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollLateGraceDeductionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A log marked 'completed' (grace period covered the late clock-in — see
     * AttendanceService::calculateStatus) must not still generate a "Late"
     * payroll deduction. calculateDetails()'s late_minutes is the raw,
     * pre-grace value, so PayrollController must gate it on the log's status.
     */
    public function test_grace_covered_late_clock_in_produces_no_late_deduction()
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-GRACE-1',
            'first_name'  => 'Grace',
            'last_name'   => 'Period',
            'email'       => 'grace-period@example.com',
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 1000,
            'rate_type'   => 'daily',
            'status'      => 'active',
        ]);

        // No schedule assigned -> default work window 09:00-18:00 (expectedHours = 9).
        // Clocked in 5 minutes late, worked the rest of the day -> raw late_minutes = 5,
        // but HR already resolved this as 'completed' (e.g. grace period covered it).
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => '2026-01-05',
            'clock_in_time' => '09:05:00',
            'clock_out_time' => '18:00:00',
            'status' => 'completed',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-05', 'cutoff_end' => '2026-01-05',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $this->assertArrayNotHasKey('Late', $payroll->deductions);
        $this->assertEquals(1000.00, (float) $payroll->net_pay);
    }

    public function test_late_status_log_still_produces_a_late_deduction()
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-GRACE-2',
            'first_name'  => 'Actually',
            'last_name'   => 'Late',
            'email'       => 'actually-late@example.com',
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 1000,
            'rate_type'   => 'daily',
            'status'      => 'active',
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => '2026-01-05',
            'clock_in_time' => '09:05:00',
            'clock_out_time' => '18:00:00',
            'status' => 'late',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-05', 'cutoff_end' => '2026-01-05',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $this->assertArrayHasKey('Late', $payroll->deductions);
        // 5 late minutes x (1000/8 hourly rate) / 60 = 10.42
        $this->assertEquals(10.42, (float) $payroll->deductions['Late']);
    }
}
