<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Loan;
use App\Models\User;
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
            'request_type' => 'company_loan',
            'subject' => 'Company loan request',
            'meta' => ['principal' => 20000, 'term_count' => 6, 'interest_rate' => 0.08],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/requests/{$employeeRequest->id}/reject", ['response_notes' => 'Not eligible'])
            ->assertOk();

        $this->assertNull(Loan::where('request_id', $employeeRequest->id)->first());
    }
}
