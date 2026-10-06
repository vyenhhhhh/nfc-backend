<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // GET /api/notifications?user_id=3
    public function index(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);

        $rows = DB::table('notifications')
            ->where('user_id', $request->query('user_id'))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($rows);
    }

    // PATCH /api/notifications/{id}/read
    public function markRead($id)
    {
        $updated = DB::table('notifications')
            ->where('id', $id)
            ->update(['is_read' => true, 'updated_at' => now()]);

        if (!$updated) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['message' => 'Marked as read.']);
    }
}