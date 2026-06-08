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
        User::updateOrCreate(
            ['email' => 'michaelaaronluyun@gmail.com'],
            [
                'name' => 'Michael Aaron Luyun',
                'password' => bcrypt('password'), // Ensure password is hashed
                'role' => 'admin',
            ]
        );

        // Seed essential data
        $this->call([
            DepartmentSeeder::class,
            SystemSettingsSeeder::class,
            LeaveTypeSeeder::class,
            ScheduleTemplateSeeder::class,
            CalendarEventTypeSeeder::class,
        ]);
    }
}
