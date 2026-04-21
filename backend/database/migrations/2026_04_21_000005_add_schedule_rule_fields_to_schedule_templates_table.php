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
        Schema::table('schedule_templates', function (Blueprint $table) {
            $table->time('clock_in_start')->nullable()->after('description');
            $table->time('clock_in_end')->nullable()->after('clock_in_start');
            $table->time('clock_out_start')->nullable()->after('clock_in_end');
            $table->time('clock_out_end')->nullable()->after('clock_out_start');
            $table->time('work_start_time')->nullable()->after('clock_out_end');
            $table->time('work_end_time')->nullable()->after('work_start_time');
            $table->integer('late_threshold_minutes')->default(0)->after('work_end_time');
            $table->integer('required_hours_per_day')->default(9)->after('late_threshold_minutes');
            $table->integer('overtime_threshold_hours')->default(9)->after('required_hours_per_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_templates', function (Blueprint $table) {
            $table->dropColumn([
                'clock_in_start',
                'clock_in_end',
                'clock_out_start',
                'clock_out_end',
                'work_start_time',
                'work_end_time',
                'late_threshold_minutes',
                'required_hours_per_day',
                'overtime_threshold_hours',
            ]);
        });
    }
};
