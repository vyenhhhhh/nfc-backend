<?php

namespace App\Http\Controllers;

use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    // GET /api/announcements  -> newest first
    public function index()
    {
        $rows = DB::table('announcements')
            ->leftJoin('users', 'users.id', '=', 'announcements.created_by')
            ->select('announcements.*', 'users.name as author')
            ->orderByDesc('announcements.created_at')
            ->orderByDesc('announcements.id')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    // POST /api/announcements   { user_id, title, body }
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'title'   => 'required|string|max:150',
            'body'    => 'required|string|max:2000',
        ]);

        $poster = DB::table('users')->where('id', $data['user_id'])->first();
        if (!$poster || !in_array($poster->role, ['ojt_coordinator', 'admin', 'supervisor'], true)) {
            return response()->json(['message' => 'Only staff can post announcements.'], 403);
        }

        $id = DB::table('announcements')->insertGetId([
            'title'      => $data['title'],
            'body'       => $data['body'],
            'created_by' => $poster->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Notifier::toRoles(['intern'], 'New announcement', $data['title'], 'announcement');

        return response()->json(['message' => 'Announcement posted.', 'id' => $id], 201);
    }

    // DELETE /api/announcements/{id}
    public function destroy($id)
    {
        DB::table('announcements')->where('id', $id)->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}