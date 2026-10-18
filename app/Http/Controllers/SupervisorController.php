<?php
//SupervisorController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\OjtHours;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The HTE supervisor's part (from the OJT coordinator's answers: "the DTRs should be validated by the
 * respective supervisors"):
 *  - overtime and forgotten time-outs of their own interns: approve (sent on to the OJT coordinator for the
 *    final decision, status "endorsed") or reject
 *  - certify an intern's DTR for a period (e.g. Oct 1–15)
 */
class SupervisorController extends Controller
{
    private function auth(Request $request)
    {
        return $request->attributes->get('auth_user');
    }

    /* ───────────── Overtime ───────────── */

    // GET /api/supervisor/overtime  -> overtime of my interns (waiting for me first)
    public function overtime(Request $request)
    {
        $scope = Supervision::scope($this->auth($request)) ?? [];

        return response()->json(
            DB::table('attendance_records as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('users as r', 'r.id', '=', 'a.ot_reviewed_by')
                ->whereIn('a.user_id', $scope)
                ->whereNotNull('a.ot_status')
                ->select(
                    'a.id', 'a.user_id', 'a.date', 'a.checked_in_at', 'a.checked_out_at',
                    'a.ot_minutes', 'a.ot_status', 'a.ot_endorsed_minutes', 'a.ot_endorse_remarks', 'a.ot_endorsed_at',
                    'a.ot_approved_minutes', 'a.ot_remarks', 'a.ot_reviewed_at',
                    'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                    'r.name as reviewer_name'
                )
                ->orderByRaw("CASE a.ot_status WHEN 'pending' THEN 0 WHEN 'endorsed' THEN 1 ELSE 2 END")
                ->orderByDesc('a.date')
                ->get()
        );
    }

    // POST /api/supervisor/overtime/{id}/review   { status: approved|rejected, minutes?, remarks? }
    public function reviewOvertime(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'minutes' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $this->auth($request);

        $rec = DB::table('attendance_records')->where('id', $id)->first();
        if (!$rec || !$rec->ot_status || !Supervision::supervises($auth, $rec->user_id)) {
            return response()->json(['message' => 'Overtime not found.'], 404);
        }

        $now = Carbon::now('Asia/Manila');
        $approve = $data['status'] === 'approved';
        $minutes = $approve ? min((int) ($data['minutes'] ?? $rec->ot_minutes), (int) $rec->ot_minutes) : null;

        // approve -> "endorsed" (waits for the coordinator); reject -> final
        $changed = DB::table('attendance_records')
            ->where('id', $id)
            ->where('ot_status', 'pending')
            ->update(array_merge([
                'ot_status'           => $approve ? 'endorsed' : 'rejected',
                'ot_endorsed_minutes' => $minutes,
                'ot_endorsed_by'      => $auth->id,
                'ot_endorsed_at'      => $now,
                'ot_endorse_remarks'  => $data['remarks'] ?? null,
                'updated_at'          => $now,
            ], $approve ? [] : [
                'ot_approved_minutes' => 0,
                'ot_remarks'          => $data['remarks'] ?? null,
                'ot_reviewed_by'      => $auth->id,
                'ot_reviewed_at'      => $now,
            ]));

        if (!$changed) {
            return response()->json(['message' => 'This overtime was already reviewed.'], 409);
        }

        $intern = DB::table('users')->where('id', $rec->user_id)->value('name') ?? 'An intern';
        $when   = Carbon::parse($rec->date)->format('M j, Y');
        if ($approve) {
            Notifier::toRoles(['ojt_coordinator'], 'Overtime for Final Approval',
                "{$auth->name} approved {$minutes} min of {$intern}'s overtime on {$when}.", 'overtime');
            Notifier::toUser($rec->user_id, 'Overtime Approved by Supervisor',
                "Your supervisor approved {$minutes} min of overtime on {$when}. Waiting for your OJT coordinator.", 'overtime_review');
        } else {
            Notifier::toUser($rec->user_id, 'Overtime Not Approved',
                "Your supervisor did not approve your overtime on {$when}."
                    . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''), 'overtime_review');
        }

        return response()->json([
            'message' => $approve ? 'Approved. Sent to the OJT coordinator for the final decision.' : 'Overtime rejected.',
        ]);
    }

    /* ───────────── Time corrections (forgotten time-outs) ───────────── */

