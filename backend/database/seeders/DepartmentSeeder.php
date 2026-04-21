<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Human Resources', 'description' => 'HR and Personnel Management'],
            ['name' => 'Technology', 'description' => 'IT and Software Development'],
            ['name' => 'Finance', 'description' => 'Accounting and Financial Management'],
            ['name' => 'Sales', 'description' => 'Sales and Business Development'],
            ['name' => 'Administration', 'description' => 'General Administration'],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(
                ['name' => $dept['name']],
                ['description' => $dept['description']]
            );
        }
    }
}
