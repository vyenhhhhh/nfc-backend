<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // GET /api/notifications?user_id=1  -> newest first, last 50
    public function index(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);

        $rows = DB::table('notifications')
            ->where('user_id', $request->user_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function ($n) {
                $n->is_read = (bool) $n->is_read;
                return $n;
            });

        return response()->json($rows);
    }

    // PATCH /api/notifications/{id}/read
    public function markRead($id)
    {
        DB::table('notifications')->where('id', $id)->update(['is_read' => true, 'updated_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }
}