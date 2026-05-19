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
            // Add separate note fields for clock-in and clock-out
            $table->text('clock_in_notes')->nullable()->after('notes');
            $table->text('clock_out_notes')->nullable()->after('clock_in_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['clock_in_notes', 'clock_out_notes']);
        });
    }
};
