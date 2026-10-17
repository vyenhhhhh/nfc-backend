<?php
//NotificationController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // GET /api/notifications  -> the signed-in user's own, newest first, last 50
    public function index(Request $request)
    {
        $uid = $request->attributes->get('auth_user')->id;

        $rows = DB::table('notifications')
            ->where('user_id', $uid)
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

    // PATCH /api/notifications/{id}/read  (only your own)
    public function markRead(Request $request, $id)
    {
        $uid = $request->attributes->get('auth_user')->id;

        DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }
        // DELETE /api/notifications/{id}  (only your own)
    public function destroy(Request $request, $id)
    {
        $uid = $request->attributes->get('auth_user')->id;

        DB::table('notifications')->where('id', $id)->where('user_id', $uid)->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }

    // DELETE /api/notifications  (clear all of your own)
    public function clear(Request $request)
    {
        $uid = $request->attributes->get('auth_user')->id;

        DB::table('notifications')->where('user_id', $uid)->delete();

        return response()->json(['message' => 'Notifications cleared.']);
    }
}