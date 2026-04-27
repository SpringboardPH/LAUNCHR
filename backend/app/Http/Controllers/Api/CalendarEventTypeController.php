<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventTypeResource;
use App\Models\CalendarEventType;
use Illuminate\Http\Request;

class CalendarEventTypeController extends Controller
{
/**
 * Get all calendar event types
 * All authenticated users can access this for the calendar legend
 */
public function index(Request $request)
{
    $query = CalendarEventType::query();

    // Only admin/hr can see inactive types if they ask
    if ($request->query('include_inactive') && in_array($request->user()->role, ['admin', 'hr'])) {
        // Keep all
    } else {
        $query->where('is_active', true);
    }

    $types = $query->orderBy('name')->get();

    return response()->json([
        'success' => true,
        'data' => CalendarEventTypeResource::collection($types),
        'message' => 'Calendar event types retrieved',
    ]);
}
    /**
     * Create a new calendar event type
     * Only admin can create
     */
    public function store(Request $request)
    {
        // Check authorization
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only admins can create event types',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:calendar_event_types,name',
            'description' => 'nullable|string|max:1000',
            'color' => 'required|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'counts_as_absence' => 'required|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $type = CalendarEventType::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'],
            'counts_as_absence' => $validated['counts_as_absence'],
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => new CalendarEventTypeResource($type),
            'message' => 'Calendar event type created successfully',
        ], 201);
    }

    /**
     * Get a specific event type
     */
    public function show(Request $request, CalendarEventType $calendarEventType)
    {
        // Check authorization
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only admins can view event types',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new CalendarEventTypeResource($calendarEventType),
            'message' => 'Calendar event type retrieved',
        ]);
    }

    /**
     * Update a calendar event type
     * Only admin can update
     */
    public function update(Request $request, CalendarEventType $calendarEventType)
    {
        // Check authorization
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only admins can update event types',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:calendar_event_types,name,' . $calendarEventType->id,
            'description' => 'nullable|string|max:1000',
            'color' => 'sometimes|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'counts_as_absence' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $calendarEventType->update($validated);

        return response()->json([
            'success' => true,
            'data' => new CalendarEventTypeResource($calendarEventType),
            'message' => 'Calendar event type updated successfully',
        ]);
    }

    /**
     * Delete a calendar event type
     * Only admin can delete
     */
    public function destroy(Request $request, CalendarEventType $calendarEventType)
    {
        // Check authorization
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only admins can delete event types',
            ], 403);
        }

        $calendarEventType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Calendar event type deleted successfully',
        ]);
    }
}
