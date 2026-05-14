<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Models\SystemSettings;
use App\Helpers\SystemClock;
use Illuminate\Support\Facades\Schedule;

// Auto clock-out at 23:59 daily
Schedule::command('attendance:auto-clock-out')->dailyAt('23:59');

// Mark absent at 00:00 daily (after auto clock-out)
Schedule::command('attendance:mark-absent')->dailyAt('00:00');

