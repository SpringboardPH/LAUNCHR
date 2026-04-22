<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL to update the enum because Blueprint's change() method has issues with ENUMs
        DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN status ENUM('absent', 'late', 'completed', 'incomplete', 'working', 'on_leave') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN status ENUM('absent', 'late', 'completed', 'incomplete') NULL");
    }
};
