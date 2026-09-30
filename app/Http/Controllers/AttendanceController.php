<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // ── NFC Tap ───────────────────────────────────────────
    public function tap(Request $request)
    {
        $request->validate(['uid' => 'required|string|max:64']);

        $uid   = strtoupper(trim($request->uid));
        $today = Carbon::today('Asia/Manila')->toDateString();
        $now   = Carbon::now('Asia/Manila');

        $card = DB::table('nfc_cards')->where('uid', $uid)->first();
        if (!$card) {
            return response()->json([
                'message' => "Card UID [{$uid}] is not registered.",
            ], 404);    
        }

        $intern = DB::table('users')->where('id', $card->user_id)->first();

        $openRecord = DB::table('attendance_records')
            ->where('user_id', $card->user_id)
            ->where('date', $today)
            ->where('action', 'CHECK_IN')
            ->whereNull('checked_out_at')
            ->first();

        $action = $openRecord ? 'CHECK_OUT' : 'CHECK_IN';

        // UUID v4 bound: token:UID:date:action
        $token = (string) Str::uuid() . ':' . $uid . ':' . $today . ':' . $action;

        DB::table('tokens')->insert([
            'token'      => $token,
            'uid'        => $uid,
            'user_id'    => $card->user_id,
            'date'       => $today,
            'action'     => $action,
            'used'       => true,
            'created_at' => $now,
        ]);

        if ($action === 'CHECK_IN') {
            DB::table('attendance_records')->insert([
                'user_id'        => $card->user_id,
                'uid'            => $uid,
                'date'           => $today,
                'action'         => 'CHECK_IN',
                'token'          => $token,
                'checked_in_at'  => $now,
                'checked_out_at' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } else {
            DB::table('attendance_records')
                ->where('id', $openRecord->id)
                ->update([
                    'action'         => 'CHECK_OUT',
                    'checked_out_at' => $now,
                    'updated_at'     => $now,
                ]);
        }

        return response()->json([
            'action' => $action,
            'uid'    => $uid,
            'name'   => $intern->name ?? 'Unknown Intern',
            'token'  => $token,
            'time'   => $now->format('h:i:s A'),
            'date'   => $today,
        ]);
    }

    // ── Today's Records ───────────────────────────────────
    public function today()
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        return response()->json(
            DB::table('attendance_records as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.date', $today)
                ->orderByDesc('a.created_at')
                ->select('u.name', 'a.*')
                ->get()
        );
    }

    // ── Intern's Own Attendance ───────────────────────────
    public function internAttendance(Request $request)
    {
        return response()->json(
            DB::table('attendance_records as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.user_id', $request->query('user_id'))
                ->orderByDesc('a.date')
                ->select('u.name', 'a.*')
                ->get()
        );
    }

    // ── All Attendance (Admin) ────────────────────────────
    public function allAttendance(Request $request)
    {
        $query = DB::table('attendance_records as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->orderByDesc('a.created_at');

        if ($request->query('date')) {
            $query->where('a.date', $request->query('date'));
        }

        return response()->json(
            $query->select('u.name', 'u.email', 'a.*')->get()
        );
    }

    // ── All Users ─────────────────────────────────────────
    public function allUsers()
    {
        return response()->json(
            DB::table('users')->orderBy('name')->get()
        );
    }

    // ── Add User (with optional NFC card registration) ────
    public function addUser(Request $request)
{
    $request->validate([
        'name'          => 'required|string|max:100',
        'email'         => 'required|email|unique:users,email',
        'password'      => 'required|string|min:6',
        'role'          => 'required|in:intern,supervisor,admin,ojt_coordinator',
        'uid'           => 'nullable|string|max:64|unique:nfc_cards,uid',
        'work_mode'     => 'nullable|in:onsite,offsite',
        'tracking_type' => 'nullable|in:hours,output',
    ]);

    $now = Carbon::now('Asia/Manila');

    $userId = DB::table('users')->insertGetId([
        'name'          => $request->name,
        'email'         => $request->email,
        'password'      => $request->password,
        'role'          => $request->role,
        'work_mode'     => $request->role === 'intern' ? ($request->work_mode ?? 'onsite') : 'onsite',
        'tracking_type' => $request->role === 'intern' ? ($request->tracking_type ?? 'hours') : 'hours',
        'created_at'    => $now,
    ]);

    $nfcRegistered = false;

    if ($request->uid && $request->role === 'intern') {
        DB::table('nfc_cards')->insert([
            'user_id'    => $userId,
            'uid'        => strtoupper(trim($request->uid)),
            'created_at' => $now,
        ]);
        $nfcRegistered = true;
    }

    $message = 'User added successfully.';
    if ($nfcRegistered) {
        $message .= ' NFC card (UID: ' . strtoupper(trim($request->uid)) . ') registered.';
    }

    return response()->json([
        'message' => $message,
        'id'      => $userId,
    ]);
}

    // ── Delete User ───────────────────────────────────────
    public function deleteUser($id)
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Delete all related data first
        DB::table('nfc_cards')->where('user_id', $id)->delete();
        DB::table('tokens')->where('user_id', $id)->delete();
        DB::table('attendance_records')->where('user_id', $id)->delete();
        DB::table('online_submissions')->where('user_id', $id)->delete();
        DB::table('users')->where('id', $id)->delete();

        return response()->json([
            'message' => "User \"{$user->name}\" deleted successfully.",
        ]);
    }
    // ── Update Work Mode / Tracking Type ───────────────────
public function updateSettings(Request $request, $id)
{
    $request->validate([
        'work_mode'     => 'required|in:onsite,offsite',
        'tracking_type' => 'required|in:hours,output',
    ]);

    $user = DB::table('users')->where('id', $id)->first();
    if (!$user) return response()->json(['message' => 'User not found.'], 404);

    DB::table('users')->where('id', $id)->update([
        'work_mode'     => $request->work_mode,
        'tracking_type' => $request->tracking_type,
    ]);

    return response()->json(['message' => 'Intern settings updated.']);
}
}