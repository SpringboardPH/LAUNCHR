<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thirteenth_month_employee_settings', function (Blueprint $table) {
            // Months (1..12) excluded from this employee's 13th-month total. Null/[] = all included.
            $table->json('excluded_months')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('thirteenth_month_employee_settings', function (Blueprint $table) {
            $table->dropColumn('excluded_months');
        });
    }
};
