<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingLeave(): LeaveRequest
    {
        LeaveType::create([
            'code' => 'unpaid',
            'name' => 'Unpaid Leave',
            'requires_balance' => false,
            'is_paid' => false,
            'is_active' => true,
            'default_days' => 0,
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-LEAVE-1',
            'first_name' => 'Leave',
            'last_name' => 'Tester',
            'email' => 'leave-tester@example.com',
            'position' => 'Staff',
            'hire_date' => '2026-01-01',
            'salary' => 26000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'unpaid',
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-14',
            'reason' => 'Personal',
            'status' => 'pending',
        ]);

        $leave->created_at = now()->subDays(10);
        $leave->save();

        return $leave;
    }

    public function test_admin_can_approve_pending_leave_filed_ten_days_ago()
    {
        $leave = $this->makePendingLeave();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/leaves/{$leave->id}/approve")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_admin_can_reject_pending_leave_filed_ten_days_ago()
    {
        $leave = $this->makePendingLeave();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/leaves/{$leave->id}/reject", [
                'rejection_reason' => 'Insufficient coverage',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('rejected', $leave->fresh()->status);
    }
}
