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

    public function test_update_recomputes_schedule_when_unpaid_but_locks_principal_after_payment()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-6', 'email' => 'loan-tester-6@example.com']);
        $hr = User::factory()->create(['role' => 'hr']);

        $loan = Loan::create([
            'employee_id' => $employee->id,
            'loan_type' => 'cash_advance',
            'principal' => 10000,
            'interest_rate' => 0.05,
            'total_payable' => 10500,
            'installment_amount' => 2625,
            'term_count' => 4,
            'balance' => 10500,
            'status' => 'active',
            'start_cutoff' => '2026-07-01',
            'approver_id' => $hr->id,
        ]);

        // Unpaid (balance == total_payable): editing principal recomputes the schedule
        $this->actingAs($hr)->putJson("/api/loans/{$loan->id}", [
            'principal' => 20000,
            'interest_rate' => 0.05,
            'term_count' => 4,
            'installment_amount' => 5250,
            'start_cutoff' => '2026-07-01',
        ])->assertOk();

        $loan->refresh();
        $this->assertEquals(20000.00, (float) $loan->principal);
        $this->assertEquals(21000.00, (float) $loan->total_payable);
        $this->assertEquals(5250.00, (float) $loan->installment_amount);
        $this->assertEquals(21000.00, (float) $loan->balance);

        // Simulate a payment already taken — this locks principal/interest_rate/term_count
        $loan->update(['balance' => 15000.00]);

        $this->actingAs($hr)->putJson("/api/loans/{$loan->id}", [
            'principal' => 99999,
            'installment_amount' => 6000,
        ])->assertOk();

        $loan->refresh();
        $this->assertEquals(20000.00, (float) $loan->principal); // unchanged, locked
        $this->assertEquals(21000.00, (float) $loan->total_payable); // unchanged
        $this->assertEquals(6000.00, (float) $loan->installment_amount); // always-editable field still applied
        $this->assertEquals(15000.00, (float) $loan->balance); // untouched by the locked-field attempt
    }

    public function test_destroy_cancels_loan_and_excludes_it_from_future_charges()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-7', 'email' => 'loan-tester-7@example.com']);
        $hr = User::factory()->create(['role' => 'hr']);

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
            'approver_id' => $hr->id,
        ]);

        $this->actingAs($hr)->deleteJson("/api/loans/{$loan->id}")->assertOk();

        $this->assertNull(Loan::find($loan->id));
        $trashed = Loan::withTrashed()->find($loan->id);
        $this->assertSame('cancelled', $trashed->status);
        $this->assertNotNull($trashed->deleted_at);

        $result = LoanService::chargeForPayroll($employee->id, '2026-07-01', '2026-07-15', 999999, 10000, 0);
        $this->assertEquals(0, $result['total']);
        $this->assertArrayNotHasKey('Cash Advance/Others', $result['deductions']);
    }

    public function test_reverse_for_payroll_restores_balance_for_cancelled_loan()
    {
        $employee = $this->makeEmployee(['employee_id' => 'EMP-LOAN-8', 'email' => 'loan-tester-8@example.com', 'salary' => 26000]);
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

        $loan->refresh();
        $this->assertEquals(5000.00, (float) $loan->balance);

        // Cancel (soft-delete) the loan after it's already been charged once
        $this->actingAs($admin)->deleteJson("/api/loans/{$loan->id}")->assertOk();

        // Regenerating must still find + restore the now-trashed loan's balance
        $this->actingAs($admin)->postJson('/api/payroll/generate', $payload)->assertOk();

        $trashed = Loan::withTrashed()->find($loan->id);
        $this->assertEquals(6000.00, (float) $trashed->balance);
        $this->assertEquals(0, LoanPayment::where('loan_id', $loan->id)->count());
    }
}
