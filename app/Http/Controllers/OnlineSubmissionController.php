<?php

namespace App\Http\Controllers;

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

        $now = Carbon::now('Asia/Manila');

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

        return response()->json([
            'message' => 'Online attendance submitted successfully.',
            'id'      => $id,
        ]);
    }

    // Supervisor gets pending submissions
    public function pending(Request $request)
    {
        $submissions = DB::table('online_submissions as os')
            ->join('users as u', 'u.id', '=', 'os.user_id')
            ->where('os.status', 'pending')
            ->orderByDesc('os.created_at')
            ->select('os.*', 'u.name as intern_name', 'u.email as intern_email')
            ->get();

        return response()->json($submissions);
    }

    // Supervisor validates or rejects a submission
    public function validate(Request $request)
    {
        $request->validate([
            'submission_id'  => 'required|integer',
            'supervisor_id'  => 'required|integer',
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

        // Update submission status
        DB::table('online_submissions')
            ->where('id', $request->submission_id)
            ->update([
                'status'     => $request->action,
                'updated_at' => $now,
            ]);

        // Log the validation action
        DB::table('validation_logs')->insert([
            'submission_id' => $request->submission_id,
            'supervisor_id' => $request->supervisor_id,
            'action'        => $request->action,
            'remarks'       => $request->remarks ?? '',
            'created_at'    => $now,
        ]);

        // If approved, create an attendance record
        if ($request->action === 'approved') {
            DB::table('attendance_records')->insert([
                'user_id'        => $submission->user_id,
                'uid'            => 'ONLINE',
                'date'           => $submission->date,
                'action'         => 'CHECK_IN',
                'token'          => 'ONLINE-VALIDATED-' . $request->submission_id,
                'checked_in_at'  => $now,
                'checked_out_at' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        return response()->json([
            'message' => 'Submission ' . $request->action . ' successfully.',
        ]);
    }
}