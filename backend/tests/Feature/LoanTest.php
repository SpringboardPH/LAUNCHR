<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => 'EMP-LOAN-1',
            'first_name'  => 'Loan',
            'last_name'   => 'Tester',
            'email'       => 'loan-tester@example.com',
            'position'    => 'Staff',
            'hire_date'   => '2026-01-01',
            'salary'      => 26000,
            'status'      => 'active',
            'rate_type'   => 'monthly',
        ], $overrides));
    }

    public function test_approving_cash_advance_request_creates_active_loan_with_correct_schedule()
    {
        $employee = $this->makeEmployee();
        $admin = User::factory()->create(['role' => 'admin']);

        $employeeRequest = EmployeeRequest::create([
            'employee_id' => $employee->id,
            'request_type' => 'cash_advance',
            'subject' => 'Cash advance request',
            'meta' => ['principal' => 10000, 'term_count' => 4, 'interest_rate' => 0.05],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/requests/{$employeeRequest->id}/approve", [])
            ->assertOk();

        $loan = Loan::where('request_id', $employeeRequest->id)->first();

        $this->assertNotNull($loan);
        $this->assertSame('active', $loan->status);
        $this->assertSame($employee->id, $loan->employee_id);
        $this->assertEquals(10500.00, (float) $loan->total_payable);
        $this->assertEquals(2625.00, (float) $loan->installment_amount);
        $this->assertEquals(10500.00, (float) $loan->balance);
    }

    public function test_rejecting_cash_advance_request_creates_no_loan()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-2', 'email' => 'loan-tester-2@example.com']);
        $admin = User::factory()->create(['role' => 'admin']);

        $employeeRequest = EmployeeRequest::create([
            'employee_id' => $employee->id,
            'request_type' => 'cash_advance',
            'subject' => 'Cash advance request',
            'meta' => ['principal' => 20000, 'term_count' => 6, 'interest_rate' => 0.08],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/requests/{$employeeRequest->id}/reject", ['response_notes' => 'Not eligible'])
            ->assertOk();

        $this->assertNull(Loan::where('request_id', $employeeRequest->id)->first());
    }

    public function test_hr_can_create_government_loan_directly_as_active()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-3', 'email' => 'loan-tester-3@example.com']);
        $hr = User::factory()->create(['role' => 'hr']);

        $response = $this->actingAs($hr)->postJson('/api/loans', [
            'employee_id' => $employee->id,
            'loan_type' => 'sss_salary',
            'principal' => 24000,
            'installment_amount' => 2000,
            'term_count' => 12,
            'start_cutoff' => '2026-07-16',
        ])->assertCreated();

        $loan = Loan::find($response->json('data.id'));
        $this->assertSame('active', $loan->status);
        $this->assertEquals(24000.00, (float) $loan->total_payable);
        $this->assertEquals(24000.00, (float) $loan->balance);
    }

    public function test_generate_charges_active_loan_and_regeneration_does_not_double_charge()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-4', 'email' => 'loan-tester-4@example.com', 'salary' => 26000]);
        $admin = User::factory()->create(['role' => 'admin']);

        $loan = Loan::create([
            'employee_id' => $employee->id,
            'loan_type' => 'cash_advance',
            'principal' => 6000,
            'interest_rate' => 0,
            'total_payable' => 6000,
            'installment_amount' => 1000,
            'term_count' => 6,
            'balance' => 6000,
            'status' => 'active',
            'start_cutoff' => '2026-07-01',
            'approver_id' => $admin->id,
        ]);

        $payload = ['cutoff_start' => '2026-07-01', 'cutoff_end' => '2026-07-15'];

        $this->actingAs($admin)->postJson('/api/payroll/generate', $payload)->assertOk();

        $payroll = \App\Models\Payroll::where('employee_id', $employee->id)->first();
        $this->assertEquals(1000.00, (float) ($payroll->deductions['Cash Advance/Others'] ?? 0));
        $this->assertEquals(1, \App\Models\LoanPayment::where('loan_id', $loan->id)->count());

        $loan->refresh();
        $this->assertEquals(5000.00, (float) $loan->balance);

        $netAfterFirstRun = (float) $payroll->net_pay;

        // Regenerate the same cutoff — must reverse-then-reapply, not double-charge
        $this->actingAs($admin)->postJson('/api/payroll/generate', $payload)->assertOk();

        $loan->refresh();
        $this->assertEquals(5000.00, (float) $loan->balance);
        $this->assertEquals(1, \App\Models\LoanPayment::where('loan_id', $loan->id)->count());

        $payroll->refresh();
        $this->assertEquals($netAfterFirstRun, (float) $payroll->net_pay);
    }

    public function test_generate_caps_loan_charge_at_net_pay_floor()
    {
        \App\Models\SystemSettings::set('loan_min_net_pay_floor', 5000, null, 'integer');

        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-5', 'email' => 'loan-tester-5@example.com', 'salary' => 5200]);
        $admin = User::factory()->create(['role' => 'admin']);

        Loan::create([
            'employee_id' => $employee->id,
            'loan_type' => 'cash_advance',
            'principal' => 6000,
            'interest_rate' => 0,
            'total_payable' => 6000,
            'installment_amount' => 3000,
            'term_count' => 2,
            'balance' => 6000,
            'status' => 'active',
            'start_cutoff' => '2026-07-01',
            'approver_id' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/payroll/generate', [
            'cutoff_start' => '2026-07-01', 'cutoff_end' => '2026-07-15',
        ])->assertOk();

        $payroll = \App\Models\Payroll::where('employee_id', $employee->id)->first();
        // Semi-monthly gross for a 5200 salary is 2600 pre-loan net; floor is 5000,
        // so no charge should be applied (net is already below the floor).
        $this->assertArrayNotHasKey('Cash Advance/Others', $payroll->deductions ?? []);
    }
}
