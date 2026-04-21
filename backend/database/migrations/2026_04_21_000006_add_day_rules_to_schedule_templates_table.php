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
            $table->json('day_rules')->nullable()->after('work_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_templates', function (Blueprint $table) {
            $table->dropColumn('day_rules');
        });
    }
};
