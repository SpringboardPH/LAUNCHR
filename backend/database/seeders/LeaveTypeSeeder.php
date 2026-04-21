<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'vacation', 'name' => 'Vacation Leave', 'default_days' => 15, 'requires_balance' => true, 'is_active' => true],
            ['code' => 'sick', 'name' => 'Sick Leave', 'default_days' => 10, 'requires_balance' => true, 'is_active' => true],
            ['code' => 'unpaid', 'name' => 'Unpaid Leave', 'default_days' => 0, 'requires_balance' => false, 'is_active' => true],
            ['code' => 'maternity', 'name' => 'Maternity Leave', 'default_days' => 60, 'requires_balance' => true, 'is_active' => true],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['description' => null]
            );
        }
    }
}