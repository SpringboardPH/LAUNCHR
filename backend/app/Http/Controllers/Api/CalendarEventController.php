<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\LeaveRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarEventController extends Controller
{
    /**
     * Get calendar events, optionally filtered by date range
     * All authenticated users can view events
     *
     * Query parameters:
     * - start_date: YYYY-MM-DD (optional)
     * - end_date: YYYY-MM-DD (optional)
     */
    public function index(Request $request)
    {
        $query = CalendarEvent::query()->with('type');

        // Filter by date range if provided
        if ($request->has('start_date')) {
            $startDate = $request->query('start_date');
            $query->where('event_date', '>=', $startDate);
        }

        if ($request->has('end_date')) {
            $endDate = $request->query('end_date');
            $query->where('event_date', '<=', $endDate);
        }

        $events = $query->orderBy('event_date')->get();
        $data = CalendarEventResource::collection($events)->toArray($request);

        // --- Include Approved Leaves ---
        $leaveQuery = LeaveRequest::where('status', 'approved')->with('employee');

        // Filtering by role
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            // Employee only sees their own leaves
            $employee = Employee::where('user_id', $request->user()->id)->first();
            if ($employee) {
                $leaveQuery->where('employee_id', $employee->id);
            } else {
                // If user is not an employee (unlikely), they see no leaves
                $leaveQuery->where('id', 0);
            }
        }

        if ($request->has('start_date')) {
            $leaveQuery->where('end_date', '>=', $request->query('start_date'));
        }
        if ($request->has('end_date')) {
            $leaveQuery->where('start_date', '<=', $request->query('end_date'));
        }

        $leaves = $leaveQuery->get();
        
        foreach ($leaves as $leave) {
            $title = $leave->leaveType ? $leave->leaveType->name : 'Leave';
            if (in_array($request->user()->role, ['admin', 'hr'])) {
                $title = ($leave->employee ? $leave->employee->first_name : 'Employee') . " (" . $title . ")";
            }

            $data[] = [
                'id' => 'leave-' . $leave->id,
                'is_leave' => true,
                'calendar_event_type_id' => null,
                'type' => [
                    'name' => 'Leave',
                    'color' => '#000000', // black
                    'counts_as_absence' => true
                ],
                'event_date' => $leave->start_date->format('Y-m-d'),
                'end_date' => $leave->end_date->format('Y-m-d'),
                'title' => $title,
                'description' => $leave->reason,
                'color' => '#000000',
                'counts_as_absence' => true,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Calendar items retrieved',
        ]);
    }

    /**
     * Create a new calendar event
     * Only HR and Admin can create
     */
    public function store(Request $request)
    {
        // Check authorization
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only HR and admin can create events',
            ], 403);
        }

        $validated = $request->validate([
            'calendar_event_type_id' => 'required|exists:calendar_event_types,id',
            'event_date' => 'required|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:event_date',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
        ]);

        $event = CalendarEvent::create([
            'calendar_event_type_id' => $validated['calendar_event_type_id'],
            'event_date' => $validated['event_date'],
            'end_date' => $validated['end_date'] ?? $validated['event_date'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $type = \App\Models\CalendarEventType::find($validated['calendar_event_type_id']);
        if ($type && $type->is_recurring_annual) {
            $startDate = \Illuminate\Support\Carbon::parse($validated['event_date']);
            $endDate = $validated['end_date'] ? \Illuminate\Support\Carbon::parse($validated['end_date']) : $startDate;
            $duration = $startDate->diffInDays($endDate);

            for ($i = 1; $i <= 10; $i++) {
                $nextStartDate = $startDate->copy()->addYears($i);
                $nextEndDate = $nextStartDate->copy()->addDays($duration);

                CalendarEvent::create([
                    'calendar_event_type_id' => $validated['calendar_event_type_id'],
                    'event_date' => $nextStartDate->format('Y-m-d'),
                    'end_date' => $nextEndDate->format('Y-m-d'),
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        // Load the relation for the resource
        $event->load('type');

        \App\Models\AuditLog::log(
            'calendar_event_created',
            "Created calendar event: {$event->title} on {$event->event_date}",
            $event,
            null,
            $event->toArray()
        );

        return response()->json([
            'success' => true,
            'data' => new CalendarEventResource($event),
            'message' => 'Calendar event created successfully',
        ], 201);
    }

    /**
     * Get a specific calendar event
     */
    public function show(Request $request, CalendarEvent $calendarEvent)
    {
        $calendarEvent->load('type');

        return response()->json([
            'success' => true,
            'data' => new CalendarEventResource($calendarEvent),
            'message' => 'Calendar event retrieved',
        ]);
    }

    /**
     * Update a calendar event
     * Only HR and Admin can update
     */
    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        // Check authorization
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only HR and admin can update events',
            ], 403);
        }

        $validated = $request->validate([
            'calendar_event_type_id' => 'sometimes|exists:calendar_event_types,id',
            'event_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:1000',
            'update_scope' => 'sometimes|in:single,future,all',
        ]);

        // Extract update_scope before updating the model
        $updateScope = $validated['update_scope'] ?? 'single';
        unset($validated['update_scope']);

        // Determine which events to update
        $eventsToUpdate = collect();
        
        if ($updateScope === 'single') {
            // Update only this event
            $eventsToUpdate->push($calendarEvent);
        } else {
            // For 'future' and 'all', we need to find related events by title and type
            $query = CalendarEvent::where('title', $calendarEvent->title)
                ->where('calendar_event_type_id', $calendarEvent->calendar_event_type_id);
            
            if ($updateScope === 'future') {
                // This event and all future occurrences
                $query->where('event_date', '>=', $calendarEvent->event_date);
            }
            
            $eventsToUpdate = $query->get();
            \Log::info("Number of events to update for scope {$updateScope}: " . $eventsToUpdate->count());
        }

        // Update all selected events
        foreach ($eventsToUpdate as $event) {
            $originalEventDate = \Carbon\Carbon::parse($event->event_date)->startOfDay();
            $originalEndDate = $event->end_date ? \Carbon\Carbon::parse($event->end_date)->startOfDay() : $originalEventDate;
            
            // Calculate the original duration of this instance in days
            $originalDuration = $originalEventDate->diffInDays($originalEndDate);

            $currentUpdates = $validated;

            if (isset($validated['event_date'])) {
                $newEventDate = \Carbon\Carbon::parse($validated['event_date'])->startOfDay();

                // Determine the new duration from the submitted dates.
                // If the user supplied an end_date use it, otherwise fall back to
                // the original duration so single-day events stay single-day.
                $newEndDateSubmitted = isset($validated['end_date'])
                    ? \Carbon\Carbon::parse($validated['end_date'])->startOfDay()
                    : null;

                $newDuration = $newEndDateSubmitted !== null
                    ? $newEventDate->diffInDays($newEndDateSubmitted)   // may be 0 for same-day
                    : $originalDuration;

                if ($updateScope !== 'single') {
                    // For future/all: shift each instance's month/day to match the
                    // submitted event_date, preserving only the year of each instance.
                    $newMonth = $newEventDate->month;
                    $newDay   = $newEventDate->day;

                    $shiftedEventDate = $originalEventDate->copy()->month($newMonth)->day($newDay);
                    $currentUpdates['event_date'] = $shiftedEventDate->format('Y-m-d');
                    // Apply the new duration (from submitted dates) to every instance
                    $currentUpdates['end_date'] = $shiftedEventDate->copy()->addDays($newDuration)->format('Y-m-d');
                } else {
                    // Single scope: use exactly what was submitted
                    $currentUpdates['event_date'] = $newEventDate->format('Y-m-d');
                    $currentUpdates['end_date'] = $newEndDateSubmitted !== null
                        ? $newEndDateSubmitted->format('Y-m-d')
                        : $newEventDate->copy()->addDays($originalDuration)->format('Y-m-d');
                }
            } elseif (isset($validated['end_date']) && $updateScope !== 'single') {
                // Date not changed but end_date was: apply the new end_date offset to every instance.
                $newEndDateSubmitted = \Carbon\Carbon::parse($validated['end_date'])->startOfDay();
                $submittedStart = \Carbon\Carbon::parse($calendarEvent->event_date)->startOfDay();
                $newDuration = $submittedStart->diffInDays($newEndDateSubmitted);
                $currentUpdates['end_date'] = $originalEventDate->copy()->addDays($newDuration)->format('Y-m-d');
            }
            
            \Log::info("Updating event ID {$event->id} with data:", $currentUpdates);
            $result = $event->update($currentUpdates);
            \Log::info("Update result for event ID {$event->id}: " . ($result ? 'success' : 'failure'));
        }
        
        $calendarEvent->load('type');
        
        return response()->json([
            'success' => true,
            'data' => new CalendarEventResource($calendarEvent),
            'message' => 'Calendar event' . ($updateScope !== 'single' ? 's' : '') . ' updated successfully',
        ]);
    }

    /**
     * Delete a calendar event
     * Only HR and Admin can delete
     */
    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        // Check authorization
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only HR and admin can delete events',
            ], 403);
        }

        $validated = $request->validate([
            'delete_scope' => 'sometimes|in:single,future,all',
        ]);

        $deleteScope = $validated['delete_scope'] ?? 'single';

        if ($deleteScope === 'single') {
            $calendarEvent->delete();
        } else {
            // For 'future' and 'all', we need to find related events by title and type
            $query = CalendarEvent::where('title', $calendarEvent->title)
                ->where('calendar_event_type_id', $calendarEvent->calendar_event_type_id);
            
            if ($deleteScope === 'future') {
                // This event and all future occurrences
                $query->where('event_date', '>=', $calendarEvent->event_date);
            }
            // For 'all', get all events with same title and type (no date filter)
            
            $query->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Calendar event' . ($deleteScope !== 'single' ? 's' : '') . ' deleted successfully',
        ]);
    }

    /**
     * Import calendar events from CSV or JSON file
     * Only HR and Admin can import
     * 
     * Expected CSV format: date,title,type_name,description (header required)
     * Expected JSON format: [{ "date": "YYYY-MM-DD", "title": "...", "type_name": "...", "description": "..." }, ...]
     */
    public function import(Request $request)
    {
        // Check authorization
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only HR and admin can import events',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,json',
        ]);

        $file = $request->file('file');
        $fileContent = file_get_contents($file->getPathname());
        $holidays = [];
        $errors = [];

        // Determine file type and parse
        if ($file->getClientOriginalExtension() === 'json') {
            try {
                $holidays = json_decode($fileContent, true);
                if (!is_array($holidays)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid JSON format. Expected array of holidays.',
                    ], 422);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to parse JSON file: ' . $e->getMessage(),
                ], 422);
            }
        } else {
            // Parse CSV
            $lines = array_filter(explode("\n", $fileContent));
            if (empty($lines)) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV file is empty',
                ], 422);
            }

            // Skip header row
            array_shift($lines);

            foreach ($lines as $lineNum => $line) {
                $fields = array_map('trim', str_getcsv($line));
                if (count($fields) >= 2) {
                    $holidays[] = [
                        'date' => $fields[0],
                        'title' => $fields[1],
                        'type_name' => $fields[2] ?? 'Holiday',
                        'description' => $fields[3] ?? null,
                        'is_recurring' => isset($fields[4]) && strtolower($fields[4]) === 'yes',
                    ];
                }
            }
        }

        if (empty($holidays)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid holiday entries found in file',
            ], 422);
        }

        // Get default holiday type or create one
        $defaultType = \App\Models\CalendarEventType::where('name', 'Holiday')->first();
        if (!$defaultType) {
            $defaultType = \App\Models\CalendarEventType::create([
                'name' => 'Holiday',
                'description' => 'Company holiday',
                'color' => '#FF6B6B',
                'counts_as_absence' => false,
                'is_recurring_annual' => false,
                'created_by' => $request->user()->id,
            ]);
        }

        $created = 0;
        $skipped = 0;

        // Bulk create events
        foreach ($holidays as $index => $holiday) {
            try {
                // Validate required fields
                if (empty($holiday['date']) || empty($holiday['title'])) {
                    $errors[] = "Row " . ($index + 1) . ": Missing required fields (date or title)";
                    $skipped++;
                    continue;
                }

                // Validate date format
                $date = Carbon::createFromFormat('Y-m-d', $holiday['date']);
                if (!$date) {
                    $errors[] = "Row " . ($index + 1) . ": Invalid date format. Use YYYY-MM-DD";
                    $skipped++;
                    continue;
                }

                $isRecurring = $holiday['is_recurring'] ?? false;

                // Find, create, or update event type with recurrence flag
                $type = $defaultType;
                if (!empty($holiday['type_name'])) {
                    $type = \App\Models\CalendarEventType::where('name', $holiday['type_name'])->first();
                    if (!$type) {
                        $type = \App\Models\CalendarEventType::create([
                            'name' => $holiday['type_name'],
                            'description' => '',
                            'color' => '#FF6B6B',
                            'counts_as_absence' => false,
                            'is_recurring_annual' => $isRecurring,
                            'created_by' => $request->user()->id,
                        ]);
                    } else {
                        // Update the type's recurring flag if this import says it should be
                        if ($isRecurring && !$type->is_recurring_annual) {
                            $type->update(['is_recurring_annual' => true]);
                        }
                    }
                } else {
                    // Using default type - update if this entry is recurring
                    if ($isRecurring && !$type->is_recurring_annual) {
                        $type->update(['is_recurring_annual' => true]);
                    }
                }

                // Create events for 10 years if type is recurring, otherwise just one year
                $startYear = (int) $date->format('Y');
                $endYear = $type->is_recurring_annual ? $startYear + 10 : $startYear + 1;

                for ($year = $startYear; $year < $endYear; $year++) {
                    $eventDate = Carbon::createFromDate($year, $date->format('m'), $date->format('d'));
                    
                    // Check if event already exists for this date
                    $exists = CalendarEvent::where('event_date', $eventDate->format('Y-m-d'))
                        ->where('title', $holiday['title'])
                        ->exists();

                    if (!$exists) {
                        CalendarEvent::create([
                            'calendar_event_type_id' => $type->id,
                            'event_date' => $eventDate->format('Y-m-d'),
                            'end_date' => $eventDate->format('Y-m-d'),
                            'title' => $holiday['title'],
                            'description' => $holiday['description'] ?? null,
                            'created_by' => $request->user()->id,
                        ]);
                        $created++;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'total' => count($holidays),
                'errors' => $errors,
            ],
            'message' => "Imported $created holiday(ies). Skipped $skipped.",
        ]);
    }

    /**
     * Export calendar events to CSV
     * Only HR and Admin can export
     */
    public function export(Request $request)
    {
        // Check authorization
        if (!in_array($request->user()->role, ['admin', 'hr'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only HR and admin can export events',
            ], 403);
        }

        $query = CalendarEvent::with('type')->orderBy('event_date');

        // Filter by date range if provided
        if ($request->has('start_date')) {
            $query->where('event_date', '>=', $request->query('start_date'));
        }
        if ($request->has('end_date')) {
            $query->where('event_date', '<=', $request->query('end_date'));
        }

        $events = $query->get();

        // Group recurring events by title to only export the earliest occurrence
        $seenRecurring = [];
        $eventsToExport = [];
        
        foreach ($events as $event) {
            if ($event->type && $event->type->is_recurring_annual) {
                // Only include the first occurrence of each recurring holiday
                if (!isset($seenRecurring[$event->title])) {
                    $seenRecurring[$event->title] = true;
                    $eventsToExport[] = $event;
                }
            } else {
                // Include all non-recurring events
                $eventsToExport[] = $event;
            }
        }

        // Generate CSV content
        $csv = "date,title,type_name,description,is_recurring\n";
        foreach ($eventsToExport as $event) {
            $date = $event->event_date->format('Y-m-d');
            $title = str_replace('"', '""', $event->title); // Escape quotes
            $typeName = $event->type ? str_replace('"', '""', $event->type->name) : 'Holiday';
            $description = str_replace('"', '""', $event->description ?? '');
            $isRecurring = ($event->type && $event->type->is_recurring_annual) ? 'yes' : 'no';

            $csv .= "\"$date\",\"$title\",\"$typeName\",\"$description\",\"$isRecurring\"\n";
        }

        // Return CSV file download
        return response(
            $csv,
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="holidays_' . now()->format('Y-m-d_His') . '.csv"',
            ]
        );
    }
}