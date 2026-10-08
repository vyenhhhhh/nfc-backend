<?php
//MovController.php
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
            'file'    => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB
        ]);

        $path = $request->file('file')->store('movs', 'public');

        $id = DB::table('movs')->insertGetId([
            'user_id'       => $request->user_id,
            'title'         => $request->title,
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
}