<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Payroll;
use App\Models\ScheduleTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollToggleSalaryDivisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_salary_toggle_uses_mon_sat_divisor_not_always_261(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-TGL-1',
            'first_name' => 'Toggle',
            'last_name' => 'Case',
            'email' => 'toggle-1@example.com',
            'position' => 'Tester',
            'hire_date' => '2026-01-01',
            'salary' => 20000,
            'undeclared_salary' => 30000,
            'rate_type' => 'monthly',
            'status' => 'active',
        ]);

        $template = ScheduleTemplate::create([
            'type' => 'fixed',
            'name' => 'Mon-Sat ' . uniqid(),
            'work_days' => [1, 2, 3, 4, 5, 6],
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'required_hours_per_day' => 8,
        ]);

        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'schedule_template_id' => $template->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-01-01', 'cutoff_end' => '2026-01-15',
        ])->assertOk();

        $payroll = Payroll::where('employee_id', $employee->id)->sole();

        // Toggle to undeclared salary — the buggy code always fell back to the
        // Mon-Fri (261) divisor here regardless of the employee's real schedule.
        $this->actingAs($admin)
            ->postJson("/api/payroll/{$payroll->id}/toggle-undertime-calc")
            ->assertOk();

        $payroll->refresh();

        // (undeclared_salary * 12) / 313, not / 261 (which would give 1379.31).
        $this->assertEquals(round((30000 * 12) / 313, 2), (float) $payroll->daily_rate);
    }
}
