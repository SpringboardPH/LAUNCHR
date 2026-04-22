<?php

namespace App\Helpers;

use App\Models\SystemSettings;
use Carbon\Carbon;

/**
 * SystemClock – resolves "now" and "today" using the admin-configured
 * system_date / system_time settings.  Falls back to real wall-clock time
 * when no override is set.
 */
class SystemClock
{
    /**
     * Return the virtual "now" as a Carbon instance.
     */
    public static function now(): Carbon
    {
        $date = SystemSettings::where('key', 'system_date')->value('value');
        $time = SystemSettings::where('key', 'system_time')->value('value');

        if ($date && $time) {
            return Carbon::parse("{$date} {$time}");
        }

        if ($date) {
            return Carbon::parse("{$date} " . Carbon::now()->format('H:i:s'));
        }

        return Carbon::now();
    }

    /**
     * Return the virtual "today" (start of day) as a Carbon instance.
     */
    public static function today(): Carbon
    {
        return static::now()->startOfDay();
    }

    /**
     * Return just the virtual current time string (HH:MM:SS).
     */
    public static function timeString(): string
    {
        return static::now()->toTimeString();
    }

    /**
     * Return just the virtual current date string (YYYY-MM-DD).
     */
    public static function dateString(): string
    {
        return static::today()->toDateString();
    }
}
