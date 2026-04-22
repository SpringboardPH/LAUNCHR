<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_template_id')->nullable()->after('employee_id');
            $table->string('schedule_template_name')->nullable()->after('schedule_template_id');
            $table->index('schedule_template_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['schedule_template_id']);
            $table->dropColumn(['schedule_template_id', 'schedule_template_name']);
        });
    }
};
