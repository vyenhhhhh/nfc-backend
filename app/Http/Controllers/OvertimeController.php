<?php
//OvertimeController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Overtime = time after 5:00 PM. It is found when the intern checks out (AttendanceController@tap)
 * and only counts after it is approved, in two steps:
 *   pending   -> the intern's supervisor approves (status "endorsed") or rejects  (SupervisorController)
 *   endorsed  -> the OJT coordinator gives the final decision                     (here)
 * An intern with no supervisor assigned yet goes straight to the coordinator.
 * Approved overtime is added on top of the official hours (config/ojt.php).
 */
class OvertimeController extends Controller
{
    // GET /api/coordinator/overtime  -> pending first, then the reviewed ones (newest first)
    public function index()
    {
        $rows = DB::table('attendance_records as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'a.ot_reviewed_by')
            ->leftJoin('users as e', 'e.id', '=', 'a.ot_endorsed_by')
            ->leftJoin('users as s', 's.id', '=', 'u.supervisor_id')
            ->whereNotNull('a.ot_status')
            ->select(
                'a.id', 'a.user_id', 'a.date', 'a.checked_in_at', 'a.checked_out_at',
                'a.ot_minutes', 'a.ot_status', 'a.ot_approved_minutes', 'a.ot_remarks', 'a.ot_reviewed_at',
                'a.ot_endorsed_minutes', 'a.ot_endorse_remarks', 'a.ot_endorsed_at',
                'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo', 'u.supervisor_id',
                'r.name as reviewer_name', 'e.name as endorser_name', 's.name as supervisor_name'
            )
            ->orderByRaw("CASE a.ot_status WHEN 'endorsed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
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

        // step 2: the coordinator decides after the supervisor approved it ("endorsed");
        // a "pending" one only when the intern has no supervisor assigned
        $hasSupervisor = Supervision::supervisorOf($rec->user_id) !== null;
        $allowed = $rec->ot_status === 'endorsed' ? ['endorsed'] : ($hasSupervisor ? [] : ['pending']);
        if (!$allowed) {
            return response()->json([
                'message' => $rec->ot_status === 'pending'
                    ? "Waiting for the intern's supervisor to review it first."
                    : 'This overtime was already reviewed.',
            ], 409);
        }

        // the coordinator may credit less than the supervisor approved, never more
        $cap = (int) ($rec->ot_status === 'endorsed' && $rec->ot_endorsed_minutes ? $rec->ot_endorsed_minutes : $rec->ot_minutes);
        $approved = $data['status'] === 'approved'
            ? min((int) ($data['minutes'] ?? $cap), $cap)
            : 0;

        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');

        // only if nobody decided it in the meantime
        $changed = DB::table('attendance_records')
            ->where('id', $id)
            ->whereIn('ot_status', $allowed)
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
                ? "{$approved} min of your overtime on {$when} was credited."
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