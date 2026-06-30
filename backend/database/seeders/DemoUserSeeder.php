<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Employee;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@company.com'],
            ['name' => 'Demo Employee', 'password' => bcrypt('demo12345'), 'role' => 'employee']
        );
        // Reset password each run so the demo credentials always work.
        $user->forceFill(['password' => bcrypt('demo12345'), 'role' => 'employee'])->save();

        $employee = Employee::firstOrCreate(
            ['email' => 'demo@company.com'],
            [
                'employee_id' => 'EMP-DEMO',
                'first_name'  => 'Demo',
                'last_name'   => 'Employee',
                'phone'       => '555-0199',
                'position'    => 'Associate',
                'department'  => 'Technology',
                'hire_date'   => '2024-01-15',
                'salary'      => 30000.00,
                'status'      => 'active',
                'user_id'     => $user->id,
            ]
        );
        $employee->user_id = $user->id;
        $employee->save();

        $this->command->info("Demo user: {$user->email} / demo12345 (role={$user->role}) -> employee #{$employee->id}");
    }
}