    // GET /api/supervisor/time-corrections
    public function corrections(Request $request)
    {
        $scope = Supervision::scope($this->auth($request)) ?? [];

        return response()->json(
            DB::table('time_corrections as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->leftJoin('attendance_records as a', 'a.id', '=', 'c.attendance_id')
                ->leftJoin('users as r', 'r.id', '=', 'c.reviewed_by')
                ->whereIn('c.user_id', $scope)
                ->select('c.*', 'a.checked_in_at',
                    'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                    'r.name as reviewer_name')
                ->orderByRaw("CASE c.status WHEN 'pending' THEN 0 WHEN 'endorsed' THEN 1 ELSE 2 END")
                ->orderByDesc('c.date')
                ->get()
        );
    }

    // POST /api/supervisor/time-corrections/{id}/review   { status: approved|rejected, remarks? }
    public function reviewCorrection(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $this->auth($request);

        $req = DB::table('time_corrections')->where('id', $id)->first();
        if (!$req || !Supervision::supervises($auth, $req->user_id)) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $now = Carbon::now('Asia/Manila');
        $approve = $data['status'] === 'approved';

        $changed = DB::table('time_corrections')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update(array_merge([
                'status'          => $approve ? 'endorsed' : 'rejected',
                'endorsed_by'     => $auth->id,
                'endorsed_at'     => $now,
                'endorse_remarks' => $data['remarks'] ?? null,
                'updated_at'      => $now,
            ], $approve ? [] : [
                'remarks'     => $data['remarks'] ?? null,
                'reviewed_by' => $auth->id,
                'reviewed_at' => $now,
            ]));

        if (!$changed) {
            return response()->json(['message' => 'This request was already reviewed.'], 409);
        }

        $intern = DB::table('users')->where('id', $req->user_id)->value('name') ?? 'An intern';
        $when   = Carbon::parse($req->date)->format('M j, Y');
        $time   = Carbon::parse($req->requested_out, 'Asia/Manila')->format('g:i A');
        if ($approve) {
            Notifier::toRoles(['ojt_coordinator'], 'Time Correction for Final Approval',
                "{$auth->name} confirmed {$intern} left at {$time} on {$when}.", 'time_correction');
            Notifier::toUser($req->user_id, 'Time Correction Confirmed by Supervisor',
                "Your supervisor confirmed your time out ({$time}) on {$when}. Waiting for your OJT coordinator.", 'time_correction_review');
        } else {
            Notifier::toUser($req->user_id, 'Time Correction Rejected',
                "Your supervisor rejected your time correction for {$when}."
                    . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''), 'time_correction_review');
        }

        return response()->json([
            'message' => $approve ? 'Confirmed. Sent to the OJT coordinator for the final decision.' : 'Request rejected.',
        ]);
    }

    /* ───────────── Online attendance (remote workdays) ───────────── */

    // GET /api/supervisor/online  -> my interns' online attendance (waiting for me first)
    public function online(Request $request)
    {
        $scope = Supervision::scope($this->auth($request)) ?? [];

        return response()->json(
            DB::table('online_submissions as os')
                ->join('users as u', 'u.id', '=', 'os.user_id')
                ->whereIn('os.user_id', $scope)
                ->select('os.id', 'os.user_id', 'os.date', 'os.description', 'os.status', 'os.endorse_remarks', 'os.endorsed_at',
                    'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo')
                ->orderByRaw("CASE os.status WHEN 'pending' THEN 0 WHEN 'endorsed' THEN 1 ELSE 2 END")
                ->orderByDesc('os.date')
                ->get()
        );
    }

    // POST /api/supervisor/online/{id}/review   { status: approved|rejected, remarks? }
    public function reviewOnline(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $this->auth($request);

        $sub = DB::table('online_submissions')->where('id', $id)->first();
        if (!$sub || !Supervision::supervises($auth, $sub->user_id)) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        $now = Carbon::now('Asia/Manila');
        $approve = $data['status'] === 'approved';

        $changed = DB::table('online_submissions')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status'          => $approve ? 'endorsed' : 'rejected',
                'endorsed_by'     => $auth->id,
                'endorsed_at'     => $now,
                'endorse_remarks' => $data['remarks'] ?? null,
                'updated_at'      => $now,
            ]);
        if (!$changed) {
            return response()->json(['message' => 'This submission was already reviewed.'], 409);
        }

        // a rejection is final: log it like the coordinator's decisions, so it shows in the history
        if (!$approve) {
            DB::table('validation_logs')->insert([
                'submission_id' => $id,
                'supervisor_id' => $auth->id,
                'action'        => 'rejected',
                'remarks'       => $data['remarks'] ?? '',
                'created_at'    => $now,
            ]);
        }

