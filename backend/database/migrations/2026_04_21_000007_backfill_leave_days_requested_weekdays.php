<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $leaves = DB::table('leave_requests')->get();

        foreach ($leaves as $leave) {
            $startDate = Carbon::parse($leave->start_date)->startOfDay();
            $endDate = Carbon::parse($leave->end_date)->startOfDay();

            if ($endDate->lt($startDate)) {
                continue;
            }

            $daysRequested = 0;
            $current = $startDate->copy();

            while ($current->lte($endDate)) {
                if ($current->isWeekday()) {
                    $daysRequested++;
                }
                $current->addDay();
            }

            DB::table('leave_requests')
                ->where('id', $leave->id)
                ->update(['days_requested' => $daysRequested]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('leave_requests')->update(['days_requested' => 0]);
    }
};