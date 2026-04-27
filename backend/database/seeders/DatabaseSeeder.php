<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Employee;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@hr.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        // Create HR user
        User::factory()->create([
            'name' => 'HR Manager',
            'email' => 'hr@hr.com',
            'password' => 'password',
            'role' => 'hr',
        ]);

        // Seed employees
        $this->call([
            EmployeeSeeder::class,
            LeaveTypeSeeder::class,
            ScheduleTemplateSeeder::class,
            CalendarEventTypeSeeder::class,
        ]);

        // Create employee users linked to first 2 employees
        $employee1 = Employee::where('employee_id', 'EMP001')->first();
        if ($employee1) {
            $user1 = User::factory()->create([
                'name' => $employee1->full_name,
                'email' => 'maria@company.com',
                'password' => 'password',
                'role' => 'employee',
            ]);
            $employee1->update(['user_id' => $user1->id]);
        }

        $employee2 = Employee::where('employee_id', 'EMP002')->first();
        if ($employee2) {
            $user2 = User::factory()->create([
                'name' => $employee2->full_name,
                'email' => 'juan@company.com',
                'password' => 'password',
                'role' => 'employee',
            ]);
            $employee2->update(['user_id' => $user2->id]);
        }
    }
}
