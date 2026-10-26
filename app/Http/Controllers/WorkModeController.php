<?php
//WorkModeController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Work arrangement requests (from the OJT coordinator's answers):
 * WFH needs a letter from the HTE approving it, plus the student's request — unless WFH is already
 * stated in the MOA or the Student Internship Contract. The intern's HTE supervisor approves (final —
 * the OJT coordinator is not involved), then the intern's work mode changes right away.
 * Going back to onsite works the same way (no letter needed).
 */
class WorkModeController extends Controller
{
    // GET /api/intern/work-mode-requests  -> the signed-in intern's requests
    public function mine(Request $request)
    {
        $uid = $request->attributes->get('auth_user')->id;
        return response()->json(
            DB::table('work_mode_requests')->where('user_id', $uid)->orderByDesc('id')->get()
        );
    }

    // POST /api/intern/work-mode-requests   (multipart) requested_mode, reason, in_moa?, file?
    public function store(Request $request)
    {
        $data = $request->validate([
            'requested_mode' => 'required|in:offsite,onsite',
            'reason'         => 'required|string|max:500',
            'in_moa'         => 'nullable|boolean',
            'file'           => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);
        $auth   = $request->attributes->get('auth_user');
        $intern = DB::table('users')->where('id', $auth->id)->first();
        $inMoa  = (bool) ($data['in_moa'] ?? false);

        if ($auth->role !== 'intern') {
            return response()->json(['message' => 'Only interns can request a work arrangement.'], 403);
        }
        if (($intern->work_mode ?? 'onsite') === $data['requested_mode']) {
            return response()->json([
                'message' => $data['requested_mode'] === 'offsite' ? 'You are already set to work from home.' : 'You are already set to onsite.',
            ], 422);
        }
        // WFH: the HTE's letter is required unless WFH is already in the MOA / Student Contract
        if ($data['requested_mode'] === 'offsite' && !$inMoa && !$request->hasFile('file')) {
            return response()->json([
                'message' => "Attach the HTE's letter approving your WFH (or tick that WFH is stated in your MOA / Student Contract).",
            ], 422);
        }
        if (!Supervision::supervisorOf($auth->id)) {
            return response()->json([
                'message' => "You don't have an HTE supervisor assigned yet. Ask your OJT coordinator to assign one first.",
            ], 422);
        }
        if (DB::table('work_mode_requests')->where('user_id', $auth->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'You already have a request waiting for review.'], 409);
        }

        $path = $request->hasFile('file') ? $request->file('file')->store('work_mode', config('filesystems.uploads', 'public')) : null;
        $now  = Carbon::now('Asia/Manila');
        $id   = DB::table('work_mode_requests')->insertGetId([
            'user_id'        => $auth->id,
            'requested_mode' => $data['requested_mode'],
            'reason'         => $data['reason'],
            'in_moa'         => $inMoa,
            'file_path'      => $path,
            'original_name'  => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        Supervision::notifyReviewer($auth->id, 'Work Arrangement Request',
            ($intern->name ?? 'An intern') . ' asks to ' . ($data['requested_mode'] === 'offsite' ? 'work from home' : 'go back onsite') . '.',
            'work_mode_request');

        return response()->json(['message' => 'Request sent. Your HTE supervisor will review it.', 'id' => $id]);
    }

    // DELETE /api/intern/work-mode-requests/{id}  -> remove your own request while it is still pending
    public function destroy(Request $request, $id)
    {
        $auth = $request->attributes->get('auth_user');
        $row  = DB::table('work_mode_requests')->where('id', $id)->first();
        if (!$row || (int) $row->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if ($row->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be removed.'], 409);
        }
        if ($row->file_path) Storage::disk(config('filesystems.uploads', 'public'))->delete($row->file_path);
        DB::table('work_mode_requests')->where('id', $id)->delete();
        return response()->json(['message' => 'Request removed.']);
    }

    // GET /api/supervisor/work-mode-requests  -> my interns' requests, pending first
    public function index(Request $request)
    {
        $scope = Supervision::scope($request->attributes->get('auth_user')) ?? [];

        return response()->json(
            DB::table('work_mode_requests as w')
                ->join('users as u', 'u.id', '=', 'w.user_id')
                ->whereIn('w.user_id', $scope)
                ->leftJoin('users as r', 'r.id', '=', 'w.reviewed_by')
                ->select('w.*', 'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo',
                    'u.placement', 'u.work_mode as current_mode', 'r.name as reviewer_name')
                ->orderByRaw("CASE w.status WHEN 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('w.id')
                ->get()
        );
    }

    // POST /api/supervisor/work-mode-requests/{id}/review   { status: approved|rejected, remarks? }
    // Final: approving changes the intern's work mode right away.
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);
        $auth = $request->attributes->get('auth_user');
        $req  = DB::table('work_mode_requests')->where('id', $id)->first();
        if (!$req || !Supervision::supervises($auth, $req->user_id)) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $now = Carbon::now('Asia/Manila');
        $changed = DB::transaction(function () use ($req, $data, $auth, $now) {
            $n = DB::table('work_mode_requests')->where('id', $req->id)->where('status', 'pending')->update([
                'status'      => $data['status'],
                'remarks'     => $data['remarks'] ?? null,
                'reviewed_by' => $auth->id ?? null,
                'reviewed_at' => $now,
                'updated_at'  => $now,
            ]);
            if ($n && $data['status'] === 'approved') {
                DB::table('users')->where('id', $req->user_id)->update([
                    'work_mode'  => $req->requested_mode,
                    'updated_at' => $now,
                ]);
            }
            return $n;
        });
        if (!$changed) {
            return response()->json(['message' => 'This request was already reviewed.'], 409);
        }

        $what = $req->requested_mode === 'offsite' ? 'work from home' : 'go back onsite';
        Notifier::toUser($req->user_id,
            $data['status'] === 'approved' ? 'Work Arrangement Approved' : 'Work Arrangement Rejected',
            ($data['status'] === 'approved' ? "Your supervisor approved it. You can now {$what}." : "Your supervisor did not approve your request to {$what}.")
                . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''),
            'work_mode_review');

        return response()->json(['message' => $data['status'] === 'approved' ? 'Approved. The work mode was updated.' : 'Request rejected.']);
    }
}