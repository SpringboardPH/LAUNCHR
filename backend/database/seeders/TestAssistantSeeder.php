<?php

namespace Database\Seeders;

use App\Helpers\SystemClock;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\ScheduleTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A single, obviously-fake employee with data across every LaunchAssist tool
 * (profile, schedule, attendance, leave, payslips) so the AI assistant can be
 * exercised end-to-end. Safe to delete. Idempotent — re-running resets the data.
 *
 * Login: test.assistant@company.com / test12345
 */
class TestAssistantSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test.assistant@company.com'],
            ['name' => '🧪 TEST — AI Demo Account', 'password' => bcrypt('test12345'), 'role' => 'employee']
        );
        $user->forceFill(['password' => bcrypt('test12345'), 'role' => 'employee'])->save();

        $employee = Employee::updateOrCreate(
            ['email' => 'test.assistant@company.com'],
            [
                'user_id'    => $user->id,
                'employee_id'=> 'TEST-AI-001',
                'first_name' => 'Testy',
                'last_name'  => 'Testerson (TEST DATA — DO NOT USE)',
                'phone'      => '0917-000-TEST',
                'position'   => 'QA Test Subject',
                'department' => 'Testing / QA',
                'hire_date'  => '2023-03-01',
                'salary'     => 45000.00,
                'rate_type'  => 'monthly',
                'status'     => 'active',
                'notes'      => 'AUTOMATED TEST ACCOUNT for exercising the AI assistant. Not a real person. Safe to delete.',
            ]
        );

        // Wipe prior test data so re-runs stay clean (no natural unique key on these).
        AttendanceLog::where('employee_id', $employee->id)->delete();
        LeaveRequest::where('employee_id', $employee->id)->delete();
        Payroll::where('employee_id', $employee->id)->delete();
        EmployeeSchedule::where('employee_id', $employee->id)->delete();

        $this->seedSchedule($employee);
        $this->seedAttendance($employee);
        $this->seedLeave($employee);
        $this->seedPayslips($employee);

        $this->command->info("Test account: {$user->email} / test12345 -> employee #{$employee->id} (TEST-AI-001)");
    }

    private function seedSchedule(Employee $employee): void
    {
        $template = ScheduleTemplate::where('name', 'like', 'Standard 9-6%')->first()
            ?? ScheduleTemplate::first();
        if (!$template) {
            return;
        }
        // Wide range so getCurrentForEmployee() always finds it.
        EmployeeSchedule::create([
            'employee_id'          => $employee->id,
            'schedule_template_id' => $template->id,
            'start_date'           => SystemClock::today()->copy()->subMonths(2)->startOfWeek(),
            'end_date'             => SystemClock::today()->copy()->addMonths(2)->endOfWeek(),
            'status'               => 'active',
        ]);
        $this->template = $template;
    }

    private ?ScheduleTemplate $template = null;

    private function seedAttendance(Employee $employee): void
    {
        // Collect the last ~28 days of weekdays (most-recent first) so special
        // statuses land on real workdays, never a skipped weekend.
        $weekdays = [];
        for ($daysAgo = 1; $daysAgo <= 28; $daysAgo++) {
            $date = SystemClock::today()->copy()->subDays($daysAgo);
            if (!$date->isWeekend()) {
                $weekdays[] = $date;
            }
        }

        // Assign by position in the weekday list => [in, out|null, status].
        $special = [
            1  => ['09:34:00', '18:05:00', 'late'],
            3  => ['09:02:00', '16:20:00', 'undertime'],
            5  => ['09:00:00', '13:00:00', 'half_day'],
            7  => [null, null, 'absent'],
            10 => ['09:41:00', '18:10:00', 'late'],
        ];

        $tpl = $this->template;
        foreach ($weekdays as $i => $date) {
            [$in, $out, $status] = $special[$i] ?? ['09:00:00', '18:00:00', 'completed'];

            AttendanceLog::create([
                'employee_id'            => $employee->id,
                'date'                   => $date->toDateString(),
                'clock_in_time'          => $in,
                'clock_out_time'         => $out,
                'status'                 => $status,
                'schedule_template_id'   => $tpl?->id,
                'schedule_template_name' => $tpl?->name,
            ]);
        }
    }

    private function seedLeave(Employee $employee): void
    {
        $today = SystemClock::today();
        $rows = [
            ['vacation', $today->copy()->subDays(20), $today->copy()->subDays(18), 3, 'approved', 'Family vacation (TEST)', null],
            ['sick',     $today->copy()->addDays(6),  $today->copy()->addDays(7),  2, 'pending',  'Flu recovery (TEST)', null],
            ['vacation', $today->copy()->subDays(40), $today->copy()->subDays(40), 1, 'rejected', 'Personal errand (TEST)', 'Insufficient team coverage'],
        ];
        foreach ($rows as [$type, $start, $end, $days, $status, $reason, $rej]) {
            LeaveRequest::create([
                'employee_id'      => $employee->id,
                'leave_type'       => $type,
                'start_date'       => $start->toDateString(),
                'end_date'         => $end->toDateString(),
                'days_requested'   => $days,
                'status'           => $status,
                'reason'           => $reason,
                'rejection_reason' => $rej,
            ]);
        }
    }

    private function seedPayslips(Employee $employee): void
    {
        // Monthly ₱45,000 → daily rate (Mon–Fri): 45000*12/261.
        $dailyRate = round(45000 * 12 / 261, 2); // 2068.97
        $cutoffs = [
            ['2026-06-01', '2026-06-15', '2026-06-16', 11],
            ['2026-06-16', '2026-06-30', '2026-07-01', 11],
        ];
        foreach ($cutoffs as [$start, $end, $paid, $days]) {
            $gross = round($dailyRate * $days, 2);
            $deductions = [
                'sss'        => 1125.00,
                'philhealth' => 562.50,
                'pagibig'    => 100.00,
                'withholding_tax' => 1480.00,
                'late'       => 0.00,
            ];
            $net = round($gross - array_sum($deductions), 2);

            Payroll::create([
                'employee_id'   => $employee->id,
                'cutoff_start'  => $start,
                'cutoff_end'    => $end,
                'base_salary'   => 45000.00,
                'daily_rate'    => $dailyRate,
                'days_worked'   => $days,
                'total_hours'   => $days * 8,
                'overtime_hours'=> 0,
                'late_minutes'  => 0,
                'undertime_minutes' => 0,
                'gross_pay'     => $gross,
                'deductions'    => $deductions,
                'allowances'    => ['meal' => 1000.00],
                'net_pay'       => $net,
                'status'        => 'paid',
                'paid_at'       => $paid,
            ]);
        }
    }
}
