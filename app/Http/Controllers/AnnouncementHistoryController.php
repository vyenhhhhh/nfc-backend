<?php
//AnnouncementHistoryController.php
namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Removing an announcement moves it to announcement_history (interns stop seeing it),
 * so the coordinator keeps a record. From the history it can be deleted for good.
 */
class AnnouncementHistoryController extends Controller
{
    // DELETE /api/announcements/{id}  -> take it down and keep a copy in the history
    public function remove(Request $request, $id)
    {
        $a = DB::table('announcements')->where('id', $id)->first();
        if (!$a) {
            return response()->json(['message' => 'Announcement not found.'], 404);
        }

        $auth = $request->attributes->get('auth_user');

        DB::transaction(function () use ($a, $auth) {
            DB::table('announcement_history')->insert([
                'announcement_id' => $a->id,
                'title'           => $a->title,
                'body'            => $a->body,
                'created_by'      => $a->created_by,
                'posted_at'       => $a->created_at,
                'removed_by'      => $auth?->id,
                'removed_at'      => Carbon::now(),
            ]);
            DB::table('announcements')->where('id', $a->id)->delete();
        });

        return response()->json(['message' => 'Announcement removed. You can find it in the history.']);
    }

    // GET /api/announcements/history  -> removed announcements, newest first
    public function index()
    {
        $rows = DB::table('announcement_history as h')
            ->leftJoin('users as p', 'p.id', '=', 'h.created_by')
            ->leftJoin('users as r', 'r.id', '=', 'h.removed_by')
            ->select('h.*', 'p.name as author', 'r.name as removed_by_name')
            ->orderByDesc('h.removed_at')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }

    // DELETE /api/announcements/history/{id}  -> delete it for good
    public function destroy($id)
    {
        $deleted = DB::table('announcement_history')->where('id', $id)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Not found in the history.'], 404);
        }
        return response()->json(['message' => 'Deleted for good.']);
    }
}