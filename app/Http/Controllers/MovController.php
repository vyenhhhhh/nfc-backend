<?php
//movcontroller.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\OjtHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class MovController extends Controller
{
    // Intern submits a MOV
    public function submit(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'title'   => 'required|string|max:150',
            'requirement' => 'nullable|string|max:60',
            'phase'       => 'nullable|in:pre,during,post',
            'report_id'   => 'nullable|integer',     // the coordinator's report this upload answers (weekly / monthly report)
            'file'    => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB
        ]);

        $phase       = $request->input('phase', 'pre');
        $requirement = $request->input('requirement') ?: 'others';

        // During-internship documents open only when the internship has started
        // (every pre-internship requirement approved by the coordinator)
        if ($phase === 'during' && !\App\Services\Supervision::internshipStarted((int) $request->user_id)) {
            return response()->json([
                'message' => 'During-internship requirements open once all your pre-internship requirements are approved.',
            ], 403);
        }

        // Post-internship documents open only after the required hours are done
        if ($phase === 'post') {
            $u = DB::table('users')->where('id', $request->user_id)->first();
            if (($u->tracking_type ?? 'hours') !== 'output') {
                $required = $this->requiredHours($u->program ?? null);
                $rendered = $this->renderedHours((int) $request->user_id);
                if ($rendered + 0.0001 < $required) {
                    return response()->json([
                        'message' => "Post-internship requirements unlock after you complete {$required} hours. You have {$rendered} hours so far.",
                    ], 403);
                }
            }
        }

        // one live submission per requirement (a rejected one can be re-uploaded);
        // "others" and weekly/monthly reports can be submitted many times
        if (!in_array($requirement, ['others', 'weekly_report'], true)) {
            $exists = DB::table('movs')
                ->where('user_id', $request->user_id)
                ->where('requirement', $requirement)
                ->where('phase', $phase)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'This requirement was already submitted.'], 409);
            }
        }

        // a weekly / monthly report goes into one of the reports the coordinator asked for
        $reportId = $request->filled('report_id') ? (int) $request->report_id : null;
        $title = $request->title;
        if ($reportId) {
            $report = ReportController::allowsIntern($reportId, (int) $request->user_id);
            if (!$report) return response()->json(['message' => 'This report is not for you.'], 403);
            // one live upload per report (a returned one can be uploaded again)
            $sent = DB::table('movs')->where('user_id', $request->user_id)->where('report_id', $reportId)
                ->whereIn('status', ['pending', 'approved'])->exists();
            if ($sent) return response()->json(['message' => 'You already sent this report.'], 409);
            $requirement = 'weekly_report';
            $phase = 'during';
            $title = $report->title;
        }

        $path = $request->file('file')->store('movs', config('filesystems.uploads', 'public'));

        $id = DB::table('movs')->insertGetId([
            'user_id'       => $request->user_id,
            'title'         => $title,
            'requirement'   => $requirement,
            'report_id'     => $reportId,
            'phase'         => $phase,
            'file_path'     => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'status'        => 'pending',
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);

        // Notify the OJT coordinators
        $intern = DB::table('users')->where('id', $request->user_id)->first();
        Notifier::toRoles(
            ['ojt_coordinator'],
            'New MOV Submitted',
            ($intern->name ?? 'An intern') . ' submitted "' . $title . '" for review.',
            'mov_submission'
        );

        return response()->json(['message' => 'MOV submitted successfully.', 'id' => $id]);
    }

    // Intern removes their own MOV while it is still pending (the uploaded file is deleted too)
    // DELETE /api/intern/submit-mov/{id}
    public function destroy(Request $request, $id)
    {
        $auth = $request->attributes->get('auth_user');
        $mov  = DB::table('movs')->where('id', $id)->first();

        if (!$mov || (int) $mov->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }
        if ($mov->status !== 'pending') {
            return response()->json(['message' => 'Only pending submissions can be removed.'], 409);
        }

        if ($mov->file_path) Storage::disk(config('filesystems.uploads', 'public'))->delete($mov->file_path);
        DB::table('movs')->where('id', $id)->delete();

        return response()->json(['message' => 'Submission removed.']);
    }

    // Coordinator views all submissions
    public function index()
    {
        $movs = DB::table('movs as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('report_schedules as rs', 'rs.id', '=', 'm.report_id')   // which report it answers
            ->orderByDesc('m.created_at')
            ->select('m.*', 'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                'rs.title as report_title', 'rs.due_date as report_due')
            ->get();

        return response()->json($movs);
    }

    // Intern views their own submissions
    public function myMovs(Request $request)
    {
        $movs = DB::table('movs')
            ->where('user_id', $request->query('user_id'))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($movs);
    }

    // Coordinator approves/rejects
    public function review(Request $request, $id)
    {
        $request->validate([
            'status'      => 'required|in:approved,rejected',
            'remarks'     => 'nullable|string',
            'reviewer_id' => 'nullable|integer|exists:users,id',
        ]);

        $mov = DB::table('movs')->where('id', $id)->first();
        if (!$mov) return response()->json(['message' => 'Submission not found.'], 404);

        $auth     = $request->attributes->get('auth_user');
        $reviewer = $auth->id ?? $request->reviewer_id;

        // only a pending submission can be reviewed
        $changed = DB::table('movs')->where('id', $id)->where('status', 'pending')->update([
            'status'      => $request->status,
            'remarks'     => $request->remarks,
            'reviewed_by' => $reviewer,
            'reviewed_at' => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        if (!$changed) {
            return response()->json(['message' => 'This submission was already reviewed.'], 409);
        }

        // Notify the intern about the decision
        Notifier::toUser(
            $mov->user_id,
            $request->status === 'approved' ? 'MOV Approved' : 'MOV Rejected',
            'Your "' . $mov->title . '" was ' . $request->status . '.'
                . ($request->remarks ? ' Remarks: ' . $request->remarks : ''),
            'mov_review'
        );

        return response()->json(['message' => 'Submission ' . $request->status . '.']);
    }

    // POST /coordinator/movs/{id}/undo
    // The coordinator takes back a decision made by mistake: the submission goes back to "waiting for review".
    // Only the OJT coordinator can do this (the admin can't).
    public function undoReview(Request $request, $id)
    {
        $auth = $request->attributes->get('auth_user');
        if (!$auth || $auth->role !== 'ojt_coordinator') {
            return response()->json(['message' => 'Only the OJT coordinator can undo a review.'], 403);
        }

        $mov = DB::table('movs')->where('id', $id)->first();
        if (!$mov) return response()->json(['message' => 'Submission not found.'], 404);

        $was = $mov->status;
        $changed = DB::table('movs')->where('id', $id)->whereIn('status', ['approved', 'rejected'])->update([
            'status'      => 'pending',
            'remarks'     => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'updated_at'  => Carbon::now(),
        ]);
        if (!$changed) {
            return response()->json(['message' => 'This submission is already waiting for review.'], 409);
        }

        Notifier::toUser(
            $mov->user_id,
            'MOV Review Undone',
            'Your "' . $mov->title . '" was marked ' . $was . ' by mistake. It is back to waiting for review.',
            'mov_review'
        );

        return response()->json(['message' => 'Done. "' . $mov->title . '" is back to waiting for review.']);
    }

    // Required hours for a program such as "BSIT - 4" (set in config/ojt.php)
    private function requiredHours(?string $program): float
    {
        $p = strtoupper((string) $program);
        $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';

        return (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 460));
    }

    // Same rule as the dashboard (see app/Services/OjtHours.php)
    private function renderedHours(int $userId): float
    {
        return OjtHours::rendered($userId);
    }
}