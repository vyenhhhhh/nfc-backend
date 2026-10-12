<?php

namespace App\Http\Controllers;

use App\Services\Notifier;
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
            'phase'       => 'nullable|in:pre,post',
            'file'    => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB
        ]);

        $phase       = $request->input('phase', 'pre');
        $requirement = $request->input('requirement') ?: 'others';

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

        // one live submission per requirement (a rejected one can be re-uploaded); "others" can repeat
        if ($requirement !== 'others') {
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

        $path = $request->file('file')->store('movs', 'public');

        $id = DB::table('movs')->insertGetId([
            'user_id'       => $request->user_id,
            'title'         => $request->title,
            'requirement'   => $requirement,
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
            ($intern->name ?? 'An intern') . ' submitted "' . $request->title . '" for review.',
            'mov_submission'
        );

        return response()->json(['message' => 'MOV submitted successfully.', 'id' => $id]);
    }

    // Coordinator views all submissions
    public function index()
    {
        $movs = DB::table('movs as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->orderByDesc('m.created_at')
            ->select('m.*', 'u.name as intern_name', 'u.email as intern_email')
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
            'reviewer_id' => 'required|integer|exists:users,id',
        ]);

        $mov = DB::table('movs')->where('id', $id)->first();
        if (!$mov) return response()->json(['message' => 'Submission not found.'], 404);

        DB::table('movs')->where('id', $id)->update([
            'status'      => $request->status,
            'remarks'     => $request->remarks,
            'reviewed_by' => $request->reviewer_id,
            'reviewed_at' => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

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

    // Required hours for a program such as "BSIT - 4" (set in config/ojt.php)
    private function requiredHours(?string $program): float
    {
        $p = strtoupper((string) $program);
        $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';

        return (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 486));
    }

    // Same rule as the dashboard: per day, first time in to first time out
    private function renderedHours(int $userId): float
    {
        $rows = DB::table('attendance_records')
            ->where('user_id', $userId)
            ->orderBy('date')->orderBy('id')
            ->get(['date', 'checked_in_at', 'checked_out_at']);

        $days = [];
        foreach ($rows as $r) {
            $d = (string) $r->date;
            if (!isset($days[$d])) $days[$d] = ['in' => null, 'out' => null];
            if ($r->checked_in_at && !$days[$d]['in'])   $days[$d]['in']  = $r->checked_in_at;
            if ($r->checked_out_at && !$days[$d]['out']) $days[$d]['out'] = $r->checked_out_at;
        }

        $total = 0;
        foreach ($days as $t) {
            if ($t['in'] && $t['out']) $total += (strtotime($t['out']) - strtotime($t['in'])) / 3600;
        }
        return round($total, 1);
    }
}