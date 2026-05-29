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
        
        return $setting->value;
    }

    public function getValueAttribute($value)
    {
        return match ($this->type) {
            'boolean' => $value === 'true' || $value === '1' || $value === true,
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    public static function set($key, $value, $description = null, $type = 'string')
    {
        $setting = self::where('key', $key)->first();
        
        if ($type === 'boolean') {
            $value = $value ? 'true' : 'false';
        } elseif ($type === 'array' || $type === 'json') {
            if (!is_string($value)) {
                $value = json_encode($value);
            }
        }

        if ($setting) {
            $setting->update(['value' => $value, 'type' => $type, 'description' => $description]);
        } else {
            self::create(['key' => $key, 'value' => $value, 'type' => $type, 'description' => $description]);
        }

        return self::where('key', $key)->first();
    }
}
