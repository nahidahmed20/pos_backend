<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = CalendarEvent::where('user_id', $request->user()->id)->orderBy('start_time', 'asc')->get();

        return response()->json(['data' => $events]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);

        $event = CalendarEvent::create([
            ...$data,
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
        ]);

        return response()->json(['data' => $event], 201);
    }

    public function show(Request $request, int $calendarEvent): JsonResponse
    {
        $item = CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent);

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, int $calendarEvent): JsonResponse
    {
        $item = CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'start_time' => ['sometimes', 'required', 'date'],
            'end_time' => ['sometimes', 'required', 'date'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, int $calendarEvent): JsonResponse
    {
        $item = CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent);
        $item->delete();

        return response()->json(['message' => 'Calendar event deleted successfully.']);
    }
}
