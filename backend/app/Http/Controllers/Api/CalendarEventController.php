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

        // Load the relation for the resource
        $event->load('type');

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
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:event_date',
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:1000',
        ]);

        $calendarEvent->update($validated);
        
        // If event_date was updated but end_date wasn't, ensure end_date >= event_date
        if ($calendarEvent->end_date && $calendarEvent->event_date->gt($calendarEvent->end_date)) {
            $calendarEvent->update(['end_date' => $calendarEvent->event_date]);
        }
        $calendarEvent->load('type');

        return response()->json([
            'success' => true,
            'data' => new CalendarEventResource($calendarEvent),
            'message' => 'Calendar event updated successfully',
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

        $calendarEvent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Calendar event deleted successfully',
        ]);
    }
}
