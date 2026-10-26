<?php

namespace App\Console\Commands;

use App\Helpers\SystemClock;
use App\Models\Payroll;
use App\Models\ThirteenthMonth;
use Illuminate\Console\Command;

class ReleaseThirteenthMonthPayrollMonths extends Command
{
    protected $signature = 'thirteenth-month:release-payroll-months {year=2026}';

    protected $description = 'Drop saved 13th month overrides for months that payroll can rebuild';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $today = SystemClock::today()->toDateString();
        $released = 0;
        $kept = 0;

        $records = ThirteenthMonth::where('year', $year)->where('is_override', true)->get();

        foreach ($records as $record) {
            $hasPayroll = Payroll::where('employee_id', $record->employee_id)
                ->whereYear('cutoff_end', $year)
                ->whereMonth('cutoff_end', $record->month)
                ->whereDate('cutoff_end', '<=', $today)
                ->exists();

            if (!$hasPayroll) {
                $kept++;
                continue;
            }

            $record->delete();
            $released++;
        }

        $this->info("Released {$released} override(s). Kept {$kept} with no payroll.");

        return self::SUCCESS;
    }
}
