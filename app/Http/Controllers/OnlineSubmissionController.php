<?php
//OnlineSubmissionController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OnlineSubmissionController extends Controller
{
    // Intern submits online attendance
    public function submit(Request $request)
    {
        $request->validate([
            'user_id'     => 'required|integer',
            'description' => 'required|string|max:500',
            'date'        => 'required|date',
        ]);

        $now  = Carbon::now('Asia/Manila');
        $date = Carbon::parse($request->date, 'Asia/Manila');

        if ($date->greaterThan($now->copy()->endOfDay())) {
            return response()->json(['message' => "You can't submit attendance for a future date."], 422);
        }

        // any intern may log a day from home; set ojt.online_requires_wfh = true to allow WFH interns only
        if (config('ojt.online_requires_wfh', false)
            && DB::table('users')->where('id', $request->user_id)->value('work_mode') !== 'offsite') {
            return response()->json([
                'message' => 'Online attendance is for approved work-from-home only. Request WFH first (with the letter from your HTE).',
            ], 403);
        }

        // the internship starts only when ALL pre-internship requirements are approved
        if (!Supervision::internshipStarted((int) $request->user_id)) {
            return response()->json([
                'message' => 'Your internship has not started yet. Online attendance opens once all your pre-internship requirements are approved.',
            ], 403);
        }

        // the same day can't count twice: a day already tapped at the kiosk is not an online day
        $tapped = DB::table('attendance_records')
            ->where('user_id', $request->user_id)
            ->where('date', $date->toDateString())
            ->where('uid', '!=', 'ONLINE')
            ->exists();
        if ($tapped) {
            return response()->json(['message' => 'You already have kiosk attendance on this date.'], 409);
        }

        // same day rules as the kiosk (config/ojt.php)
        if (config('ojt.block_weekends', false) && $date->isWeekend()) {
            return response()->json(['message' => 'Online attendance is not accepted on ' . $date->format('l') . 's.'], 422);
        }
        if (config('ojt.block_holidays', false)) {
            $event = \App\Services\CalendarDays::eventFor($date->toDateString(), $request->user_id);
            if ($event) {
                return response()->json(['message' => "That date is a holiday / non-working day ({$event->title})."], 422);
            }
        }

        // Check if already submitted for this date
        $existing = DB::table('online_submissions')
            ->where('user_id', $request->user_id)
            ->where('date', $request->date)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already submitted attendance for this date.'
            ], 409);
        }

        $id = DB::table('online_submissions')->insertGetId([
            'user_id'     => $request->user_id,
            'date'        => $request->date,
            'description' => $request->description,
            'status'      => 'pending',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // the intern's supervisor confirms it first (the coordinators, if no supervisor is assigned)
        $intern = DB::table('users')->where('id', $request->user_id)->first();
        Supervision::notifyReviewer(
            (int) $request->user_id,
            'New Online Attendance',
            ($intern->name ?? 'An intern') . ' submitted online attendance for '
                . Carbon::parse($request->date)->format('M j, Y') . '.',
            'online_submission'
        );

        return response()->json([
            'message' => 'Online attendance submitted successfully.',
            'id'      => $id,
        ]);
    }

    // Intern sees their own online submissions
    public function mine(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
        ]);

        $submissions = DB::table('online_submissions as os')
            ->where('os.user_id', $request->user_id)
            ->orderByDesc('os.date')
            ->select(
                'os.id',
                'os.date',
                'os.description',
                'os.status',
                'os.endorse_remarks',
                DB::raw('(SELECT vl.remarks FROM validation_logs vl
                          WHERE vl.submission_id = os.id
                          ORDER BY vl.created_at DESC LIMIT 1) as remarks')
            )
            ->get();

        return response()->json($submissions);
    }

    // Intern removes their own submission while it is still pending
    // DELETE /api/intern/submit-online/{id}
    public function destroy(Request $request, $id)
    {
        $auth = $request->attributes->get('auth_user');
        $row  = DB::table('online_submissions')->where('id', $id)->first();

        if (!$row || (int) $row->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }
        if ($row->status !== 'pending') {
            return response()->json(['message' => 'Only submissions nobody has reviewed yet can be removed.'], 409);
        }

        DB::table('online_submissions')->where('id', $id)->delete();
        return response()->json(['message' => 'Submission removed.']);
    }

    // Coordinator: submissions not decided yet — "endorsed" (confirmed by the supervisor) first,
    // then "pending" ones (still with the supervisor, or no supervisor assigned)
    public function pending(Request $request)
    {
        $submissions = DB::table('online_submissions as os')
            ->join('users as u', 'u.id', '=', 'os.user_id')
            ->leftJoin('users as e', 'e.id', '=', 'os.endorsed_by')
            ->leftJoin('users as s', 's.id', '=', 'u.supervisor_id')
            ->whereIn('os.status', ['pending', 'endorsed'])
            ->orderByRaw("CASE os.status WHEN 'endorsed' THEN 0 ELSE 1 END")
            ->orderByDesc('os.created_at')
            ->select('os.*', 'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                'u.supervisor_id', 'e.name as endorser_name', 's.name as supervisor_name')
            ->get();

        return response()->json($submissions);
    }

    // Approved + rejected submissions, newest first
    public function history()
    {
        $rows = DB::table('online_submissions as os')
            ->join('users as u', 'u.id', '=', 'os.user_id')
            ->leftJoin('users as e', 'e.id', '=', 'os.endorsed_by')
            ->whereIn('os.status', ['approved', 'rejected'])
            ->orderByDesc('os.updated_at')
            ->select(
                'os.id', 'os.user_id', 'os.date', 'os.description', 'os.status', 'os.endorsed_by', 'os.endorse_remarks',
                'os.created_at as submitted_at', 'e.name as endorser_name',
                DB::raw("CASE WHEN os.status = 'rejected' AND EXISTS (SELECT 1 FROM validation_logs vl2 WHERE vl2.submission_id = os.id AND vl2.supervisor_id = os.endorsed_by) THEN 'supervisor' WHEN os.status = 'rejected' THEN 'coordinator' END as rejected_by"),
                'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                DB::raw('(SELECT vl.remarks FROM validation_logs vl
                          WHERE vl.submission_id = os.id ORDER BY vl.created_at DESC LIMIT 1) as remarks'),
                DB::raw('(SELECT vl.created_at FROM validation_logs vl
                          WHERE vl.submission_id = os.id ORDER BY vl.created_at DESC LIMIT 1) as reviewed_at')
            )
            ->get();

        return response()->json($rows);
    }

    // Coordinator approves or rejects a submission
    public function validate(Request $request)
    {
        $request->validate([
            'submission_id'  => 'required|integer',
            'supervisor_id'  => 'nullable|integer',
            'action'         => 'required|in:approved,rejected',
            'remarks'        => 'nullable|string|max:300',
        ]);

        $now = Carbon::now('Asia/Manila');

        $submission = DB::table('online_submissions')
            ->where('id', $request->submission_id)
            ->first();

        if (!$submission) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        // step 2: after the supervisor confirmed it ("endorsed"); a "pending" one only when there is no supervisor
        $hasSupervisor = Supervision::supervisorOf($submission->user_id) !== null;
        $from = $submission->status === 'endorsed' ? 'endorsed'
            : (($submission->status === 'pending' && !$hasSupervisor) ? 'pending' : null);
        if (!$from) {
            return response()->json([
                'message' => $submission->status === 'pending'
                    ? "Waiting for the intern's supervisor to confirm it first."
                    : 'This submission was already reviewed.',
            ], 409);
        }

        // only if nobody decided it in the meantime (stops double approvals / duplicate attendance)
        $changed = DB::table('online_submissions')
            ->where('id', $request->submission_id)
            ->where('status', $from)
            ->update(['status' => $request->action, 'updated_at' => $now]);

        if (!$changed) {
            return response()->json(['message' => 'This submission was already reviewed.'], 409);
        }

        // Log the validation action (the reviewer is whoever is signed in)
        $auth = $request->attributes->get('auth_user');
        DB::table('validation_logs')->insert([
            'submission_id' => $request->submission_id,
            'supervisor_id' => $auth->id ?? $request->supervisor_id,
            'action'        => $request->action,
            'remarks'       => $request->remarks ?? '',
            'created_at'    => $now,
        ]);

        // If approved, create an attendance record worth a full official day (8:00 AM–5:00 PM = 8 hours)
        if ($request->action === 'approved') {
            $s   = config('ojt.schedule');
            $day = Carbon::parse($submission->date, 'Asia/Manila')->startOfDay();
            DB::table('attendance_records')->insert([
                'user_id'        => $submission->user_id,
                'uid'            => 'ONLINE',
                'date'           => $day->toDateString(),
                'action'         => 'CHECK_OUT',
                'token'          => 'ONLINE-VALIDATED-' . $request->submission_id,
                'checked_in_at'  => $day->copy()->addHours($s['am_in']),
                'checked_out_at' => $day->copy()->addHours($s['pm_out']),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        // Notify the intern about the decision
        $when = Carbon::parse($submission->date)->format('M j, Y');
        Notifier::toUser(
            $submission->user_id,
            $request->action === 'approved' ? 'Online Attendance Approved' : 'Online Attendance Rejected',
            'Your online attendance for ' . $when . ' was ' . $request->action . '.'
                . ($request->remarks ? ' Remarks: ' . $request->remarks : ''),
            'online_review'
        );

        return response()->json([
            'message' => 'Submission ' . $request->action . ' successfully.',
        ]);
    }
}