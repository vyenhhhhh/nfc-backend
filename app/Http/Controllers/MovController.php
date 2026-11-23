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
        // a weekly / monthly report is always a during-internship document (checked below like the others)
        if ($request->filled('report_id')) $phase = 'during';

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
            if ($u && \App\Services\OjtCompletion::isProject($u)) {
                // project-based: done when the project is confirmed complete, not by hours
                if (!\App\Services\OjtCompletion::projectDone($u)) {
                    return response()->json([
                        'message' => 'Post-internship requirements unlock once your HTE supervisor marks your project as complete and the OJT coordinator confirms it.',
                    ], 403);
                }
            } elseif ($u) {   // on-site, hybrid, virtual: the required hours of their program
                $required = $this->requiredHours($u->program ?? null, $u->prior_hours ?? 0);
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

        $path = \App\Services\PrivateFiles::store($request->file('file'), 'movs');   // private: opened with short-lived links only

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

        \App\Services\PrivateFiles::delete($mov->file_path);
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
        $auth  = $request->attributes->get('auth_user');
        $uid   = (int) $request->query('user_id');
        $scope = \App\Services\Supervision::scope($auth);
        if ($scope !== null && !in_array($uid, array_map('intval', (array) $scope), true)) {
            return response()->json(['message' => 'You can only view your own interns.'], 403);
        }

        $movs = DB::table('movs')
            ->where('user_id', $uid)
            ->orderByDesc('created_at')
            ->get();

        // only the intern and the OJT coordinator may open the files themselves
        $seesFiles = $auth && ((int) $auth->id === $uid || $auth->role === 'ojt_coordinator');
        if (!$seesFiles) {
            $movs = $movs->map(function ($m) { unset($m->file_path); return $m; })->values();
        }

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
        // A requirement sent back to the intern is called "returned" (the database still stores 'rejected').
        $word = $request->status === 'approved' ? 'approved' : 'returned';
        Notifier::toUser(
            $mov->user_id,
            $request->status === 'approved' ? 'Requirement Approved' : 'Requirement Returned',
            'Your "' . $mov->title . '" was ' . $word . '.'
                . ($request->remarks ? ' Remarks: ' . $request->remarks : ''),
            'mov_review'
        );

        return response()->json(['message' => 'Submission ' . $word . '.']);
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
            'Your "' . $mov->title . '" was marked ' . ($was === 'rejected' ? 'returned' : $was) . ' by mistake. It is back to waiting for review.',
            'mov_review'
        );

        return response()->json(['message' => 'Done. "' . $mov->title . '" is back to waiting for review.']);
    }

    // Required hours for a program such as "BSIT - 4" (set in config/ojt.php)
    // GET /api/coordinator/narrative-reports?ids=1,2,3
    // Every APPROVED narrative report of the interns shown on the Requirements tracker, in one ZIP
    // (the "consolidated narrative report"). Files: BSIT_Hermoso_Vivien_221-02399.pdf
    public function narrativeZip(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))))));
        if (!$ids) return response()->json(['message' => 'No intern selected.'], 422);
        if (!class_exists(\ZipArchive::class)) return response()->json(['message' => 'The server cannot make ZIP files.'], 500);

        // the newest approved narrative report of each intern
        $rows = DB::table('movs as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.user_id', $ids)
            ->where('m.requirement', 'narrative_report')
            ->where('m.status', 'approved')
            ->orderByDesc('m.created_at')
            ->select('m.user_id', 'm.file_path', 'm.original_name', 'u.name', 'u.student_id', 'u.program')
            ->get()
            ->unique('user_id')
            ->values();
        if ($rows->isEmpty()) return response()->json(['message' => 'None of these interns has an approved narrative report yet.'], 404);

        $tmp = tempnam(sys_get_temp_dir(), 'narr');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) return response()->json(['message' => "Couldn't make the ZIP file."], 500);

        $clean = fn ($v) => trim(preg_replace('/[^A-Za-z0-9-]+/', '_', (string) $v), '_');
        $used = []; $added = 0; $missing = [];
        foreach ($rows as $r) {
            $bytes = null;
            try { $d = \App\Services\PrivateFiles::disk(); if ($d->exists($r->file_path)) $bytes = $d->get($r->file_path); } catch (\Throwable $e) { report($e); }
            if ($bytes === null) {
                try { $p = \App\Services\PrivateFiles::publicDisk(); if ($p->exists($r->file_path)) $bytes = $p->get($r->file_path); } catch (\Throwable $e) { report($e); }
            }
            if ($bytes === null) { $missing[] = $r->name; continue; }

            $prog = self::programKey($r->program) ?? 'Other';
            $parts = array_map('trim', explode(',', (string) $r->name, 2));   // "Hermoso, Vivien" or "Vivien Hermoso"
            $person = count($parts) === 2 ? $parts[0] . '_' . $parts[1] : $r->name;
            $ext = strtolower(pathinfo((string) ($r->original_name ?: $r->file_path), PATHINFO_EXTENSION)) ?: 'pdf';
            $base = implode('_', array_filter([$prog, $clean($person), $clean($r->student_id)]));
            $name = $base . '.' . $ext;
            for ($i = 2; isset($used[strtolower($name)]); $i++) $name = "{$base}_{$i}.{$ext}";
            $used[strtolower($name)] = true;
            $zip->addFromString($name, $bytes);
            $added++;
        }
        if ($missing) $zip->addFromString('_NOT_FOUND.txt', "These files could not be found on the server:\r\n- " . implode("\r\n- ", $missing) . "\r\n");
        $zip->close();

        if (!$added) { @unlink($tmp); return response()->json(['message' => "The files couldn't be found on the server."], 404); }

        $progs = $rows->map(fn ($r) => self::programKey($r->program))->filter()->unique()->values();
        $file = 'Narrative_Reports_' . ($progs->count() === 1 ? $progs[0] . '_' : '') . now()->format('Y-m-d') . '.zip';
        try { \App\Services\Activity::record($request->attributes->get('auth_user'), 'Narrative reports downloaded', "{$added} file(s) · {$file}", $request); } catch (\Throwable $e) { /* best-effort */ }

        return response()->download($tmp, $file, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private static function programKey(?string $program): ?string
    {
        $p = strtoupper((string) $program);
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) return 'BSIT';
        if (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) return 'BSIS';
        if (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) return 'BSCS';
        return null;
    }

    private function requiredHours(?string $program, $prior = 0): float
    {
        $p = strtoupper((string) $program);
        $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';

        // hours already rendered in an earlier term are taken off
        return max(0.0, (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 486)) - (float) $prior);
    }

    // Same rule as the dashboard (see app/Services/OjtHours.php)
    private function renderedHours(int $userId): float
    {
        return OjtHours::rendered($userId);
    }
}