<?php

namespace Database\Seeders;

use App\Models\CalendarEventType;
use App\Models\User;
use Illuminate\Database\Seeder;

class CalendarEventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $adminId = $admin ? $admin->id : 1;

        $types = [
            [
                'name' => 'Regular Holiday',
                'description' => 'Mandatory non-working day with 100% pay.',
                'color' => '#ef4444', // red-500
                'counts_as_absence' => false,
                'created_by' => $adminId,
            ],
            [
                'name' => 'Special Non-Working Day',
                'description' => 'Non-working day, usually 30% premium if worked.',
                'color' => '#f97316', // orange-500
                'counts_as_absence' => false,
                'created_by' => $adminId,
            ],
            [
                'name' => 'Company Event',
                'description' => 'Team building, town halls, or parties.',
                'color' => '#8b5cf6', // purple-500
                'counts_as_absence' => true,
                'created_by' => $adminId,
            ],
            [
                'name' => 'Training / Seminar',
                'description' => 'Professional development sessions.',
                'color' => '#06b6d4', // cyan-500
                'counts_as_absence' => true,
                'created_by' => $adminId,
            ],
            [
                'name' => 'Deadline / Milestone',
                'description' => 'Important project dates.',
                'color' => '#10b981', // emerald-500
                'counts_as_absence' => true,
                'created_by' => $adminId,
            ],
        ];

        foreach ($types as $type) {
            CalendarEventType::updateOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
