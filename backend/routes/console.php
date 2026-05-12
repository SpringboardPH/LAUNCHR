<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Models\SystemSettings;
use App\Helpers\SystemClock;
use Illuminate\Support\Facades\Schedule;

Schedule::command('attendance:auto-clock-out')->everyMinute()->when(function () {
    $time = SystemSettings::where('key', 'absent_marking_time')->value('value') ?? '23:59';
    // Ensure we match HH:MM using simulation-aware time
    return SystemClock::now()->format('H:i') === substr($time, 0, 5);
});

Schedule::command('attendance:mark-absent')->everyMinute()->when(function () {
    $time = SystemSettings::where('key', 'absent_marking_time')->value('value') ?? '23:59';
    // Ensure we match HH:MM using simulation-aware time
    return SystemClock::now()->format('H:i') === substr($time, 0, 5);
});

