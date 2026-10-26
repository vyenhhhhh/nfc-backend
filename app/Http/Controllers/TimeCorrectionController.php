<?php
//TimeCorrectionController.php
namespace App\Http\Controllers;

use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Forgot to tap out, or forgot to tap in?
 *  - forgot to tap out: the intern sends the time they left (past days only; today they can still tap)
 *  - forgot to tap in: the intern sends when they arrived; if the day has only one tap
 *    (e.g. they tapped only when leaving), that tap becomes the time out
 *  - the intern's HTE supervisor approves it (the time out is saved right away and the day gets its
 *    hours) or rejects it — this is final, the OJT coordinator is not involved   (SupervisorController)
 *  - an intern needs an assigned supervisor before they can send one
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

    // POST /api/intern/time-corrections
    //   forgot to tap out: { attendance_id, kind: "out", time_out: "17:00", reason }
    //   forgot to tap in:  { attendance_id, kind: "in",  time_in:  "08:00", reason }
    //     (if the day has only one tap, that tap becomes the time out)
    public function store(Request $request)
    {
        if ($request->input('kind') === 'day') return $this->storeDay($request);

        $data = $request->validate([
            'attendance_id' => 'required|integer',
            'kind'          => 'nullable|in:out,in',
            'time_out'      => 'required_unless:kind,in|nullable|date_format:H:i',
            'time_in'       => 'required_if:kind,in|nullable|date_format:H:i',
            'reason'        => 'required|string|max:500',
        ]);
        $kind = $data['kind'] ?? 'out';

        $auth = $request->attributes->get('auth_user');
        $rec  = DB::table('attendance_records')->where('id', $data['attendance_id'])->first();

        if (!$rec || (int) $rec->user_id !== (int) $auth->id || $rec->uid === 'ONLINE') {
            return response()->json(['message' => 'Attendance record not found.'], 404);
        }

        $day   = substr((string) $rec->date, 0, 10);
        $today = Carbon::today('Asia/Manila')->toDateString();
        $tap   = Carbon::parse($rec->checked_in_at, 'Asia/Manila');   // the tap the kiosk saved as the time in
        $requestedIn = null;

        if ($kind === 'out') {
            if ($rec->checked_out_at) {
                return response()->json(['message' => 'This day already has a time out.'], 409);
            }
            if ($day >= $today) {
                return response()->json(['message' => 'You can still tap out at the kiosk today.'], 422);
            }

            $out    = Carbon::parse("{$day} {$data['time_out']}", 'Asia/Manila');
            $latest = $tap->copy()->startOfDay()->addHours((int) config('ojt.schedule.pm_out', 17));

            if ($out->lessThanOrEqualTo($tap)) {
                return response()->json(['message' => 'Your time out must be after your time in (' . $tap->format('g:i A') . ').'], 422);
            }
            if ($out->greaterThan($latest)) {
                return response()->json([
                    'message' => 'A forgotten time out can be at most ' . $latest->format('g:i A') . '. Overtime can\'t be added this way.',
                ], 422);
            }
        } else {
            // forgot to tap in: the intern says when they really arrived (earlier than the saved tap)
            $requestedIn = Carbon::parse("{$day} {$data['time_in']}", 'Asia/Manila');
            $earliest    = $tap->copy()->startOfDay()->addHours((int) (config('ojt.tap_window.from', 6)));

            if ($requestedIn->greaterThanOrEqualTo($tap)) {
                return response()->json(['message' => 'Your time in must be earlier than your tap at ' . $tap->format('g:i A') . '.'], 422);
            }
            if ($requestedIn->lessThan($earliest)) {
                return response()->json(['message' => 'Your time in can\'t be earlier than ' . $earliest->format('g:i A') . '.'], 422);
            }
            // only one tap that day -> that tap was really the time out; otherwise the time out stays as it is
            $out = $rec->checked_out_at ? Carbon::parse($rec->checked_out_at, 'Asia/Manila') : $tap;
        }

        if (!Supervision::supervisorOf($auth->id)) {
            return response()->json([
                'message' => "You don't have an HTE supervisor assigned yet. Ask your OJT coordinator to assign one first.",
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
            'kind'          => $kind,
            'requested_in'  => $requestedIn,
            'requested_out' => $out,
            'reason'        => $data['reason'],
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $who  = $auth->name ?? 'An intern';
        $when = Carbon::parse($day)->format('M j, Y');
        Supervision::notifyReviewer(
            $auth->id,
            'Time Correction Request',
            $kind === 'in'
                ? "{$who} forgot to tap in on {$when} and asks for a time in of " . $requestedIn->format('g:i A')
                    . ' (time out ' . $out->format('g:i A') . ').'
                : "{$who} forgot to tap out on {$when} and asks for " . $out->format('g:i A') . '.',
            'time_correction'
        );

        return response()->json(['message' => 'Request sent. Your HTE supervisor will review it.', 'id' => $id]);
    }

    // Missed the whole day (no tap at all): { kind: "day", date, time_in, time_out, reason }
    // The supervisor approves it and the day is added to the intern's attendance.
    private function storeDay(Request $request)
    {
        $data = $request->validate([
            'date'     => 'required|date_format:Y-m-d',
            'time_in'  => 'required|date_format:H:i',
            'time_out' => 'required|date_format:H:i',
            'reason'   => 'required|string|max:500',
        ]);
        $auth  = $request->attributes->get('auth_user');
        $day   = $data['date'];
        $today = Carbon::today('Asia/Manila')->toDateString();
        $d     = Carbon::parse($day, 'Asia/Manila');

        if ($day >= $today) {
            return response()->json(['message' => 'Pick a past day. Today you can still tap at the kiosk.'], 422);
        }
        if ($d->isWeekend()) {
            return response()->json(['message' => 'That day is a ' . $d->format('l') . '. Only weekdays can be added.'], 422);
        }
        if ($d->lt(Carbon::today('Asia/Manila')->subDays(30))) {
            return response()->json(['message' => 'You can only ask for days in the last 30 days.'], 422);
        }
        if (!Supervision::internshipStarted($auth->id)) {
            return response()->json(['message' => "Your internship hasn't started yet, so there is no day to add."], 422);
        }
        if (DB::table('attendance_records')->where('user_id', $auth->id)->where('date', $day)->exists()) {
            return response()->json(['message' => 'That day already has attendance. Use "Request fix" on that day in My Attendance instead.'], 409);
        }

        $in     = Carbon::parse("{$day} {$data['time_in']}", 'Asia/Manila');
        $out    = Carbon::parse("{$day} {$data['time_out']}", 'Asia/Manila');
        $first  = $d->copy()->startOfDay()->addHours((int) config('ojt.tap_window.from', 6));
        $latest = $d->copy()->startOfDay()->addHours((int) config('ojt.schedule.pm_out', 17));
        if ($out->lessThanOrEqualTo($in)) {
            return response()->json(['message' => 'Your time out must be after your time in.'], 422);
        }
        if ($in->lessThan($first)) {
            return response()->json(['message' => 'Your time in can\'t be earlier than ' . $first->format('g:i A') . '.'], 422);
        }
        if ($out->greaterThan($latest)) {
            return response()->json(['message' => 'Your time out can be at most ' . $latest->format('g:i A') . '. Overtime can\'t be added this way.'], 422);
        }
        if (!Supervision::supervisorOf($auth->id)) {
            return response()->json([
                'message' => "You don't have an HTE supervisor assigned yet. Ask your OJT coordinator to assign one first.",
            ], 422);
        }
        $pending = DB::table('time_corrections')->where('user_id', $auth->id)->where('date', $day)
            ->whereIn('status', ['pending', 'endorsed'])->exists();
        if ($pending) {
            return response()->json(['message' => 'You already sent a request for this day.'], 409);
        }

        $now = Carbon::now('Asia/Manila');
        $id  = DB::table('time_corrections')->insertGetId([
            'user_id'       => $auth->id,
            'attendance_id' => null,
            'date'          => $day,
            'kind'          => 'day',
            'requested_in'  => $in,
            'requested_out' => $out,
            'reason'        => $data['reason'],
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        Supervision::notifyReviewer(
            $auth->id,
            'Time Correction Request',
            ($auth->name ?? 'An intern') . ' has no tap on ' . $d->format('M j, Y') . ' and asks to add '
                . $in->format('g:i A') . ' – ' . $out->format('g:i A') . '.',
            'time_correction'
        );

        return response()->json(['message' => 'Request sent. Your HTE supervisor will review it.', 'id' => $id]);
    }
}
