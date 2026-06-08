<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employees = [
            [
                'employee_id' => 'EMP001',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'email' => 'maria@company.com',
                'phone' => '555-0101',
                'position' => 'Manager',
                'department' => 'Human Resources',
                'hire_date' => '2022-01-15',
                'salary' => 45000.00,
                'undeclared_salary' => 40000.00,
                'status' => 'active',
                'notes' => 'Senior HR Manager',
            ],
            [
                'employee_id' => 'EMP002',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'email' => 'juan@company.com',
                'phone' => '555-0102',
                'position' => 'Developer',
                'department' => 'Technology',
                'hire_date' => '2023-03-20',
                'salary' => 38000.00,
                'status' => 'active',
                'notes' => 'Full Stack Developer',
            ],
            [
                'employee_id' => 'EMP003',
                'first_name' => 'Ana',
                'last_name' => 'Garcia',
                'email' => 'ana@company.com',
                'phone' => '555-0103',
                'position' => 'Accountant',
                'department' => 'Finance',
                'hire_date' => '2021-06-10',
                'salary' => 35000.00,
                'status' => 'active',
                'notes' => 'Senior Accountant',
            ],
            [
                'employee_id' => 'EMP004',
                'first_name' => 'Pedro',
                'last_name' => 'Lopez',
                'email' => 'pedro@company.com',
                'phone' => '555-0104',
                'position' => 'Sales Executive',
                'department' => 'Sales',
                'hire_date' => '2023-01-05',
                'salary' => 32000.00,
                'status' => 'active',
                'notes' => 'Regional Sales Lead',
            ],
            [
                'employee_id' => 'EMP005',
                'first_name' => 'Rosa',
                'last_name' => 'Reyes',
                'email' => 'rosa@company.com',
                'phone' => '555-0105',
                'position' => 'Administrative Assistant',
                'department' => 'Administration',
                'hire_date' => '2022-09-12',
                'salary' => 25000.00,
                'status' => 'active',
                'notes' => 'Office Manager',
            ],
        ];

        foreach ($employees as $employee) {
            Employee::create($employee);
        }
    }
}
