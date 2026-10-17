<?php
//attendancecontroller.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\OjtHours;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // ── NFC Tap ───────────────────────────────────────────
    public function tap(Request $request)
    {
        // only the kiosk may record taps (stops anyone who knows a card UID from tapping for someone)
        $key = (string) config('ojt.kiosk_key');
        if ($key === '' || !hash_equals($key, (string) $request->header('X-Kiosk-Key'))) {
            return response()->json([
                'message' => $key === ''
                    ? 'The kiosk is not set up yet (KIOSK_KEY is missing on the server).'
                    : 'This device is not an authorized attendance kiosk.',
            ], 401);
        }

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

        // DTR type (set per intern, depends on the HTI):
        //   two  = 2 taps a day: in (AM) and out (PM), lunch break not counted
        //   four = 4 taps a day: AM in / AM out, PM in / PM out
        $mode     = ($intern->dtr_mode ?? 'two') === 'four' ? 'four' : 'two';
        $maxPairs = $mode === 'four' ? 2 : 1;

        // today's card taps only (approved online attendance is not a tap)
        $todayRows = DB::table('attendance_records')
            ->where('user_id', $card->user_id)
            ->where('date', $today)
            ->where('uid', '!=', 'ONLINE')
            ->orderBy('id')
            ->get();

        $openRecord = $todayRows->first(fn ($r) => $r->action === 'CHECK_IN' && !$r->checked_out_at);

        // ── accidental duplicate: a second tap within 5 minutes is refused with a warning ──
        $cooldown = (int) config('ojt.tap_cooldown_minutes', 5);
        $last = null;   // [timestamp, action] of the latest tap today
        foreach ($todayRows as $r) {
            foreach ([['CHECK_IN', $r->checked_in_at], ['CHECK_OUT', $r->checked_out_at]] as [$act, $t]) {
                if (!$t) continue;
                $ts = Carbon::parse($t, 'Asia/Manila')->getTimestamp();
                if (!$last || $ts > $last[0]) $last = [$ts, $act];
            }
        }
        if ($last && $now->getTimestamp() - $last[0] < $cooldown * 60) {
            $at   = Carbon::createFromTimestamp($last[0], 'Asia/Manila');
            $what = $last[1] === 'CHECK_IN' ? 'checked in' : 'checked out';
            return response()->json([
                'code'    => 'DUPLICATE_TAP',
                'name'    => $intern->name ?? null,
                'photo'   => $intern->photo ?? null,
                'message' => "You already {$what} at " . $at->format('h:i A') . '. '
                    . 'This tap was not recorded. If you need to tap again, wait until '
                    . $at->copy()->addMinutes($cooldown)->format('h:i A') . '.',
            ], 409);
        }

        // ── days and hours when a CHECK-IN is not allowed (config/ojt.php) ──
        // check-outs always go through, so an open record can still be closed
        if (!$openRecord) {
            $blocked = null;
            $window  = config('ojt.tap_window');
            $hourNow = $now->hour + $now->minute / 60;

            if (config('ojt.block_holidays', false)) {
                $event = DB::table('calendar_events')->whereDate('date', $today)->first();
                if ($event) {
                    $kind    = $event->type === 'holiday' ? 'a holiday' : 'a non-working day';
                    $blocked = "Today is {$kind} ({$event->title}). Check-ins are closed.";
                }
            }
            if (!$blocked && config('ojt.block_weekends', false) && $now->isWeekend()) {
                $blocked = 'Check-ins are closed on ' . $now->format('l') . 's.';
            }
            if (!$blocked && $window && ($hourNow < $window['from'] || $hourNow >= $window['to'])) {
                $from = $now->copy()->startOfDay()->addHours($window['from'])->format('g:i A');
                $to   = $now->copy()->startOfDay()->addHours($window['to'])->format('g:i A');
                $blocked = "Check-ins are only open from {$from} to {$to}.";
            }

            if ($blocked) {
                return response()->json([
                    'code'    => 'TAP_BLOCKED',
                    'name'    => $intern->name ?? null,
                    'photo'   => $intern->photo ?? null,
                    'message' => $blocked . ' This tap was not recorded.',
                ], 403);
            }
        }

        // no more check-ins once today's pairs are complete (2 taps or 4 taps, per HTI)
        if (!$openRecord && $todayRows->count() >= $maxPairs) {
            return response()->json([
                'message' => $mode === 'four'
                    ? 'You already completed your AM and PM time logs today.'
                    : 'You already checked out today.',
            ], 409);
        }

        $action = $openRecord ? 'CHECK_OUT' : 'CHECK_IN';
        $sched  = config('ojt.schedule');
        $at     = fn (float $h) => $now->copy()->startOfDay()->addMinutes((int) round($h * 60));
        $note   = null;

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
                'checked_in_at'  => $now,          // the real time is kept (the DTR shows it)
                'checked_out_at' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            // which official start applies: 1:00 PM for a 4-tap PM check-in, otherwise 8:00 AM
            $start = ($mode === 'four' && $now->hour >= 12) ? $sched['pm_in'] : $sched['am_in'];
            $startAt = $at($start);
            if ($now->greaterThan($startAt)) {
                $late = (int) floor(($now->getTimestamp() - $startAt->getTimestamp()) / 60);
                if ($late > 0) $note = "Late by {$late} min (official time in is " . $startAt->format('g:i A') . ').';
            } elseif ($now->lessThan($startAt)) {
                $note = 'Early arrival: your hours start counting at ' . $startAt->format('g:i A') . '.';
            }
        } else {
            $update = [
                'action'         => 'CHECK_OUT',
                'checked_out_at' => $now,
                'updated_at'     => $now,
            ];

            // time after 5:00 PM is overtime: it counts only if the coordinator approves it
            [, $otSeconds] = OjtHours::split($openRecord->checked_in_at, $now);
            $otMinutes = (int) floor($otSeconds / 60);
            if ($otMinutes >= (int) config('ojt.overtime_min_minutes', 15)) {
                $update['ot_minutes'] = $otMinutes;
                $update['ot_status']  = 'pending';
                $note = "Overtime of {$otMinutes} min was sent to your OJT coordinator for approval.";
            }

            DB::table('attendance_records')->where('id', $openRecord->id)->update($update);

            if (isset($update['ot_status'])) {
                Notifier::toRoles(
                    ['ojt_coordinator'],
                    'Overtime to Review',
                    ($intern->name ?? 'An intern') . " has {$otMinutes} min of overtime on "
                        . $now->format('M j, Y') . '.',
                    'overtime'
                );
            }
        }

        return response()->json([
            'action' => $action,
            'uid'    => $uid,
            'name'   => $intern->name ?? 'Unknown Intern',
            'photo'  => $intern->photo ?? null,   // the kiosk shows the intern's photo
            'token'  => $token,
            'time'   => $now->format('h:i:s A'),
            'date'   => $today,
            'note'   => $note,                    // late / early arrival / overtime message
        ]);
    }

    // ── Today's Records ───────────────────────────────────
        // ── Today's Records ───────────────────────────────────
    public function today()
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        return response()->json(
            DB::table('attendance_records as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.date', $today)
                ->orderByDesc('a.created_at')
                ->select('u.name', 'u.photo', 'a.*')
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
                ->select('u.name', 'u.photo', 'a.*')
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
            $query->select('u.name', 'u.email', 'u.photo', 'a.*')->get()
        );
    }

    // ── All Users (with the NFC card UID, if any) ─────────
    public function allUsers()
    {
        $rows = DB::table('users as u')
            ->leftJoin('nfc_cards as n', 'n.user_id', '=', 'u.id')
            ->orderBy('u.name')
            ->select('u.*', 'n.uid as nfc_uid')
            ->get();

        // never send password hashes to the browser
        return response()->json(
            $rows->map(function ($u) {
                unset($u->password, $u->remember_token);
                return $u;
            })->values()
        );
    }

    // ── Add User (with optional NFC card registration) ────
    public function addUser(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:100',
            'middle_name'    => 'nullable|string|max:100',
            'last_name'      => 'required|string|max:100',
            'student_id'     => 'required|string|max:50|unique:users,student_id',  // student ID, or employee ID for staff
            'contact_number' => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'placement'      => 'nullable|required_if:role,intern|string|max:150',
            'semester'       => 'nullable|required_if:role,intern|in:1st Semester,2nd Semester',
            'program'        => 'nullable|required_if:role,intern|in:BSIT - 4,BSCS - 4,BSIS - 4',
            'email'          => 'required|email|unique:users,email',
            'date_of_birth'  => 'required|date|before:today',
            'password'       => 'required|string|min:6',
            'role'           => 'required|in:intern,supervisor,admin,ojt_coordinator',
            'uid'            => 'nullable|string|max:64|unique:nfc_cards,uid',
            'work_mode'      => 'nullable|in:onsite,offsite',
            'tracking_type'  => 'nullable|in:hours,output',
            'dtr_mode'       => 'nullable|in:two,four',
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
            'student_id'     => trim($data['student_id']),
            'contact_number' => $data['contact_number'] ?? null,
            'address'        => $data['address'] ?? null,
            'date_of_birth'  => Carbon::parse($data['date_of_birth'])->toDateString(),
            'placement'      => $isIntern ? ($data['placement'] ?? null) : null,
            'semester'       => $isIntern ? ($data['semester'] ?? null) : null,
            'program'        => $isIntern ? ($data['program'] ?? null) : null,
            'college'        => $isIntern ? 'CCIS' : null,
            'password'       => Hash::make($data['password']),
            'role'           => $data['role'],
            'work_mode'      => $isIntern ? ($data['work_mode'] ?? 'onsite') : 'onsite',
            'tracking_type'  => $isIntern ? ($data['tracking_type'] ?? 'hours') : 'hours',
            'dtr_mode'       => $isIntern ? ($data['dtr_mode'] ?? 'two') : 'two',
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
            'dtr_mode'       => 'nullable|in:two,four',
            'uid'            => 'nullable|string|max:64',
            'date_of_birth'  => 'nullable|date|before:today',
            'password'       => 'nullable|string|min:6|max:100',   // admin sets a new password
        ]);

        // Who is asking (set by the sign-in check on the route)
        $auth    = $request->attributes->get('auth_user');
        $isAdmin = $auth && $auth->role === 'admin';

        if ($auth && !$isAdmin) {
            // interns can't edit accounts; a coordinator may edit interns (or themselves), not other staff
            if ($auth->role === 'intern'
                || ($user->role !== 'intern' && (int) $auth->id !== (int) $id)) {
                return response()->json(['message' => 'You are not allowed to edit this account.'], 403);
            }
            // only the admin may change these
            foreach (['email', 'student_id', 'date_of_birth', 'password'] as $k) {
                unset($data[$k]);
            }
        }

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

        // only touch these when they were actually sent (the profile panel doesn't send them)
        if (!empty($data['date_of_birth'])) {
            $update['date_of_birth'] = Carbon::parse($data['date_of_birth'])->toDateString();
        }
        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        if (isset($data['student_id']) && trim((string) $data['student_id']) !== '') {
            $update['student_id'] = trim($data['student_id']);   // student ID, or employee ID for staff
        }

        if ($isIntern) {
            $update['program']       = $data['program'] ?? null;
            $update['semester']      = $data['semester'] ?? null;
            $update['placement']     = $data['placement'] ?? null;
            $update['college']       = 'CCIS';
            $update['work_mode']     = $data['work_mode'] ?? $user->work_mode;
            $update['tracking_type'] = $data['tracking_type'] ?? $user->tracking_type;
            $update['dtr_mode']      = $data['dtr_mode'] ?? ($user->dtr_mode ?? 'two');
        }

        try {
            DB::transaction(function () use ($id, $update, $isIntern, $uid) {
                DB::table('users')->where('id', $id)->update($update);

                // a new password signs the user out everywhere
                if (isset($update['password']) && Schema::hasTable('api_tokens')) {
                    DB::table('api_tokens')->where('user_id', $id)->delete();
                }

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

        try {
            DB::transaction(function () use ($id) {
                // Delete everything that belongs to this user first (skips tables that don't exist yet)
                foreach (['nfc_cards', 'tokens', 'attendance_records', 'online_submissions',
                          'nfc_requests', 'notifications', 'account_recovery', 'api_tokens'] as $t) {
                    if (Schema::hasTable($t) && Schema::hasColumn($t, 'user_id')) {
                        DB::table($t)->where('user_id', $id)->delete();
                    }
                }

                // MOVs: remove the uploaded files too
                if (Schema::hasTable('movs')) {
                    $files = DB::table('movs')->where('user_id', $id)->pluck('file_path');
                    foreach ($files as $f) {
                        if ($f) Storage::disk('public')->delete($f);
                    }
                    DB::table('movs')->where('user_id', $id)->delete();

                    // if this user reviewed other people's MOVs, keep those but clear the reviewer
                    if (Schema::hasColumn('movs', 'reviewed_by')) {
                        DB::table('movs')->where('reviewed_by', $id)->update(['reviewed_by' => null]);
                    }
                }

                // Announcements they posted
                if (Schema::hasTable('announcements')) {
                    foreach (['user_id', 'posted_by', 'created_by'] as $col) {
                        if (Schema::hasColumn('announcements', $col)) {
                            DB::table('announcements')->where($col, $id)->delete();
                        }
                    }
                }

                DB::table('users')->where('id', $id)->delete();
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not delete user: ' . $e->getMessage()], 500);
        }

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