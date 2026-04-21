<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY leave_type VARCHAR(255) NOT NULL DEFAULT 'vacation'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY leave_type ENUM('vacation', 'sick', 'unpaid', 'maternity') NOT NULL DEFAULT 'vacation'");
    }
};