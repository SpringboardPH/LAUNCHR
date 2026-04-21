<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSettings extends Model
{
    protected $table = 'system_settings';
    protected $fillable = ['key', 'value', 'description', 'type'];
    public $timestamps = true;

    public static function get($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }
        
        return match ($setting->type) {
            'boolean' => $setting->value === 'true' || $setting->value === '1',
            'integer' => (int) $setting->value,
            'decimal' => (float) $setting->value,
            'array', 'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set($key, $value, $description = null, $type = 'string')
    {
        $setting = self::where('key', $key)->first();
        
        if ($type === 'boolean') {
            $value = $value ? 'true' : 'false';
        } elseif ($type === 'array' || $type === 'json') {
            $value = json_encode($value);
        }

        if ($setting) {
            $setting->update(['value' => $value, 'type' => $type, 'description' => $description]);
        } else {
            self::create(['key' => $key, 'value' => $value, 'type' => $type, 'description' => $description]);
        }

        return self::where('key', $key)->first();
    }
}
