<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CalendarController extends Controller
{
    // Get all calendar events
    public function index()
    {
        return response()->json(
            DB::table('calendar_events')
                ->orderBy('date')
                ->get()
        );
    }

    // Add a calendar event
    public function store(Request $request)
    {
        $request->validate([
            'date'        => 'required|date',
            'type'        => 'required|in:holiday,non_working',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'created_by'  => 'nullable|integer',
        ]);

        $existing = DB::table('calendar_events')
            ->where('date', $request->date)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'There is already an event on this date.'
            ], 409);
        }

        $id = DB::table('calendar_events')->insertGetId([
            'date'        => $request->date,
            'type'        => $request->type,
            'title'       => $request->title,
            'description' => $request->description,
            'created_by'  => $request->created_by,
            'created_at'  => Carbon::now('Asia/Manila'),
            'updated_at'  => Carbon::now('Asia/Manila'),
        ]);

        return response()->json([
            'message' => 'Calendar event added successfully.',
            'id'      => $id,
        ]);
    }

    // Update a calendar event
    public function update(Request $request, $id)
    {
        $request->validate([
            'date'        => 'required|date',
            'type'        => 'required|in:holiday,non_working',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $event = DB::table('calendar_events')
            ->where('id', $id)
            ->first();

        if (!$event) {
            return response()->json([
                'message' => 'Calendar event not found.'
            ], 404);
        }

        $existing = DB::table('calendar_events')
            ->where('date', $request->date)
            ->where('id', '!=', $id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'There is already an event on this date.'
            ], 409);
        }

        DB::table('calendar_events')
            ->where('id', $id)
            ->update([
                'date'        => $request->date,
                'type'        => $request->type,
                'title'       => $request->title,
                'description' => $request->description,
                'updated_at'  => Carbon::now('Asia/Manila'),
            ]);

        return response()->json([
            'message' => 'Calendar event updated successfully.'
        ]);
    }

    // Delete a calendar event
    public function destroy($id)
    {
        $event = DB::table('calendar_events')
            ->where('id', $id)
            ->first();

        if (!$event) {
            return response()->json([
                'message' => 'Calendar event not found.'
            ], 404);
        }

        DB::table('calendar_events')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'message' => 'Calendar event deleted successfully.'
        ]);
    }
}