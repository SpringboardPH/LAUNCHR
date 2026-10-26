<?php

namespace Tests\Feature;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveOnBehalfFilingTest extends TestCase
{
    use RefreshDatabase;

    private function weekdays(int $count, Carbon $from): array
    {
        $days = [];
        $cursor = $from->copy()->startOfDay();
        while (count($days) < $count) {
            if ($cursor->isWeekday()) {
                $days[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $days;
    }

    private function employee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => 'EMP-'.uniqid(),
            'first_name' => 'Past',
            'last_name' => 'Leave',
            'email' => uniqid('leave').'@example.com',
            'position' => 'Staff',
            'hire_date' => '2020-01-01',
            'salary' => 26000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ], $overrides));
    }

    private function paidType(int $days = 5): void
    {
        LeaveType::create([
            'code' => 'vl',
            'name' => 'Vacation Leave',
            'requires_balance' => true,
            'is_paid' => true,
            'is_active' => true,
            'default_days' => $days,
        ]);
    }

    public function test_employee_cannot_file_a_past_start(): void
    {
        $this->paidType();
        $user = User::factory()->create(['role' => 'employee']);
        $this->employee(['user_id' => $user->id, 'email' => $user->email]);
        $past = $this->weekdays(1, SystemClock::today()->subDays(21))[0];

        $this->actingAs($user)
            ->postJson('/api/leaves', [
                'leave_type' => 'vl',
                'start_date' => $past,
                'end_date' => $past,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.start_date.0', 'Start date must be today or a future date.');
    }

    public function test_on_behalf_roles_can_file_a_past_start(): void
    {
        $this->paidType();
        $employee = $this->employee();
        $past = $this->weekdays(1, SystemClock::today()->subDays(21))[0];

        foreach (['admin', 'hr', 'accounting'] as $role) {
            $actor = User::factory()->create(['role' => $role]);

            $created = $this->actingAs($actor)
                ->postJson('/api/leaves', [
                    'employee_id' => $employee->id,
                    'leave_type' => 'vl',
                    'start_date' => $past,
                    'end_date' => $past,
                ])
                ->assertCreated()
                ->assertJsonPath('data.status', 'pending');

            $this->assertSame($past, \App\Models\LeaveRequest::find($created->json('data.id'))->start_date->toDateString());
        }
    }

    public function test_admin_own_leave_still_rejects_a_past_start(): void
    {
        $this->paidType();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->employee(['user_id' => $admin->id, 'email' => $admin->email]);
        $past = $this->weekdays(1, SystemClock::today()->subDays(21))[0];

        $this->actingAs($admin)
            ->postJson('/api/leaves', [
                'leave_type' => 'vl',
                'start_date' => $past,
                'end_date' => $past,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.start_date.0', 'Start date must be today or a future date.');
    }

    public function test_start_before_hire_date_is_rejected(): void
    {
        $this->paidType();
        $hire = SystemClock::today()->toDateString();
        $employee = $this->employee(['hire_date' => $hire]);
        $past = $this->weekdays(1, SystemClock::today()->subDays(21))[0];
        $hr = User::factory()->create(['role' => 'hr']);

        $this->actingAs($hr)
            ->postJson('/api/leaves', [
                'employee_id' => $employee->id,
                'leave_type' => 'vl',
                'start_date' => $past,
                'end_date' => $past,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.start_date.0', 'Start date cannot be before the employee hire date.');
    }

    public function test_employee_cannot_exceed_balance(): void
    {
        $this->paidType(5);
        $user = User::factory()->create(['role' => 'employee']);
        $this->employee(['user_id' => $user->id, 'email' => $user->email]);
        $days = $this->weekdays(6, SystemClock::today());

        $this->actingAs($user)
            ->postJson('/api/leaves', [
                'leave_type' => 'vl',
                'start_date' => $days[0],
                'end_date' => $days[5],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Leave request exceeds your available balance.');
    }

    public function test_on_behalf_can_file_past_the_balance_and_accept_when_confirmed(): void
    {
        $this->paidType(5);
        $employee = $this->employee();
        $days = $this->weekdays(6, SystemClock::today()->subDays(40));
        $hr = User::factory()->create(['role' => 'hr']);

        $created = $this->actingAs($hr)
            ->postJson('/api/leaves', [
                'employee_id' => $employee->id,
                'leave_type' => 'vl',
                'start_date' => $days[0],
                'end_date' => $days[5],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $leaveId = $created->json('data.id');

        $this->actingAs($hr)
            ->getJson('/api/leaves/balance?employee_id='.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.balances.vl.used', 6);

        $this->actingAs($hr)
            ->patchJson("/api/leaves/{$leaveId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', "Approving this leave would exceed the employee's remaining balance.");

        $this->actingAs($hr)
            ->patchJson("/api/leaves/{$leaveId}/approve", [
                'confirm_exceed_balance' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($hr)
            ->getJson('/api/leaves/balance?employee_id='.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.balances.vl.used', 6);
    }
}
