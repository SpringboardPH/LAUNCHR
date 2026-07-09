<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE employee_requests MODIFY request_type ENUM('overtime','half_day','undertime','concern','schedule_change','coe','other','cash_advance','company_loan') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE employee_requests MODIFY request_type ENUM('overtime','half_day','undertime','concern','schedule_change','coe','other') NOT NULL");
    }
};
