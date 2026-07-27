<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A day only counts as a PAID worked day when actual hours were logged.
 * An open punch (clocked in, never clocked out) has 0 hours and must not
 * inflate days_worked / base pay — the flexi "all days enabled" over-count.
 */
class OpenPunchNotPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_punch_does_not_count_as_a_paid_day(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-OPEN-1',
            'first_name'  => 'Open',
            'last_name'   => 'Punch',
            'email'       => 'open-punch@example.com',
            'position'    => 'Tester',
            'hire_date'   => '2026-01-01',
            'salary'      => 695,      // daily rate
            'rate_type'   => 'daily',
            'status'      => 'active',
        ]);

        // Day 1: a real, completed 9h day → counts.
        AttendanceLog::create([
            'employee_id'    => $employee->id,
            'date'           => '2026-01-12',
            'clock_in_time'  => '09:00:00',
            'clock_out_time' => '18:00:00',
            'status'         => 'completed',
        ]);
        // Day 2: an open punch — clocked in, never clocked out → 0 hours → must NOT be paid.
        AttendanceLog::create([
            'employee_id'   => $employee->id,
            'date'          => '2026-01-13',
            'clock_in_time' => '09:00:00',
            'status'        => 'working',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-12', 'cutoff_end' => '2026-01-13',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();
        $this->assertEquals(1, (int) $payroll->days_worked, 'Open punch should not count as a worked day');
        $this->assertEquals(695.00, (float) $payroll->gross_pay);
    }
}
