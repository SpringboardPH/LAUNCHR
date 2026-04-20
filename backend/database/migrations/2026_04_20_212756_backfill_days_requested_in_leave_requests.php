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
        // Get all leave requests and calculate days_requested
        $leaves = DB::table('leave_requests')->get();
        
        foreach ($leaves as $leave) {
            $startDate = Carbon::parse($leave->start_date);
            $endDate = Carbon::parse($leave->end_date);
            $daysRequested = $endDate->diffInDays($startDate) + 1;
            
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
