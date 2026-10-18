<?php
//TimeCorrectionController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Forgot to tap out?
 *  - the intern sends the time they left and a reason (for a past day only; today they can still tap)
 *  - the intern's supervisor confirms it ("endorsed") or rejects it            (SupervisorController)
 *  - then the OJT coordinator approves (the time out is saved and the day gets its hours) or rejects it
 *  - an intern with no supervisor assigned yet goes straight to the coordinator
 */
class TimeCorrectionController extends Controller
{
    // GET /api/intern/time-corrections  -> the signed-in intern's own requests
    public function mine(Request $request)
    {
        $uid = $request->attributes->get('auth_user')->id;

        return response()->json(
            DB::table('time_corrections')->where('user_id', $uid)->orderByDesc('id')->get()
        );
    }

    // POST /api/intern/time-corrections   { attendance_id, time_out: "17:00", reason }
    public function store(Request $request)
    {
        $data = $request->validate([
            'attendance_id' => 'required|integer',
            'time_out'      => 'required|date_format:H:i',
            'reason'        => 'required|string|max:500',
        ]);

        $auth = $request->attributes->get('auth_user');
        $rec  = DB::table('attendance_records')->where('id', $data['attendance_id'])->first();

        if (!$rec || (int) $rec->user_id !== (int) $auth->id || $rec->uid === 'ONLINE') {
            return response()->json(['message' => 'Attendance record not found.'], 404);
        }
        if ($rec->checked_out_at) {
            return response()->json(['message' => 'This day already has a time out.'], 409);
        }

        $day = substr((string) $rec->date, 0, 10);
        if ($day >= Carbon::today('Asia/Manila')->toDateString()) {
            return response()->json(['message' => 'You can still tap out at the kiosk today.'], 422);
        }

        $in     = Carbon::parse($rec->checked_in_at, 'Asia/Manila');
        $out    = Carbon::parse("{$day} {$data['time_out']}", 'Asia/Manila');
        $latest = $in->copy()->startOfDay()->addHours((int) config('ojt.schedule.pm_out', 17));

        if ($out->lessThanOrEqualTo($in)) {
            return response()->json(['message' => 'Your time out must be after your time in (' . $in->format('g:i A') . ').'], 422);
        }
        if ($out->greaterThan($latest)) {
            return response()->json([
                'message' => 'A forgotten time out can be at most ' . $latest->format('g:i A') . '. Overtime can\'t be added this way.',
            ], 422);
        }

        $pending = DB::table('time_corrections')
            ->where('attendance_id', $rec->id)
            ->whereIn('status', ['pending', 'endorsed'])
            ->exists();
        if ($pending) {
            return response()->json(['message' => 'You already sent a request for this day.'], 409);
        }

        $now = Carbon::now('Asia/Manila');
        $id  = DB::table('time_corrections')->insertGetId([
            'user_id'       => $auth->id,
            'attendance_id' => $rec->id,
            'date'          => $day,
            'requested_out' => $out,
            'reason'        => $data['reason'],
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        Supervision::notifyReviewer(
            $auth->id,
            'Time Correction Request',
            ($auth->name ?? 'An intern') . ' forgot to tap out on ' . Carbon::parse($day)->format('M j, Y')
                . ' and asks for ' . $out->format('g:i A') . '.',
            'time_correction'
        );

        return response()->json(['message' => 'Request sent. Your OJT coordinator will review it.', 'id' => $id]);
    }

    // GET /api/coordinator/time-corrections  -> pending first, then reviewed (newest first)
    public function index()
    {
        $rows = DB::table('time_corrections as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('attendance_records as a', 'a.id', '=', 'c.attendance_id')
            ->leftJoin('users as r', 'r.id', '=', 'c.reviewed_by')
            ->leftJoin('users as e', 'e.id', '=', 'c.endorsed_by')
            ->leftJoin('users as s', 's.id', '=', 'u.supervisor_id')
            ->select(
                'c.*', 'a.checked_in_at', 'u.supervisor_id',
                'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                'r.name as reviewer_name', 'e.name as endorser_name', 's.name as supervisor_name'
            )
            ->orderByRaw("CASE c.status WHEN 'endorsed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('c.date')
            ->orderByDesc('c.id')
            ->get();

        return response()->json($rows);
    }

    // POST /api/coordinator/time-corrections/{id}/review   { status: approved|rejected, remarks? }
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);

        $req = DB::table('time_corrections')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        // step 2: after the supervisor confirmed it ("endorsed"); a "pending" one only when there is no supervisor
        $hasSupervisor = Supervision::supervisorOf($req->user_id) !== null;
        $from = $req->status === 'endorsed' ? 'endorsed' : (($req->status === 'pending' && !$hasSupervisor) ? 'pending' : null);
        if (!$from) {
            return response()->json([
                'message' => $req->status === 'pending'
                    ? "Waiting for the intern's supervisor to review it first."
                    : 'This request was already reviewed.',
            ], 409);
        }

        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');

        $result = DB::transaction(function () use ($req, $data, $auth, $now, $from) {
            if ($data['status'] === 'approved') {
                // save the time out only if the record still has none
                $fixed = DB::table('attendance_records')
                    ->where('id', $req->attendance_id)
                    ->whereNull('checked_out_at')
                    ->update([
                        'action'         => 'CHECK_OUT',
                        'checked_out_at' => $req->requested_out,
                        'updated_at'     => $now,
                    ]);
                if (!$fixed) return 'conflict';
            }

            DB::table('time_corrections')->where('id', $req->id)->where('status', $from)->update([
                'status'      => $data['status'],
                'remarks'     => $data['remarks'] ?? null,
                'reviewed_by' => $auth->id ?? null,
                'reviewed_at' => $now,
                'updated_at'  => $now,
            ]);
            return 'ok';
        });

        if ($result === 'conflict') {
            return response()->json(['message' => 'That day already has a time out, so nothing was changed.'], 409);
        }

        $when = Carbon::parse($req->date)->format('M j, Y');
        $time = Carbon::parse($req->requested_out, 'Asia/Manila')->format('g:i A');
        Notifier::toUser(
            $req->user_id,
            $data['status'] === 'approved' ? 'Time Correction Approved' : 'Time Correction Rejected',
            ($data['status'] === 'approved'
                ? "Your time out on {$when} was set to {$time}."
                : "Your time correction for {$when} was rejected.")
                . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''),
            'time_correction_review'
        );

        return response()->json([
            'message' => $data['status'] === 'approved' ? "Approved. Time out set to {$time}." : 'Request rejected.',
        ]);
    }
}