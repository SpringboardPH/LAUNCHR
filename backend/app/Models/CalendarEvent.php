<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = [
        'calendar_event_type_id',
        'event_date',
        'end_date',
        'title',
        'description',
        'color',
        'counts_as_absence',
        'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'end_date' => 'date',
        'counts_as_absence' => 'boolean',
    ];

    public function type()
    {
        return $this->belongsTo(CalendarEventType::class, 'calendar_event_type_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the effective counts_as_absence value
     * Uses event override if set, otherwise uses type default
     */
    public function shouldCountAsAbsence(): bool
    {
        return $this->counts_as_absence !== null 
            ? $this->counts_as_absence 
            : $this->type->counts_as_absence;
    }

    /**
     * Get the effective color value
     * Uses event override if set, otherwise uses type default
     */
    public function getColor(): string
    {
        return $this->color ?? $this->type->color;
    }
}
