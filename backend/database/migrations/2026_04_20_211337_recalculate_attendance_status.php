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
        // Get all attendance logs and calculate their status
        $logs = DB::table('attendance_logs')->get();
        
        foreach ($logs as $log) {
            $status = $this->calculateStatus($log->clock_in_time, $log->clock_out_time);
            
            DB::table('attendance_logs')
                ->where('id', $log->id)
                ->update(['status' => $status]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('attendance_logs')->update(['status' => null]);
    }
    
    /**
     * Calculate status based on clock times
     */
    private function calculateStatus($clockInTime, $clockOutTime)
    {
        if (!$clockInTime) {
            return 'absent';
        }
        
        if (!$clockOutTime) {
            return 'absent';
        }

        // Parse times
        [$inH, $inM, $inS] = sscanf($clockInTime, '%d:%d:%d');
        [$outH, $outM, $outS] = sscanf($clockOutTime, '%d:%d:%d');
        
        $inMinutes = $inH * 60 + $inM;
        $outMinutes = $outH * 60 + $outM;
        
        // Work start time is 9 AM (540 minutes)
        $workStartMinutes = 9 * 60;
        
        // Check if late (after 9:00 AM)
        $isLate = $inMinutes > $workStartMinutes;
        
        // Calculate hours worked
        $diffMinutes = $outMinutes - $inMinutes;
        $hoursWorked = $diffMinutes / 60;
        
        // Determine status
        if ($isLate) {
            return 'late';
        } elseif ($hoursWorked >= 9) {
            return 'completed';
        } else {
            return 'incomplete';
        }
    }
};
