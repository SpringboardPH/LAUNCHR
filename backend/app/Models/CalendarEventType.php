<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEventType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
        'counts_as_absence',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'counts_as_absence' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function events()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
