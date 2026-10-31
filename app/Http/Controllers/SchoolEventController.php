<?php
//SchoolEventController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attending a CSU school event = an excused absence (the OJT head allows it; mandatory events need permission).
 *  - the event time gives NO OJT hours, and the day is not counted as absent
 *  - taps the intern still made that day count as normal (the DTR shows the undertime)
 *  - Filed -> HTE supervisor confirms -> OJT coordinator decides (no supervisor: straight to the coordinator)
 */
class SchoolEventController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    /* ───────────── Intern ───────────── */

    // GET /api/intern/school-events
    public function mine(Request $request)
    {
        return response()->json(
            DB::table('school_event_excuses')->where('user_id', $this->auth($request)->id)->orderByDesc('date')->orderByDesc('id')->get()
        );
    }

    // POST /api/intern/school-events   { date, event_name, reason?, mandatory? }
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'       => 'required|date_format:Y-m-d',
            'event_name' => 'required|string|max:160',
            'reason'     => 'nullable|string|max:500',
            'mandatory'  => 'nullable|boolean',
        ]);
        $auth  = $this->auth($request);
        $today = Carbon::today('Asia/Manila');
        $d     = Carbon::parse($data['date'], 'Asia/Manila');

        if ($auth->role !== 'intern') return response()->json(['message' => 'Only interns can file this.'], 403);
        if ($d->isWeekend()) return response()->json(['message' => 'That day is a ' . $d->format('l') . '. Pick a weekday.'], 422);
        if ($d->lt($today->copy()->subDays(30))) return response()->json(['message' => 'You can only file for the last 30 days or upcoming days.'], 422);
        if ($d->gt($today->copy()->addDays(60))) return response()->json(['message' => 'That date is too far ahead.'], 422);
        if (DB::table('school_event_excuses')->where('user_id', $auth->id)->where('date', $data['date'])
            ->whereIn('status', ['pending', 'endorsed', 'approved'])->exists()) {
            return response()->json(['message' => 'You already filed a school event for that day.'], 409);
        }

        $now = Carbon::now('Asia/Manila');
        $id  = DB::table('school_event_excuses')->insertGetId([
            'user_id'    => $auth->id,
            'date'       => $data['date'],
            'event_name' => trim($data['event_name']),
            'mandatory'  => (bool) ($data['mandatory'] ?? false),
            'reason'     => $data['reason'] ?? null,
            'status'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Supervision::notifyReviewer($auth->id, 'School Event (Excused)',
            ($auth->name ?? 'An intern') . ' will attend "' . trim($data['event_name']) . '" on ' . $d->format('M j, Y')
                . (!empty($data['mandatory']) ? ' (mandatory)' : '') . ' and asks for an excused day.',
            'school_event');

        return response()->json([
            'message' => Supervision::supervisorOf($auth->id)
                ? 'Request sent. Your HTE supervisor reviews it first, then your OJT coordinator.'
                : 'Request sent. Your OJT coordinator will review it.',
            'id' => $id,
        ]);
    }

    // DELETE /api/intern/school-events/{id}  (only while waiting)
    public function destroy(Request $request, $id)
    {
        $row = DB::table('school_event_excuses')->where('id', $id)->first();
        if (!$row || (int) $row->user_id !== (int) $this->auth($request)->id) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if (!in_array($row->status, ['pending', 'endorsed'], true)) {
            return response()->json(['message' => 'Only waiting requests can be removed.'], 409);
        }
        DB::table('school_event_excuses')->where('id', $id)->delete();
        return response()->json(['message' => 'Request removed.']);
    }

    /* ───────────── Excused days (DTR / attendance) ───────────── */

    // GET /api/excused-days?user_id=5  -> [{date, event_name}] approved days
    // interns: their own · supervisor: own interns · admin / coordinator: anyone
    public function excused(Request $request)
    {
        $auth = $this->auth($request);
        $uid  = $auth->role === 'intern' ? (int) $auth->id : (int) $request->query('user_id');
        if (!$uid) return response()->json([]);
        $scope = Supervision::scope($auth);
        if ($scope !== null && !in_array($uid, $scope, true)) return response()->json([]);

        return response()->json(
            DB::table('school_event_excuses')->where('user_id', $uid)->where('status', 'approved')
                ->orderBy('date')->get(['date', 'event_name', 'mandatory'])
        );
    }

    /* ───────────── Supervisor and coordinator ───────────── */

    // GET /api/supervisor/school-events   (supervisor: own interns · admin/coordinator: everyone)
    public function index(Request $request)
    {
        $auth  = $this->auth($request);
        $scope = Supervision::scope($auth);

        $q = DB::table('school_event_excuses as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'e.reviewed_by')
            ->select('e.*', 'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                'u.supervisor_id', 'r.name as reviewer_name');
        if ($scope !== null) $q->whereIn('e.user_id', $scope);

        // supervisor: waiting for me = pending. coordinator: waiting for me = endorsed, or pending with no supervisor
        $waiting = $auth->role === 'supervisor'
            ? "e.status = 'pending'"
            : "(e.status = 'endorsed' OR (e.status = 'pending' AND u.supervisor_id IS NULL))";
        return response()->json(
            $q->orderByRaw("CASE WHEN {$waiting} THEN 0 WHEN e.status IN ('pending','endorsed') THEN 1 ELSE 2 END")
              ->orderByDesc('e.date')->get()
        );
    }

    // POST /api/supervisor/school-events/{id}/review   { status: approved|rejected, remarks? }
    // supervisor: approve = confirm (sent to the coordinator) · coordinator / admin: approve = final
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $this->auth($request);
        $row  = DB::table('school_event_excuses')->where('id', $id)->first();
        if (!$row) return response()->json(['message' => 'Request not found.'], 404);

        $isSup   = $auth->role === 'supervisor';
        $approve = $data['status'] === 'approved';
        $now     = Carbon::now('Asia/Manila');

        if ($isSup) {
            if (!Supervision::supervises($auth, $row->user_id)) return response()->json(['message' => 'Request not found.'], 404);
            if ($row->status !== 'pending') return response()->json(['message' => 'This request was already reviewed.'], 409);
            $upd = $approve
                ? ['status' => 'endorsed', 'endorsed_by' => $auth->id, 'endorsed_at' => $now, 'endorse_remarks' => $data['remarks'] ?? null]
                : ['status' => 'rejected', 'endorsed_by' => $auth->id, 'endorsed_at' => $now, 'endorse_remarks' => $data['remarks'] ?? null,
                   'reviewed_by' => $auth->id, 'reviewed_at' => $now, 'remarks' => $data['remarks'] ?? null];
            $from = ['pending'];
        } else {
            $hasSup = Supervision::supervisorOf($row->user_id) !== null;
            if ($row->status === 'pending' && $hasSup) {
                return response()->json(['message' => "This is still waiting for the intern's HTE supervisor."], 409);
            }
            if (!in_array($row->status, ['pending', 'endorsed'], true)) return response()->json(['message' => 'This request was already reviewed.'], 409);
            $upd = ['status' => $data['status'], 'reviewed_by' => $auth->id, 'reviewed_at' => $now, 'remarks' => $data['remarks'] ?? null];
            $from = ['pending', 'endorsed'];
        }
        $upd['updated_at'] = $now;

        $changed = DB::table('school_event_excuses')->where('id', $id)->whereIn('status', $from)->update($upd);
        if (!$changed) return response()->json(['message' => 'This request was already reviewed.'], 409);

        $internName = DB::table('users')->where('id', $row->user_id)->value('name') ?? 'An intern';
        $when = Carbon::parse($row->date)->format('M j, Y');
        $rem  = !empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : '';

        if ($isSup && $approve) {
            Notifier::toRoles(['ojt_coordinator'], 'School Event for Final Approval',
                "{$auth->name} confirmed {$internName}'s school event \"{$row->event_name}\" on {$when}.", 'school_event');
            Notifier::toUser($row->user_id, 'School Event Confirmed by Supervisor',
                "Your supervisor confirmed \"{$row->event_name}\" on {$when}. Waiting for your OJT coordinator.", 'school_event_review');
            $msg = 'Confirmed. Sent to the OJT coordinator for the final decision.';
        } elseif ($approve) {
            Notifier::toUser($row->user_id, 'School Event Approved',
                "{$when} is an excused day for \"{$row->event_name}\". The event time is not counted as OJT hours.", 'school_event_review');
            $msg = 'Approved. The day is excused (no hours, not absent).';
        } else {
            Notifier::toUser($row->user_id, 'School Event Not Approved',
                ($isSup ? 'Your supervisor' : 'Your OJT coordinator') . " did not approve \"{$row->event_name}\" on {$when}." . $rem, 'school_event_review');
            $msg = 'Request rejected.';
        }
        return response()->json(['message' => $msg]);
    }
}