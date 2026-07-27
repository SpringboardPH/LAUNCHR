<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('clock_in_lat', 10, 7)->nullable()->after('clock_in_notes');
            $table->decimal('clock_in_lng', 10, 7)->nullable()->after('clock_in_lat');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['clock_in_lat', 'clock_in_lng']);
        });
    }
};
