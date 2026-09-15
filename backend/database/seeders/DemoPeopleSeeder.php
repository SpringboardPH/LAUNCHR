<?php

namespace Database\Seeders;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveType;
use App\Models\ScheduleTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoPeopleSeeder extends Seeder
{
    public function run(): void
    {
        $today = SystemClock::today();
        $templates = ScheduleTemplate::query()->get()->keyBy('name');
        $standard = $templates['Standard 9-6 (Mon-Fri)'];
        $leaveTypes = LeaveType::query()->where('requires_balance', true)->get();

        foreach ($this->personas() as $i => $persona) {
            $seq = $i + 1;
            $template = $templates[$persona['schedule']] ?? $standard;
            $userId = null;

            if (!empty($persona['role'])) {
                $user = User::updateOrCreate(
                    ['email' => $persona['email']],
                    [
                        'name' => $persona['first_name'].' '.$persona['last_name'],
                        'password' => 'password',
                        'role' => $persona['role'],
                    ]
                );
                $userId = $user->id;
            }

            $employee = Employee::updateOrCreate(
                ['employee_id' => $persona['employee_id']],
                [
                    'user_id' => $userId,
                    'first_name' => $persona['first_name'],
                    'last_name' => $persona['last_name'],
                    'email' => $persona['email'],
                    'phone' => sprintf('09%09d', 170000000 + $seq),
                    'position' => $persona['position'],
                    'department' => $persona['department'],
                    'hire_date' => $persona['hire_date'],
                    'salary' => $persona['salary'],
                    'undeclared_salary' => $persona['undeclared_salary'] ?? 0,
                    'rate_type' => $persona['rate_type'] ?? 'monthly',
                    'status' => 'active',
                    'notes' => $persona['notes'] ?? null,
                    'bank_account_number' => sprintf('%012d', 100000000000 + $seq),
                    'sss_number' => sprintf('%02d-%07d-%d', 34, 1000000 + $seq, $seq % 10),
                    'philhealth_number' => sprintf('%02d-%09d-%d', 12, 200000000 + $seq, $seq % 10),
                    'pagibig_number' => sprintf('%04d-%04d-%04d', 1210, 1000 + $seq, 2000 + $seq),
                    'tin_number' => sprintf('%03d-%03d-%03d-0000', 100 + $seq, 200 + $seq, 300 + $seq),
                    'geo_tracking_enabled' => true,
                ]
            );

            EmployeeSchedule::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'schedule_template_id' => $template->id,
                ],
                [
                    'start_date' => $today->copy()->subMonths(6)->toDateString(),
                    'end_date' => $today->copy()->addYear()->toDateString(),
                    'status' => 'active',
                ]
            );

            foreach ($leaveTypes as $type) {
                EmployeeLeaveBalance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                    ],
                    [
                        'allocated_days' => $type->default_days,
                        'carryover_days' => 0,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function personas(): array
    {
        $std = 'Standard 9-6 (Mon-Fri)';

        return [
            ['employee_id' => 'EMP001', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'hr@springboardph.com', 'role' => 'hr', 'position' => 'HR Manager', 'department' => 'Human Resources', 'hire_date' => '2021-03-01', 'salary' => 55000, 'schedule' => $std, 'notes' => 'Demo HR login'],
            ['employee_id' => 'EMP002', 'first_name' => 'Carlo', 'last_name' => 'Reyes', 'email' => 'accounting@springboardph.com', 'role' => 'accounting', 'position' => 'Senior Accountant', 'department' => 'Finance', 'hire_date' => '2020-08-15', 'salary' => 48000, 'schedule' => $std, 'notes' => 'Demo accounting login'],
            ['employee_id' => 'EMP003', 'first_name' => 'Juan', 'last_name' => 'Cruz', 'email' => 'juan@springboardph.com', 'role' => 'employee', 'position' => 'Full Stack Developer', 'department' => 'Technology', 'hire_date' => '2023-03-20', 'salary' => 38000, 'schedule' => $std, 'notes' => 'Live clock-in persona'],
            ['employee_id' => 'EMP004', 'first_name' => 'Ana', 'last_name' => 'Garcia', 'email' => 'ana@springboardph.com', 'role' => 'employee', 'position' => 'Accountant', 'department' => 'Finance', 'hire_date' => '2021-06-10', 'salary' => 35000, 'schedule' => $std],
            ['employee_id' => 'EMP005', 'first_name' => 'Pedro', 'last_name' => 'Lopez', 'email' => 'pedro@springboardph.com', 'role' => 'employee', 'position' => 'Sales Executive', 'department' => 'Sales', 'hire_date' => '2023-01-05', 'salary' => 32000, 'schedule' => $std],
            ['employee_id' => 'EMP006', 'first_name' => 'Rosa', 'last_name' => 'Reyes', 'email' => 'rosa@springboardph.com', 'role' => 'employee', 'position' => 'Administrative Assistant', 'department' => 'Administration', 'hire_date' => '2022-09-12', 'salary' => 25000, 'schedule' => $std],
            ['employee_id' => 'EMP007', 'first_name' => 'Luis', 'last_name' => 'Fernandez', 'email' => 'luis.fernandez@springboardph.com', 'position' => 'Software Engineer', 'department' => 'Technology', 'hire_date' => '2022-04-04', 'salary' => 42000, 'schedule' => $std],
            ['employee_id' => 'EMP008', 'first_name' => 'Sofia', 'last_name' => 'Mendoza', 'email' => 'sofia.mendoza@springboardph.com', 'position' => 'Recruiter', 'department' => 'Human Resources', 'hire_date' => '2022-11-07', 'salary' => 30000, 'schedule' => $std],
            ['employee_id' => 'EMP009', 'first_name' => 'Miguel', 'last_name' => 'Torres', 'email' => 'miguel.torres@springboardph.com', 'position' => 'QA Engineer', 'department' => 'Technology', 'hire_date' => '2023-02-13', 'salary' => 33000, 'schedule' => 'Night Shift (10PM-6AM)'],
            ['employee_id' => 'EMP010', 'first_name' => 'Isabel', 'last_name' => 'Ramos', 'email' => 'isabel.ramos@springboardph.com', 'position' => 'Night Support Specialist', 'department' => 'Technology', 'hire_date' => '2023-07-17', 'salary' => 31000, 'schedule' => 'Night Shift (10PM-6AM)'],
            ['employee_id' => 'EMP011', 'first_name' => 'Diego', 'last_name' => 'Navarro', 'email' => 'diego.navarro@springboardph.com', 'position' => 'Sales Associate', 'department' => 'Sales', 'hire_date' => '2024-01-08', 'salary' => 28000, 'schedule' => $std],
            ['employee_id' => 'EMP012', 'first_name' => 'Camille', 'last_name' => 'Bautista', 'email' => 'camille.bautista@springboardph.com', 'position' => 'Marketing Associate', 'department' => 'Sales', 'hire_date' => '2023-05-22', 'salary' => 29000, 'schedule' => $std],
            ['employee_id' => 'EMP013', 'first_name' => 'Rafael', 'last_name' => 'Lim', 'email' => 'rafael.lim@springboardph.com', 'position' => 'Systems Administrator', 'department' => 'Technology', 'hire_date' => '2021-10-18', 'salary' => 40000, 'schedule' => 'Flexible Hours 8-8'],
            ['employee_id' => 'EMP014', 'first_name' => 'Hannah', 'last_name' => 'Villanueva', 'email' => 'hannah.villanueva@springboardph.com', 'position' => 'Payroll Specialist', 'department' => 'Finance', 'hire_date' => '2022-02-28', 'salary' => 34000, 'schedule' => $std],
            ['employee_id' => 'EMP015', 'first_name' => 'Marco', 'last_name' => 'Dela Cruz', 'email' => 'marco.delacruz@springboardph.com', 'position' => 'Collections Associate', 'department' => 'Finance', 'hire_date' => '2024-03-04', 'salary' => 850, 'undeclared_salary' => 18000, 'rate_type' => 'daily', 'schedule' => $std],
            ['employee_id' => 'EMP016', 'first_name' => 'Patricia', 'last_name' => 'Ong', 'email' => 'patricia.ong@springboardph.com', 'position' => 'Office Manager', 'department' => 'Administration', 'hire_date' => '2020-05-11', 'salary' => 36000, 'schedule' => $std],
            ['employee_id' => 'EMP017', 'first_name' => 'Gabriel', 'last_name' => 'Tan', 'email' => 'gabriel.tan@springboardph.com', 'position' => 'Junior Developer', 'department' => 'Technology', 'hire_date' => '2024-06-03', 'salary' => 28000, 'schedule' => $std],
            ['employee_id' => 'EMP018', 'first_name' => 'Andrea', 'last_name' => 'Flores', 'email' => 'andrea.flores@springboardph.com', 'position' => 'Customer Success', 'department' => 'Sales', 'hire_date' => '2023-09-11', 'salary' => 30000, 'schedule' => $std],
            ['employee_id' => 'EMP019', 'first_name' => 'Nico', 'last_name' => 'Aquino', 'email' => 'nico.aquino@springboardph.com', 'position' => 'HR Associate', 'department' => 'Human Resources', 'hire_date' => '2023-08-14', 'salary' => 27000, 'schedule' => $std],
            ['employee_id' => 'EMP020', 'first_name' => 'Bianca', 'last_name' => 'Sy', 'email' => 'bianca.sy@springboardph.com', 'position' => 'Bookkeeper', 'department' => 'Finance', 'hire_date' => '2022-07-25', 'salary' => 26000, 'schedule' => $std],
            ['employee_id' => 'EMP021', 'first_name' => 'Enzo', 'last_name' => 'Gutierrez', 'email' => 'enzo.gutierrez@springboardph.com', 'position' => 'Sales Lead', 'department' => 'Sales', 'hire_date' => '2021-12-06', 'salary' => 37000, 'schedule' => $std],
            ['employee_id' => 'EMP022', 'first_name' => 'Katrina', 'last_name' => 'Perez', 'email' => 'katrina.perez@springboardph.com', 'position' => 'Receptionist', 'department' => 'Administration', 'hire_date' => '2024-02-19', 'salary' => 22000, 'schedule' => $std],
            ['employee_id' => 'EMP023', 'first_name' => 'Paolo', 'last_name' => 'Chua', 'email' => 'paolo.chua@springboardph.com', 'position' => 'DevOps Engineer', 'department' => 'Technology', 'hire_date' => '2022-06-20', 'salary' => 45000, 'schedule' => $std],
            ['employee_id' => 'EMP024', 'first_name' => 'Liza', 'last_name' => 'Morales', 'email' => 'liza.morales@springboardph.com', 'position' => 'Benefits Officer', 'department' => 'Human Resources', 'hire_date' => '2021-09-27', 'salary' => 31000, 'schedule' => $std],
            ['employee_id' => 'EMP025', 'first_name' => 'Anton', 'last_name' => 'Villar', 'email' => 'anton.villar@springboardph.com', 'position' => 'Account Executive', 'department' => 'Sales', 'hire_date' => '2023-04-03', 'salary' => 33000, 'schedule' => $std],
            ['employee_id' => 'EMP026', 'first_name' => 'Joyce', 'last_name' => 'Alonzo', 'email' => 'joyce.alonzo@springboardph.com', 'position' => 'AP Clerk', 'department' => 'Finance', 'hire_date' => '2024-04-15', 'salary' => 24000, 'schedule' => $std],
            ['employee_id' => 'EMP027', 'first_name' => 'Benjie', 'last_name' => 'Castro', 'email' => 'benjie.castro@springboardph.com', 'position' => 'Facilities Associate', 'department' => 'Administration', 'hire_date' => '2023-10-02', 'salary' => 750, 'undeclared_salary' => 16000, 'rate_type' => 'daily', 'schedule' => $std],
            ['employee_id' => 'EMP028', 'first_name' => 'Kim', 'last_name' => 'Fernandez', 'email' => 'kim.fernandez@springboardph.com', 'position' => 'Junior Engineer', 'department' => 'Technology', 'hire_date' => '2024-07-08', 'salary' => 27000, 'schedule' => $std],
        ];
    }
}
