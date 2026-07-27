<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rest day counts in payroll only when the employee GENUINELY worked it —
 * a real clock-in and a self clock-out. A rest-day log force-closed by
 * auto-clock-out (or left open) must not be paid the rest-day premium.
 */
class RestDayWorkedOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(string $suffix): Employee
    {
        return Employee::create([
            'employee_id' => "EMP-REST-$suffix",
            'first_name'  => 'Rest',
            'last_name'   => $suffix,
            'email'       => "rest-$suffix@example.com",
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 1000,   // daily rate -> daily_rate = 1000
            'rate_type'   => 'daily',
            'status'      => 'active',
        ]);
    }

    private function generate(string $date): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => $date, 'cutoff_end' => $date,
        ])->assertOk();
    }

    public function test_self_clocked_out_rest_day_is_paid(): void
    {
        $employee = $this->makeEmployee('WORKED');

        // No schedule -> default 09:00-18:00 window (9h). status 'rest_day' + a genuine
        // self clock-out => rest-day work: 9/9 * 1000 * 1.30 = 1300.
        AttendanceLog::create([
            'employee_id'   => $employee->id,
            'date'          => '2026-01-10',
            'clock_in_time' => '09:00:00',
            'clock_out_time'=> '18:00:00',
            'status'        => 'rest_day',
        ]);

        $this->generate('2026-01-10');

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $restDayPay = collect($payroll->allowances)->firstWhere('label', 'Rest Day Pay');
        $this->assertNotNull($restDayPay, 'Worked rest day should be paid');
        $this->assertEquals(1300.00, (float) $restDayPay['amount']);
    }

    public function test_auto_clocked_out_rest_day_is_not_paid(): void
    {
        $employee = $this->makeEmployee('AUTO');

        // Same rest-day punch, but the clock-out was fabricated by auto-clock-out.
        AttendanceLog::create([
            'employee_id'    => $employee->id,
            'date'           => '2026-01-10',
            'clock_in_time'  => '09:00:00',
            'clock_out_time' => '23:59:00',
            'status'         => 'rest_day',
            'clock_out_notes'=> '[System] Automatically clocked out due to missed departure window.',
        ]);

        $this->generate('2026-01-10');

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $this->assertNull(
            collect($payroll->allowances)->firstWhere('label', 'Rest Day Pay'),
            'Auto-closed rest day must not be paid'
        );
        $this->assertEquals(0.00, (float) $payroll->gross_pay);
    }
}
