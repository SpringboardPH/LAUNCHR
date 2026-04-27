<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'calendar_event_type_id' => $this->calendar_event_type_id,
            'type' => new CalendarEventTypeResource($this->whenLoaded('type')),
            'event_date' => $this->event_date?->format('Y-m-d'),
            'end_date' => ($this->end_date ?? $this->event_date)?->format('Y-m-d'),
            'title' => $this->title,
            'description' => $this->description,
            'color' => $this->getColor(),
            'counts_as_absence' => $this->shouldCountAsAbsence(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
