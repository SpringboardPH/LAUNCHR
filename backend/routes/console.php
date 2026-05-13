<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Models\SystemSettings;
use App\Helpers\SystemClock;
use Illuminate\Support\Facades\Schedule;

// Auto clock-out runs at 23:59 PM (11:59 PM) daily - BEFORE mark absent
// Uses SystemClock to respect time simulation in testing
Schedule::command('attendance:auto-clock-out')->everyMinute()->when(function () {
    return SystemClock::now()->format('H:i') === '23:59';
});

// Mark absent runs at 00:00 (12:00 AM) daily - AFTER auto clock-out
// Uses SystemClock to respect time simulation in testing
Schedule::command('attendance:mark-absent')->everyMinute()->when(function () {
    return SystemClock::now()->format('H:i') === '00:00';
});

