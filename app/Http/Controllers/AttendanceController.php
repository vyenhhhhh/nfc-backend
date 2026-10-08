<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    // ── All Users (with the NFC card UID, if any) ─────────
    public function allUsers()
    {
        return response()->json(
            DB::table('users as u')
                ->leftJoin('nfc_cards as n', 'n.user_id', '=', 'u.id')
                ->orderBy('u.name')
                ->select('u.*', 'n.uid as nfc_uid')
                ->get()
        );
    }

    // ── Add User (with optional NFC card registration) ────
    public function addUser(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:100',
            'middle_name'    => 'nullable|string|max:100',
            'last_name'      => 'required|string|max:100',
            'student_id'     => 'nullable|required_if:role,intern|string|max:50|unique:users,student_id',
            'contact_number' => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'placement'      => 'nullable|required_if:role,intern|string|max:150',
            'semester'       => 'nullable|required_if:role,intern|in:1st Semester,2nd Semester',
            'program'        => 'nullable|required_if:role,intern|in:BSIT - 4,BSCS - 4,BSIS - 4',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:6',
            'role'           => 'required|in:intern,supervisor,admin,ojt_coordinator',
            'uid'            => 'nullable|string|max:64|unique:nfc_cards,uid',
            'work_mode'      => 'nullable|in:onsite,offsite',
            'tracking_type'  => 'nullable|in:hours,output',
            'photo'          => 'nullable|image|max:2048',
        ]);

        // Build the full name so the rest of the dashboard keeps working
        $fullName = trim(implode(' ', array_filter([
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
        ])));

        $isIntern = $data['role'] === 'intern';

        $now = Carbon::now('Asia/Manila');

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('profiles', 'public');
        }

        $userId = DB::table('users')->insertGetId([
            'name'           => $fullName,
            'first_name'     => $data['first_name'],
            'middle_name'    => $data['middle_name'] ?? null,
            'last_name'      => $data['last_name'],
            'email'          => $data['email'],
            'student_id'     => $isIntern ? ($data['student_id'] ?? null) : null,
            'contact_number' => $data['contact_number'] ?? null,
            'address'        => $data['address'] ?? null,
            'placement'      => $isIntern ? ($data['placement'] ?? null) : null,
            'semester'       => $isIntern ? ($data['semester'] ?? null) : null,
            'program'        => $isIntern ? ($data['program'] ?? null) : null,
            'college'        => $isIntern ? 'CCIS' : null,
            'password'       => Hash::make($data['password']),
            'role'           => $data['role'],
            'work_mode'      => $isIntern ? ($data['work_mode'] ?? 'onsite') : 'onsite',
            'tracking_type'  => $isIntern ? ($data['tracking_type'] ?? 'hours') : 'hours',
            'photo'          => $photoPath,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $nfcRegistered = false;

        if (!empty($data['uid']) && $isIntern) {
            DB::table('nfc_cards')->insert([
                'user_id'    => $userId,
                'uid'        => strtoupper(trim($data['uid'])),
                'created_at' => $now,
            ]);
            $nfcRegistered = true;
        }

        $message = 'User added successfully.';
        if ($nfcRegistered) {
            $message .= ' NFC card (UID: ' . strtoupper(trim($data['uid'])) . ') registered.';
        }

        return response()->json([
            'message' => $message,
            'id'      => $userId,
        ]);
    }

    // ── Update User (Manage Accounts → Edit) ──────────────
    public function updateUser(Request $request, $id)
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $data = $request->validate([
            'first_name'     => 'required|string|max:100',
            'middle_name'    => 'nullable|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'sometimes|required|email|unique:users,email,' . $id,
            'student_id'     => 'nullable|string|max:50|unique:users,student_id,' . $id,
            'contact_number' => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'program'        => 'nullable|in:BSIT - 4,BSCS - 4,BSIS - 4',
            'semester'       => 'nullable|in:1st Semester,2nd Semester',
            'placement'      => 'nullable|string|max:150',
            'work_mode'      => 'nullable|in:onsite,offsite',
            'tracking_type'  => 'nullable|in:hours,output',
            'uid'            => 'nullable|string|max:64',
        ]);

        $isIntern = $user->role === 'intern';
        $uid      = strtoupper(trim($data['uid'] ?? ''));

        // the card can't already belong to someone else
        if ($isIntern && $uid !== '') {
            $taken = DB::table('nfc_cards')
                ->where('uid', $uid)
                ->where('user_id', '!=', $id)
                ->exists();

            if ($taken) {
                return response()->json([
                    'message' => "Card UID [{$uid}] is already assigned to another user.",
                ], 422);
            }
        }

        $fullName = trim(implode(' ', array_filter([
            $data['first_name'], $data['middle_name'] ?? null, $data['last_name'],
        ])));

        $update = [
            'name'           => $fullName,
            'first_name'     => $data['first_name'],
            'middle_name'    => $data['middle_name'] ?? null,
            'last_name'      => $data['last_name'],
            'email'          => $data['email'] ?? $user->email,
            'contact_number' => $data['contact_number'] ?? null,
            'address'        => $data['address'] ?? null,
            'updated_at'     => Carbon::now('Asia/Manila'),
        ];

        if ($isIntern) {
            $update['student_id']    = $data['student_id'] ?? null;
            $update['program']       = $data['program'] ?? null;
            $update['semester']      = $data['semester'] ?? null;
            $update['placement']     = $data['placement'] ?? null;
            $update['college']       = 'CCIS';
            $update['work_mode']     = $data['work_mode'] ?? $user->work_mode;
            $update['tracking_type'] = $data['tracking_type'] ?? $user->tracking_type;
        }

        try {
            DB::transaction(function () use ($id, $update, $isIntern, $uid) {
                DB::table('users')->where('id', $id)->update($update);

                if ($isIntern) {
                    $card = DB::table('nfc_cards')->where('user_id', $id)->first();

                    if ($uid === '') {
                        // field cleared: unlink the card
                        DB::table('nfc_cards')->where('user_id', $id)->delete();
                    } elseif ($card) {
                        DB::table('nfc_cards')->where('user_id', $id)->update(['uid' => $uid]);
                    } else {
                        DB::table('nfc_cards')->insert([
                            'user_id'    => $id,
                            'uid'        => $uid,
                            'created_at' => Carbon::now('Asia/Manila'),
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Database error: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'User updated.',
            'name'    => $fullName,
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

    // ── Update Work Mode / Tracking Type ──────────────────
    public function updateSettings(Request $request, $id)
    {
        $request->validate([
            'work_mode'     => 'required|in:onsite,offsite',
            'tracking_type' => 'required|in:hours,output',
        ]);

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        DB::table('users')->where('id', $id)->update([
            'work_mode'     => $request->work_mode,
            'tracking_type' => $request->tracking_type,
        ]);

        return response()->json(['message' => 'Intern settings updated.']);
    }

    // ── Update User Photo ─────────────────────────────────
    public function updatePhoto(Request $request, $id)
    {
        $request->validate([
            'photo' => 'required|image|max:2048',
        ]);

        $user = DB::table('users')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->photo) {
            Storage::disk('public')->delete($user->photo);
        }

        $photoPath = $request->file('photo')->store('profiles', 'public');

        DB::table('users')
            ->where('id', $id)
            ->update([
                'photo' => $photoPath,
            ]);

        return response()->json([
            'message' => 'Photo updated.',
            'photo'   => $photoPath,
        ]);
    }
}