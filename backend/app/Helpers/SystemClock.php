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
    private static $settingsCache = null;

    /**
     * Return the virtual "now" as a Carbon instance.
     */
    public static function now(): Carbon
    {
        if (self::$settingsCache === null) {
            $settings = SystemSettings::whereIn('key', ['system_date', 'system_time'])->get();
            self::$settingsCache = [
                'date' => $settings->where('key', 'system_date')->first(),
                'time' => $settings->where('key', 'system_time')->first(),
            ];
        }

        $dateSetting = self::$settingsCache['date'];
        $timeSetting = self::$settingsCache['time'];

        $date = $dateSetting?->value;
        $time = $timeSetting?->value;

        if ($date && $time) {
            $base = Carbon::parse("{$date} {$time}");

            // Keep virtual time moving: advance from the saved baseline
            // by the real elapsed seconds since either date/time was updated.
            $anchorUpdatedAt = $dateSetting?->updated_at;
            if ($timeSetting?->updated_at && (!$anchorUpdatedAt || $timeSetting->updated_at->gt($anchorUpdatedAt))) {
                $anchorUpdatedAt = $timeSetting->updated_at;
            }

            if ($anchorUpdatedAt) {
                $elapsedSeconds = max(0, $anchorUpdatedAt->diffInSeconds(Carbon::now()));
                return $base->copy()->addSeconds($elapsedSeconds);
            }

            return $base;
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
