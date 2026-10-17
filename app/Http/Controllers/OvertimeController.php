<?php
//OvertimeController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Overtime = time after 5:00 PM. It is found when the intern checks out (AttendanceController@tap)
 * and only counts after the OJT coordinator approves it. A day still never credits more than
 * the daily cap in config/ojt.php (8 hours).
 */
class OvertimeController extends Controller
{
    // GET /api/coordinator/overtime  -> pending first, then the reviewed ones (newest first)
    public function index()
    {
        $rows = DB::table('attendance_records as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'a.ot_reviewed_by')
            ->whereNotNull('a.ot_status')
            ->select(
                'a.id', 'a.user_id', 'a.date', 'a.checked_in_at', 'a.checked_out_at',
                'a.ot_minutes', 'a.ot_status', 'a.ot_approved_minutes', 'a.ot_remarks', 'a.ot_reviewed_at',
                'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                'r.name as reviewer_name'
            )
            ->orderByRaw("CASE a.ot_status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('a.date')
            ->orderByDesc('a.id')
            ->get();

        return response()->json($rows);
    }

    // POST /api/coordinator/overtime/{id}/review   { status: approved|rejected, minutes?, remarks? }
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'minutes' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string|max:300',
        ]);

        $rec = DB::table('attendance_records')->where('id', $id)->first();
        if (!$rec || !$rec->ot_status) {
            return response()->json(['message' => 'Overtime not found.'], 404);
        }

        // the coordinator may credit less than the full overtime, never more
        $approved = $data['status'] === 'approved'
            ? min((int) ($data['minutes'] ?? $rec->ot_minutes), (int) $rec->ot_minutes)
            : 0;

        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');

        // only a pending request can be reviewed
        $changed = DB::table('attendance_records')
            ->where('id', $id)
            ->where('ot_status', 'pending')
            ->update([
                'ot_status'           => $data['status'],
                'ot_approved_minutes' => $approved,
                'ot_remarks'          => $data['remarks'] ?? null,
                'ot_reviewed_by'      => $auth->id ?? null,
                'ot_reviewed_at'      => $now,
                'updated_at'          => $now,
            ]);

        if (!$changed) {
            return response()->json(['message' => 'This overtime was already reviewed.'], 409);
        }

        $when = Carbon::parse($rec->date)->format('M j, Y');
        Notifier::toUser(
            $rec->user_id,
            $data['status'] === 'approved' ? 'Overtime Approved' : 'Overtime Not Approved',
            $data['status'] === 'approved'
                ? "{$approved} min of your overtime on {$when} was credited (daily limit: "
                    . config('ojt.daily_cap_hours', 8) . ' hours).'
                    . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : '')
                : "Your overtime on {$when} was not credited."
                    . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''),
            'overtime_review'
        );

        return response()->json([
            'message' => $data['status'] === 'approved'
                ? "Overtime approved: {$approved} min credited."
                : 'Overtime rejected.',
        ]);
    }
}