        $intern = DB::table('users')->where('id', $sub->user_id)->value('name') ?? 'An intern';
        $when   = Carbon::parse($sub->date)->format('M j, Y');
        if ($approve) {
            Notifier::toRoles(['ojt_coordinator'], 'Online Attendance for Final Approval',
                "{$auth->name} confirmed {$intern}'s online work on {$when}.", 'online_submission');
            Notifier::toUser($sub->user_id, 'Online Attendance Confirmed by Supervisor',
                "Your supervisor confirmed your online work on {$when}. Waiting for your OJT coordinator.", 'online_review');
        } else {
            Notifier::toUser($sub->user_id, 'Online Attendance Rejected',
                "Your supervisor rejected your online attendance for {$when}."
                    . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''), 'online_review');
        }

        return response()->json([
            'message' => $approve ? 'Confirmed. Sent to the OJT coordinator for the final decision.' : 'Submission rejected.',
        ]);
    }

    /* ───────────── DTR certification ───────────── */

    // GET /api/dtr-certifications?user_id=5  -> that intern's certified periods (interns: their own only)
    public function certifications(Request $request)
    {
        $auth  = $this->auth($request);
        $scope = Supervision::scope($auth);

        $q = DB::table('dtr_certifications as d')
            ->leftJoin('users as s', 's.id', '=', 'd.certified_by')
            ->select('d.*', 's.name as certified_by_name')
            ->orderByDesc('d.period_from');

        if ($auth->role === 'intern') {
            $q->where('d.user_id', $auth->id);
        } elseif ($request->query('user_id')) {
            $q->where('d.user_id', (int) $request->query('user_id'));
        }
        if ($scope !== null) {
            $q->whereIn('d.user_id', $scope);
        }

        return response()->json($q->get());
    }

    // POST /api/supervisor/dtr-certifications   { user_id, from: "2026-10-01", to: "2026-10-15", remarks? }
    public function certify(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $this->auth($request);

        if (!Supervision::supervises($auth, $data['user_id'])) {
            return response()->json(['message' => 'You can only certify the DTR of your own interns.'], 403);
        }

        $from = Carbon::parse($data['from'])->toDateString();
        $to   = Carbon::parse($data['to'])->toDateString();
        if ($to > Carbon::today('Asia/Manila')->toDateString()) {
            return response()->json(['message' => 'You can only certify a period that has already ended.'], 422);
        }

        // waiting overtime or time corrections would change the hours: decide those first
        $open = DB::table('attendance_records')->where('user_id', $data['user_id'])
                ->whereBetween('date', [$from, $to])->whereIn('ot_status', ['pending', 'endorsed'])->count()
              + DB::table('time_corrections')->where('user_id', $data['user_id'])
                ->whereBetween('date', [$from, $to])->whereIn('status', ['pending', 'endorsed'])->count()
              + DB::table('online_submissions')->where('user_id', $data['user_id'])
                ->whereBetween('date', [$from, $to])->whereIn('status', ['pending', 'endorsed'])->count();
        if ($open) {
            return response()->json([
                'message' => "This period still has {$open} request(s) waiting (overtime, time corrections or online days). Finish those first.",
            ], 409);
        }

        // the hours as the DTR shows them
        $rows = DB::table('attendance_records')
            ->where('user_id', $data['user_id'])
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'checked_in_at', 'checked_out_at', 'ot_approved_minutes']);
        $hours = 0;
        foreach ($rows->groupBy(fn ($r) => (string) $r->date) as $dayRows) {
            $hours += OjtHours::day($dayRows);
        }

        $exists = DB::table('dtr_certifications')
            ->where(['user_id' => $data['user_id'], 'period_from' => $from, 'period_to' => $to])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'This DTR period is already certified.'], 409);
        }

        $now = Carbon::now('Asia/Manila');
        DB::table('dtr_certifications')->insert([
            'user_id'      => $data['user_id'],
            'period_from'  => $from,
            'period_to'    => $to,
            'hours'        => round($hours, 2),
            'remarks'      => $data['remarks'] ?? null,
            'certified_by' => $auth->id,
            'certified_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $label  = Carbon::parse($from)->format('M j') . '–' . Carbon::parse($to)->format('M j, Y');
        $intern = DB::table('users')->where('id', $data['user_id'])->value('name') ?? 'An intern';
        Notifier::toUser($data['user_id'], 'DTR Certified',
            "Your supervisor certified your DTR for {$label} (" . round($hours, 1) . ' hours).', 'dtr_certified');
        Notifier::toRoles(['ojt_coordinator'], 'DTR Certified',
            "{$auth->name} certified {$intern}'s DTR for {$label} (" . round($hours, 1) . ' hours).', 'dtr_certified');

        return response()->json(['message' => "DTR for {$label} certified (" . round($hours, 1) . ' hours).']);
    }

    // DELETE /api/supervisor/dtr-certifications/{id}  -> undo, e.g. certified by mistake
    public function uncertify(Request $request, $id)
    {
        $auth = $this->auth($request);
        $row  = DB::table('dtr_certifications')->where('id', $id)->first();

        if (!$row || (int) $row->certified_by !== (int) $auth->id) {
            return response()->json(['message' => 'Certification not found.'], 404);
        }
        DB::table('dtr_certifications')->where('id', $id)->delete();

        return response()->json(['message' => 'Certification removed.']);
    }
}