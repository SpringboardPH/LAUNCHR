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
        Schema::table('calendar_events', function (Blueprint $blueprint) {
            $blueprint->date('end_date')->nullable()->after('event_date');
        });

        // Backfill end_date with event_date
        DB::table('calendar_events')->update([
            'end_date' => DB::raw('event_date')
        ]);
        
        // Now make it non-nullable if we want, but keeping it nullable is fine 
        // as long as we treat null as single day in logic.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });
    }
};
