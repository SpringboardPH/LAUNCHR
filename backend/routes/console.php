<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Schedule::command('attendance:auto-clock-out')->everyFifteenMinutes();
Schedule::command('attendance:mark-absent')->dailyAt('23:59');

