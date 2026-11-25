<?php
//attendancecontroller.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\CardSecurity;
use App\Services\Notifier;
use App\Services\OjtHours;
use App\Services\Supervision;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // ── NFC Tap ───────────────────────────────────────────
    public function tap(Request $request)
    {
        // only a registered kiosk may record taps (stops anyone who knows a card UID from tapping for someone).
        // Each kiosk has its own key (Accounts → Kiosks); KIOSK_KEY in .env still works as the main CCIS kiosk.
        $sent  = (string) $request->header('X-Kiosk-Key');
        $kiosk = AccountController::kioskFor($sent);
        if (!$kiosk['ok']) {
            return response()->json([
                'message' => $sent === ''
                    ? 'The kiosk is not set up yet. Ask the admin for this kiosk\'s key.'
                    : 'This device is not an authorized attendance kiosk.',
            ], 401);
        }
        $kiosk = $kiosk['kiosk'];   // null = main CCIS kiosk

        $request->validate([
            'uid'    => 'required|string|max:64',
            'card'   => 'nullable|string|max:200',            // what is written on the card (phone / ACR122U kiosks)
            'writer' => 'nullable|string|in:webnfc,acr122u',  // this kiosk can write the next one-time code
            'offline_at' => 'nullable|date',                  // a tap the kiosk saved while it was offline
        ]);

        $uid   = strtoupper(trim($request->uid));
        $today = Carbon::today('Asia/Manila')->toDateString();
        $now   = Carbon::now('Asia/Manila');

        // ── a tap saved on the kiosk while the internet was down: it counts at the time it really happened ──
        $offline = null;
        if ($request->filled('offline_at')) {
            $offline = Carbon::parse($request->input('offline_at'))->setTimezone('Asia/Manila');
            $maxAge  = (int) config('ojt.offline_tap_max_hours', 72);
            if ($offline->greaterThan($now->copy()->addMinutes(2)) || $offline->lessThan($now->copy()->subHours($maxAge))) {
                return response()->json([
                    'code'    => 'OFFLINE_TOO_OLD',
                    'message' => "This saved tap is older than {$maxAge} hours (or has a wrong time), so it was not recorded. Ask the coordinator to add it.",
                ], 422);
            }
            $now   = $offline;
            $today = $offline->toDateString();
            // the card was read (not re-written) while offline: its one-time code is checked now, and no new code is issued
            $request->merge([
                'offline_card'   => $request->input('card'),
                'offline_writer' => $request->input('writer'),
                'writer' => null, 'card' => null, 'offline_sync' => true,
            ]);
        }

        // find the card (any UID format) and check its one-time code: a copied card is refused and flagged
        $checked = CardSecurity::check($request, $uid, $kiosk);
        if (!$checked['ok']) {
            return response()->json($checked['body'], $checked['status']);
        }
        $card = $checked['card'];
        $uid  = strtoupper((string) $card->uid);   // keep the UID as it was registered
        if ($offline) {
            Activity::record(DB::table('users')->where('id', $card->user_id)->first(), 'Offline tap sent',
                'Saved on kiosk ' . ($kiosk->name ?? 'CCIS (main)') . ' at ' . $offline->format('M j, g:i A') . ', sent when it was back online.', $request);
        }

        // one tap at a time for the same intern: a double read or two kiosks at the same moment
        // can't both pass the checks below and record two time-ins
        $lock = Cache::lock('tap-user-' . $card->user_id, 15);
        if (!$lock->block(5)) {
            return response()->json(['code' => 'DUPLICATE_TAP', 'message' => 'Your tap is still being processed. Please wait a moment.'], 409);
        }
        try {

        $intern = DB::table('users')->where('id', $card->user_id)->first();

        // graduated / deactivated accounts can't tap
        if (!empty($intern->deactivated_at ?? null)) {
            Activity::record($intern, 'Tap refused: account deactivated', 'kiosk: ' . ($kiosk->name ?? 'CCIS (main)'), $request);
            return response()->json([
                'code' => 'TAP_BLOCKED', 'name' => $intern->name ?? null, 'photo' => $intern->photo ?? null,
                'message' => 'This account is deactivated. This tap was not recorded.',
            ], 403);
        }
        // an HTE kiosk only accepts the interns placed at that HTE (CCIS kiosks accept everyone)
        if (!AccountController::kioskAllows($kiosk, $intern)) {
            Activity::record($intern, 'Tap refused: wrong kiosk', "kiosk: {$kiosk->name} ({$kiosk->placement}) · intern placed at: " . ($intern->placement ?: 'none'), $request);
            return response()->json([
                'code' => 'TAP_BLOCKED', 'name' => $intern->name ?? null, 'photo' => $intern->photo ?? null,
                'message' => "This kiosk is only for interns placed at {$kiosk->placement}. Please tap at your own HTE's kiosk or at CCIS. This tap was not recorded.",
            ], 403);
        }

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
        if ($last && abs($now->getTimestamp() - $last[0]) < $cooldown * 60) {
            $at   = Carbon::createFromTimestamp($last[0], 'Asia/Manila');
            $what = $last[1] === 'CHECK_IN' ? 'timed in' : 'timed out';
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

            // the internship starts only when ALL pre-internship requirements are approved
            $missing = Supervision::missingPreRequirements($card->user_id);
            if (count($missing) > 0) {
                $total   = count(\App\Services\Requirements::preKeys());
                $blocked = 'Your internship has not started yet: ' . ($total - count($missing)) . " of {$total} "
                    . 'pre-internship requirements are approved. Complete them under Submit MOV and wait for '
                    . 'your OJT coordinator to approve them.';
            }

            if (!$blocked && config('ojt.block_holidays', false)) {
                $event = \App\Services\CalendarDays::eventFor($today, $card->user_id);   // everyone's days off + this intern's HTE days off
                if ($event) {
                    $kind    = $event->type === 'holiday' ? 'a holiday' : 'a non-working day';
                    $blocked = "Today is {$kind} ({$event->title}). Time-ins are closed.";
                }
            }
            // an HTE that marked this day "We're open" (even a Saturday / Sunday) lets its interns time in
            $hteOpen = $now->isWeekend() && \App\Services\CalendarDays::hteOpen($today, $card->user_id);
            if (!$blocked && !$hteOpen && config('ojt.block_weekends', false) && $now->isWeekend()) {
                $blocked = 'Time-ins are closed on ' . $now->format('l') . 's.';
            }
            if (!$blocked && $window && ($hourNow < $window['from'] || $hourNow >= $window['to'])) {
                $from = $now->copy()->startOfDay()->addHours($window['from'])->format('g:i A');
                $to   = $now->copy()->startOfDay()->addHours($window['to'])->format('g:i A');
                $blocked = "Time-ins are only open from {$from} to {$to}.";
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
                    : 'You already timed out today.',
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

        // a tap the kiosk saved while it was offline is marked, so the coordinator can see it
        // (php artisan migrate adds the columns; remembered for an hour so a tap doesn't ask every time)
        $markOffline = $offline && \Illuminate\Support\Facades\Cache::remember('att_offline_cols', 3600,
            fn () => \App\Services\SchemaCache::has('attendance_records', 'offline_in'));
        // which kiosk recorded this tap ("main" = the CCIS key in .env): the kiosk screen and the admin's kiosk history use it
        $kioskCols = \Illuminate\Support\Facades\Cache::remember('att_kiosk_cols', 3600,
            fn () => \App\Services\SchemaCache::has('attendance_records', 'kiosk_in'));
        $kioskTag  = AccountController::kioskTag($kiosk);

        if ($action === 'CHECK_IN') {
            DB::table('attendance_records')->insert(array_merge([
                'user_id'        => $card->user_id,
                'uid'            => $uid,
                'date'           => $today,
                'action'         => 'CHECK_IN',
                'token'          => $token,
                'checked_in_at'  => $now,          // the real time is kept (the DTR shows it)
                'checked_out_at' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $markOffline ? ['offline_in' => true] : [], $kioskCols ? ['kiosk_in' => $kioskTag] : []));

            // which official start applies: 1:00 PM for a 4-tap PM check-in, otherwise 8:00 AM
            $start = ($mode === 'four' && $now->hour >= 12) ? $sched['pm_in'] : $sched['am_in'];
            $startAt = $at($start);
            if ($now->greaterThan($startAt)) {
                $late = (int) floor(($now->getTimestamp() - $startAt->getTimestamp()) / 60);
                if ($late > 0) $note = "Late by {$late} min (official start time is " . $startAt->format('g:i A') . ').';
            } elseif ($now->lessThan($startAt)) {
                $note = 'Early arrival: your hours start counting at ' . $startAt->format('g:i A') . '.';
            }
        } else {
            $update = [
                'action'         => 'CHECK_OUT',
                'checked_out_at' => $now,
                'updated_at'     => $now,
            ];
            if ($markOffline) $update['offline_out'] = true;
            if ($kioskCols) $update['kiosk_out'] = $kioskTag;

            // time after 5:00 PM is overtime: it counts only if the coordinator approves it
            [, $otSeconds] = OjtHours::split($openRecord->checked_in_at, $now);
            $otMinutes = (int) floor($otSeconds / 60);
            if ($otMinutes >= (int) config('ojt.overtime_min_minutes', 15)) {
                $update['ot_minutes'] = $otMinutes;
                $update['ot_status']  = 'pending';
                $note = "Overtime of {$otMinutes} min was sent to your HTE supervisor for review.";
            }

            DB::table('attendance_records')->where('id', $openRecord->id)->update($update);

            if (isset($update['ot_status'])) {
                // the intern's supervisor reviews it first (the coordinators, if no supervisor is assigned)
                Supervision::notifyReviewer(
                    $card->user_id,
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
            // phone / ACR122U kiosks: the next one-time code to write on the card, then POST /tap/confirm
            'card_write' => CardSecurity::nextToken($request, $card),
            'secured'    => $checked['secured'],
        ]);

        } finally {
            $lock->release();
        }
    }

    // ── the kiosk wrote the new one-time code on the card ──
    public function confirmCardWrite(Request $request)
    {
        $kiosk = AccountController::kioskFor((string) $request->header('X-Kiosk-Key'));
        if (!$kiosk['ok']) return response()->json(['message' => 'This device is not an authorized attendance kiosk.'], 401);
        $request->validate(['card' => 'required|string|max:200']);
        $ok = CardSecurity::confirm($request->input('card'));
        return response()->json(['ok' => $ok], $ok ? 200 : 409);
    }

    // ── Today's Records ───────────────────────────────────
    public function today(Request $request)
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        $scope = Supervision::scope($request->attributes->get('auth_user'));   // a supervisor: own interns only
        return response()->json(
            DB::table('attendance_records as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.date', $today)
                ->when($scope !== null, fn ($q) => $q->whereIn('a.user_id', $scope))
                ->orderByDesc('a.created_at')
                ->select('u.name', 'u.photo', 'a.*')
                ->get()
        );
    }

    // ── Intern's Own Attendance ───────────────────────────
    public function internAttendance(Request $request)
    {
        // interns: only their own (ApiTokenAuth). HTE supervisors: only their own interns.
        $scope = Supervision::scope($request->attributes->get('auth_user'));
        if ($scope !== null && !in_array((int) $request->query('user_id'), array_map('intval', (array) $scope), true)) {
            return response()->json(['message' => 'You can only view the attendance of your own interns.'], 403);
        }
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

        // a supervisor only sees their own interns
        $scope = Supervision::scope($request->attributes->get('auth_user'));
        if ($scope !== null) {
            $query->whereIn('a.user_id', $scope);
        }

        return response()->json(
            $query->select('u.name', 'u.email', 'u.photo', 'a.*')->get()
        );
    }

    // POST /api/me/ping: the portal is open for this account (sent about once a minute)
    public function ping(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        if ($auth && \App\Services\SchemaCache::has('users', 'last_seen_at')) {
            DB::table('users')->where('id', $auth->id)->update(['last_seen_at' => Carbon::now('Asia/Manila')]);
        }
        return response()->json(['ok' => true]);
    }

    // ── All Users (with the NFC card UID, if any) ─────────
    public function allUsers(Request $request)
    {
        $auth    = $request->attributes->get('auth_user');
        $isAdmin = $auth && $auth->role === 'admin';

        // a supervisor only sees their own interns (and themselves)
        $scope = Supervision::scope($auth);

        $rows = DB::table('users as u')
            ->leftJoin('nfc_cards as n', 'n.user_id', '=', 'u.id')
            ->leftJoin('users as s', 's.id', '=', 'u.supervisor_id')
            ->when($scope !== null, fn ($q) => $q->where(function ($w) use ($scope, $auth) {
                $w->whereIn('u.id', $scope)->orWhere('u.id', $auth->id);
            }))
            ->orderBy('u.name')
            ->select('u.*', 'n.uid as nfc_uid', 's.name as supervisor_name')
            ->get();

        // never send password hashes to the browser
        return response()->json(
            $rows->map(function ($u) use ($isAdmin) {
                unset($u->password, $u->remember_token);
                if (!$isAdmin) unset($u->date_of_birth);   // only the admin sees birthdays
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
            'name_extension' => 'nullable|string|max:20',   // Jr., Sr., II, III ...
            'student_id'     => 'required|string|max:50|unique:users,student_id',  // student ID, or employee ID for staff
            'contact_number' => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'placement'      => 'nullable|required_if:role,intern|required_if:role,supervisor|string|max:150',  // intern: placement · supervisor: their HTE
            'semester'       => 'nullable|required_if:role,intern|in:1st Semester,2nd Semester',
            'program'        => 'nullable|required_if:role,intern|in:BSIT - 4,BSCS - 4,BSIS - 4',
            'email'          => 'required|email|unique:users,email',
            'date_of_birth'  => 'required|date|before:today',
            'password'       => 'required|string|min:8',
            'role'           => 'required|in:intern,supervisor,admin,ojt_coordinator',
            'uid'            => 'nullable|string|max:64|unique:nfc_cards,uid',
            'work_mode'      => 'nullable|in:onsite,offsite',
            'tracking_type'  => 'nullable|in:hours,output',
            'modality'       => 'nullable|in:onsite,hybrid,virtual,project,hte',   // hte = follow the HTE's modality
            'hte_modality'   => 'nullable|in:onsite,hybrid,virtual,project',       // supervisor: the modality of their HTE
            'hte_category'   => 'nullable|string|max:60',                          // supervisor: the kind of HTE (IT Company, Hospital, ...)
            'scope'          => 'nullable|in:local,out_of_region,inbound,outbound',
            'dtr_mode'       => 'nullable|in:two,four',
            'supervisor_id'  => 'nullable|integer|exists:users,id',
            'photo'          => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'signature'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'position'        => 'nullable|string|max:150',
            'hte_address'     => 'nullable|string|max:255',
            'moa_valid_until' => 'nullable|date',
            'output_required' => 'nullable|boolean',              // supervisor: the HTE requires a system output presentation
            'prior_hours'     => 'nullable|numeric|min:0|max:1000', // intern: hours rendered in an earlier term
            'emergency_contact_name'     => 'nullable|string|max:150',
            'emergency_contact_relation' => 'nullable|string|max:60',
            'emergency_contact_number'   => 'nullable|string|max:20',
            'emergency_contact_address'  => 'nullable|string|max:255',
        ]);

        // the OJT coordinator may add interns and HTE supervisors only (never admins or other coordinators)
        $actor = $request->attributes->get('auth_user');
        if (($actor->role ?? null) === 'ojt_coordinator' && !in_array($data['role'], ['intern', 'supervisor'], true)) {
            return response()->json(['message' => 'You can add interns and HTE supervisors. Ask the admin to add other roles.'], 403);
        }

        if (!empty($data['supervisor_id'])
            && DB::table('users')->where('id', $data['supervisor_id'])->value('role') !== 'supervisor') {
            return response()->json(['message' => 'The chosen supervisor is not a supervisor account.'], 422);
        }

        // Build the full name so the rest of the dashboard keeps working
        $fullName = trim(implode(' ', array_filter([
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
            $data['name_extension'] ?? null,
        ])));

        $isIntern = $data['role'] === 'intern';

        // an intern follows their HTE's modality unless the coordinator picked a different one
        $followHte = false;
        if ($isIntern && \App\Services\HteModality::ready()) {
            $hteMod = \App\Services\HteModality::forIntern((object) ['supervisor_id' => $data['supervisor_id'] ?? null, 'placement' => $data['placement'] ?? null]);
            if (empty($data['modality']) || $data['modality'] === 'hte') { $data['modality'] = $hteMod ?: 'onsite'; $followHte = true; }
            else $followHte = !$hteMod || $data['modality'] === $hteMod;
        } elseif (($data['modality'] ?? null) === 'hte') {
            $data['modality'] = 'onsite';
        }

        $now = Carbon::now('Asia/Manila');

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('profiles', config('filesystems.uploads', 'public'));
        }

        $userId = DB::table('users')->insertGetId([
            'name'           => $fullName,
            'first_name'     => $data['first_name'],
            'middle_name'    => $data['middle_name'] ?? null,
            'last_name'      => $data['last_name'],
            'name_extension' => trim((string) ($data['name_extension'] ?? '')) ?: null,
            'email'          => $data['email'],
            'student_id'     => trim($data['student_id']),
            'contact_number' => $data['contact_number'] ?? null,
            'address'        => $data['address'] ?? null,
            'date_of_birth'  => Carbon::parse($data['date_of_birth'])->toDateString(),
            // intern: where they are placed · supervisor: the HTE (company/office) they work for
            'placement'      => ($isIntern || ($data['role'] ?? '') === 'supervisor') ? ($data['placement'] ?? null) : null,
            'semester'       => $isIntern ? ($data['semester'] ?? null) : null,
            'program'        => $isIntern ? ($data['program'] ?? null) : null,
            'college'        => $isIntern ? 'CCIS' : null,
            'password'       => Hash::make($data['password']),
            'role'           => $data['role'],
            // the modality decides it (interns don't ask for work from home): virtual = offsite (no kiosk, Submit Online)
            'work_mode'      => $isIntern ? ((($data['modality'] ?? 'onsite') === 'virtual') ? 'offsite' : ($data['work_mode'] ?? 'onsite')) : 'onsite',
            'tracking_type'  => 'hours',   // every intern renders the required hours of their program
            'dtr_mode'       => $isIntern ? ($data['dtr_mode'] ?? 'two') : 'two',
            'supervisor_id'  => $isIntern ? ($data['supervisor_id'] ?? null) : null,
            'emergency_contact_name'     => $data['emergency_contact_name'] ?? null,
            'emergency_contact_relation' => $data['emergency_contact_relation'] ?? null,
            'emergency_contact_number'   => $data['emergency_contact_number'] ?? null,
            'emergency_contact_address'  => $data['emergency_contact_address'] ?? null,
            'photo'          => $photoPath,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // the internship modality and scope (when the columns exist: php artisan migrate)
        if ($isIntern && \App\Services\SchemaCache::has('users', 'modality')) {
            DB::table('users')->where('id', $userId)->update([
                'modality' => $data['modality'] ?? 'onsite',
                'scope'    => $data['scope'] ?? 'local',
            ]);
            if (\App\Services\HteModality::ready()) DB::table('users')->where('id', $userId)->update(['modality_custom' => !$followHte]);
        }
        // a supervisor: the kind of HTE (for the HTE list and the Quarterly Report)
        if ($data['role'] === 'supervisor' && !empty($data['hte_category']) && \App\Services\SchemaCache::has('users', 'hte_category')) {
            DB::table('users')->where('id', $userId)->update(['hte_category' => trim($data['hte_category'])]);
        }
        // a supervisor: the modality of their HTE, given to the interns already placed there
        if ($data['role'] === 'supervisor' && !empty($data['hte_modality']) && \App\Services\HteModality::ready()) {
            DB::table('users')->where('id', $userId)->update(['hte_modality' => $data['hte_modality']]);
            if (!empty($data['placement'])) \App\Services\HteModality::setForHte($data['placement'], $data['hte_modality'], true);
        }

        // staff details (when the columns exist: php artisan migrate)
        if (!$isIntern && \App\Services\SchemaCache::has('users', 'position')) {
            $extra = [
                'position'    => $data['position'] ?? null,
                'hte_address' => ($data['role'] === 'supervisor') ? ($data['hte_address'] ?? null) : null,
                'moa_valid_until' => ($data['role'] === 'supervisor' && !empty($data['moa_valid_until'])) ? Carbon::parse($data['moa_valid_until'])->toDateString() : null,
            ];
            if ($request->hasFile('signature')) {
                $extra['signature'] = \App\Services\PrivateFiles::store($request->file('signature'), 'signatures');
            }
            DB::table('users')->where('id', $userId)->update($extra);
        }

        // system output presentation (decided by the HTE) and hours from an earlier term
        if (\App\Services\SchemaCache::has('users', 'output_required')) {
            $more = [];
            if ($data['role'] === 'supervisor') {
                $more['output_required'] = array_key_exists('output_required', $data) && $data['output_required'] !== null ? (bool) $data['output_required'] : true;
            }
            if ($isIntern) {
                $more['prior_hours'] = round((float) ($data['prior_hours'] ?? 0), 2);
                // the form sends the rule of the chosen supervisor's HTE (it can be changed for this intern)
                $supFlag = !empty($data['supervisor_id']) ? DB::table('users')->where('id', $data['supervisor_id'])->value('output_required') : null;
                $more['output_required'] = array_key_exists('output_required', $data) && $data['output_required'] !== null
                    ? (bool) $data['output_required'] : ($supFlag === null ? true : (bool) $supFlag);
            }
            if ($more) DB::table('users')->where('id', $userId)->update($more);
        }

        // NFC cards are not linked here: every intern follows the same steps
        // (request → coordinator approves → intern signs → admin prints → admin links in Card Requests)
        $message = 'User added successfully.';

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
            'name_extension' => 'nullable|string|max:20',   // Jr., Sr., II, III ...
            'email'          => 'sometimes|required|email|unique:users,email,' . $id,
            'student_id'     => 'nullable|string|max:50|unique:users,student_id,' . $id,
            'contact_number' => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'program'        => 'nullable|in:BSIT - 4,BSCS - 4,BSIS - 4',
            'semester'       => 'nullable|in:1st Semester,2nd Semester',
            'placement'      => 'nullable|string|max:150',
            'work_mode'      => 'nullable|in:onsite,offsite',
            'tracking_type'  => 'nullable|in:hours,output',
            'modality'       => 'nullable|in:onsite,hybrid,virtual,project,hte',   // hte = follow the HTE's modality
            'hte_modality'   => 'nullable|in:onsite,hybrid,virtual,project',       // supervisor: the modality of their HTE
            'hte_category'   => 'nullable|string|max:60',                          // supervisor: the kind of HTE (IT Company, Hospital, ...)
            'scope'          => 'nullable|in:local,out_of_region,inbound,outbound',
            'dtr_mode'       => 'nullable|in:two,four',
            'supervisor_id'  => 'nullable|integer|exists:users,id',
            'uid'            => 'nullable|string|max:64',
            'date_of_birth'  => 'nullable|date|before:today',
            'password'       => 'nullable|string|min:8|max:100',   // admin sets a new password
            'position'        => 'nullable|string|max:150',
            'hte_address'     => 'nullable|string|max:255',
            'moa_valid_until' => 'nullable|date',
            'output_required' => 'nullable|boolean',              // supervisor: the HTE requires a system output presentation
            'prior_hours'     => 'nullable|numeric|min:0|max:1000', // intern: hours rendered in an earlier term
            'emergency_contact_name'     => 'nullable|string|max:150',
            'emergency_contact_relation' => 'nullable|string|max:60',
            'emergency_contact_number'   => 'nullable|string|max:20',
            'emergency_contact_address'  => 'nullable|string|max:255',
        ]);

        // Who is asking (set by the sign-in check on the route)
        $auth    = $request->attributes->get('auth_user');
        $isAdmin = $auth && $auth->role === 'admin';

        // the admin edits any account; the OJT coordinator edits interns and HTE supervisors only (and can't set passwords)
        $isCoord = $auth && $auth->role === 'ojt_coordinator';
        if (!$isAdmin && !($isCoord && in_array($user->role, ['intern', 'supervisor'], true))) {
            return response()->json(['message' => 'Only the admin or OJT coordinator can edit this account.'], 403);
        }
        if (!$isAdmin) {
            unset($data['password']);
        }

        $isIntern = $user->role === 'intern';
        $uid      = strtoupper(trim($data['uid'] ?? ''));

        // a new card is linked only through Card Requests (after approval and printing);
        // here the admin can keep the current card or unlink it (e.g. a lost card)
        if ($isIntern && $uid !== '') {
            $current = DB::table('nfc_cards')->where('user_id', $id)->value('uid');
            if (strtoupper((string) $current) !== $uid) {
                return response()->json([
                    'message' => 'New cards are linked in Accounts → Card Requests, after the request is approved and the card is printed. Here you can only unlink a card.',
                ], 422);
            }
        }

        // the card can't already belong to someone else
        if ($isIntern && $uid !== '') {
            \App\Services\CardSecurity::dropOrphans();   // a card of a deleted account is free again
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

        // the extension (Jr., III) changes only when the form sent it; other forms (My Profile) keep it
        $ext = $request->has('name_extension')
            ? (trim((string) ($data['name_extension'] ?? '')) ?: null)
            : ($user->name_extension ?? null);
        $fullName = trim(implode(' ', array_filter([
            $data['first_name'], $data['middle_name'] ?? null, $data['last_name'], $ext,
        ])));

        $update = [
            'name'           => $fullName,
            'first_name'     => $data['first_name'],
            'middle_name'    => $data['middle_name'] ?? null,
            'last_name'      => $data['last_name'],
            'name_extension' => $ext,
            'email'          => $data['email'] ?? $user->email,
            'contact_number' => $data['contact_number'] ?? null,
            'address'        => $data['address'] ?? null,
            'updated_at'     => Carbon::now('Asia/Manila'),
        ];

        // emergency contact: saved when the form sent it (a cleared field is saved as empty)
        foreach (['emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_number', 'emergency_contact_address'] as $k) {
            if ($request->has($k)) {
                $update[$k] = ($data[$k] ?? null) !== null && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
            }
        }

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

        // a supervisor's HTE (company/office) is kept in the same "placement" field as an intern's placement
        if ($user->role === 'supervisor' && array_key_exists('placement', $data)) {
            $update['placement'] = $data['placement'] ?? null;
        }

        // staff details: only when the form sent them (and the columns exist)
        if (!$isIntern && \App\Services\SchemaCache::has('users', 'position')) {
            if ($request->has('position')) $update['position'] = trim((string) ($data['position'] ?? '')) ?: null;
            if ($user->role === 'supervisor') {
                if ($request->has('hte_address')) $update['hte_address'] = trim((string) ($data['hte_address'] ?? '')) ?: null;
                if ($request->has('moa_valid_until')) $update['moa_valid_until'] = !empty($data['moa_valid_until']) ? Carbon::parse($data['moa_valid_until'])->toDateString() : null;
            }
        }
        $hasOutCol = \App\Services\SchemaCache::has('users', 'output_required');
        if ($hasOutCol && $user->role === 'supervisor' && $request->has('output_required') && $data['output_required'] !== null) {
            $update['output_required'] = (bool) $data['output_required'];
        }

        if ($isIntern) {
            $update['program']       = $data['program'] ?? null;
            $update['semester']      = $data['semester'] ?? null;
            $update['placement']     = $data['placement'] ?? null;
            $update['college']       = 'CCIS';
            $update['work_mode']     = $data['work_mode'] ?? $user->work_mode;
            // the modality decides the work mode, like setPlacement(): virtual = offsite, on-site = onsite
            if (($data['modality'] ?? null) === 'virtual') $update['work_mode'] = 'offsite';
            if (($data['modality'] ?? null) === 'onsite')  $update['work_mode'] = 'onsite';
            $update['tracking_type'] = 'hours';   // no output-based tracking
            if (\App\Services\SchemaCache::has('users', 'modality')) {
                $update['modality'] = $data['modality'] ?? ($user->modality ?? 'onsite');
                $update['scope']    = $data['scope'] ?? ($user->scope ?? 'local');
                // "Same as the HTE", or a modality of its own (an exception to the HTE)
                if (\App\Services\HteModality::ready() && $request->has('modality')) {
                    $after = (object) ['supervisor_id' => $request->has('supervisor_id') ? ($data['supervisor_id'] ?? null) : $user->supervisor_id, 'placement' => $data['placement'] ?? null];
                    $hteMod = \App\Services\HteModality::forIntern($after);
                    if (($data['modality'] ?? null) === 'hte' || empty($data['modality'])) {
                        $update['modality'] = $hteMod ?: ($user->modality ?? 'onsite');
                        $update['modality_custom'] = false;
                    } else {
                        $update['modality_custom'] = $hteMod ? $data['modality'] !== $hteMod : false;
                    }
                    $update['work_mode'] = \App\Services\HteModality::workMode($update['modality'], $update['work_mode'] ?? $user->work_mode);
                } elseif (($update['modality'] ?? null) === 'hte') {
                    $update['modality'] = $user->modality ?? 'onsite';
                }
            }
            $update['dtr_mode']      = $data['dtr_mode'] ?? ($user->dtr_mode ?? 'two');

            // the intern's supervisor: only when the form sent it (admin or coordinator)
            if ($request->has('supervisor_id') && (!$auth || in_array($auth->role, ['admin', 'ojt_coordinator'], true))) {
                $sup = $data['supervisor_id'] ?? null;
                if ($sup && DB::table('users')->where('id', $sup)->value('role') !== 'supervisor') {
                    return response()->json(['message' => 'The chosen supervisor is not a supervisor account.'], 422);
                }
                $update['supervisor_id'] = $sup ?: null;
                // a new supervisor: the presentation rule of that HTE comes with it
                if ($hasOutCol && (int) ($sup ?: 0) !== (int) ($user->supervisor_id ?? 0) && $sup) {
                    $update['output_required'] = (bool) (DB::table('users')->where('id', $sup)->value('output_required') ?? true);
                }
            }
            if ($hasOutCol) {
                if ($request->has('prior_hours')) $update['prior_hours'] = round((float) ($data['prior_hours'] ?? 0), 2);
                // the coordinator / admin can still change it for one intern
                if ($request->has('output_required') && $data['output_required'] !== null && !isset($update['output_required'])) {
                    $update['output_required'] = (bool) $data['output_required'];
                }
            }
        }

        // a supervisor: the modality of their HTE (given to the HTE's interns after the save)
        $newHteMod = null;
        if ($user->role === 'supervisor' && !empty($data['hte_modality']) && \App\Services\HteModality::ready()) {
            $update['hte_modality'] = $newHteMod = $data['hte_modality'];
        }
        // a supervisor: the kind of HTE (empty clears it)
        if ($user->role === 'supervisor' && $request->has('hte_category') && \App\Services\SchemaCache::has('users', 'hte_category')) {
            $update['hte_category'] = trim((string) ($data['hte_category'] ?? '')) ?: null;
        }

        try {
            DB::transaction(function () use ($id, $update, $isIntern, $uid, $user) {
                DB::table('users')->where('id', $id)->update($update);
                // the HTE changed its rule: every intern under this supervisor follows it
                if ($user->role === 'supervisor' && array_key_exists('output_required', $update)) {
                    DB::table('users')->where('role', 'intern')->where('supervisor_id', $id)->update(['output_required' => $update['output_required']]);
                }

                // a new password signs the user out everywhere
                if (isset($update['password']) && \App\Services\SchemaCache::has('api_tokens')) {
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
            Log::error('updateUser failed: ' . $e->getMessage());
            return response()->json(['message' => 'The changes could not be saved. Please try again.'], 500);
        }

        // the HTE's modality reaches its interns (those with their own modality are kept)
        $hteDone = null;
        if ($newHteMod && !empty($update['placement'] ?? $user->placement)) {
            $hteDone = \App\Services\HteModality::setForHte($update['placement'] ?? $user->placement, $newHteMod, true);
        }
        // an intern moved to another HTE / supervisor follows the new HTE (unless they have their own modality)
        if ($isIntern && (array_key_exists('supervisor_id', $update) || array_key_exists('placement', $update))
            && !array_key_exists('modality_custom', $update)) {
            \App\Services\HteModality::applyToIntern((int) $id);
        }

        return response()->json([
            'message' => $hteDone && $hteDone['changed'] ? "User updated. {$hteDone['changed']} intern" . ($hteDone['changed'] === 1 ? '' : 's') . " now follow the HTE's modality." : 'User updated.',
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

        // an intern who already has attendance keeps their records: deactivate the account instead
        if ($user->role === 'intern' && DB::table('attendance_records')->where('user_id', $id)->exists()) {
            return response()->json([
                'message' => 'This intern already has attendance records, so the account can\'t be deleted. Deactivate it instead (the records stay).',
            ], 409);
        }

        try {
            DB::transaction(function () use ($id) {
                // Delete everything that belongs to this user first (skips tables that don't exist yet)
                foreach (['nfc_cards', 'tokens', 'attendance_records', 'online_submissions', 'nfc_requests',
                          'notifications', 'account_recovery', 'api_tokens', 'time_corrections', 'dtr_certifications'] as $t) {
                    if (\App\Services\SchemaCache::has($t) && \App\Services\SchemaCache::has($t, 'user_id')) {
                        DB::table($t)->where('user_id', $id)->delete();
                    }
                }

                // MOVs: remove the uploaded files too
                if (\App\Services\SchemaCache::has('movs')) {
                    $files = DB::table('movs')->where('user_id', $id)->pluck('file_path');
                    foreach ($files as $f) {
                        \App\Services\PrivateFiles::delete($f);
                    }
                    DB::table('movs')->where('user_id', $id)->delete();

                    // if this user reviewed other people's MOVs, keep those but clear the reviewer
                    if (\App\Services\SchemaCache::has('movs', 'reviewed_by')) {
                        DB::table('movs')->where('reviewed_by', $id)->update(['reviewed_by' => null]);
                    }
                }

                // a deleted supervisor's interns are left without a supervisor (the admin assigns a new one)
                if (\App\Services\SchemaCache::has('users', 'supervisor_id')) {
                    DB::table('users')->where('supervisor_id', $id)->update(['supervisor_id' => null]);
                }

                // Announcements they posted
                if (\App\Services\SchemaCache::has('announcements')) {
                    foreach (['user_id', 'posted_by', 'created_by'] as $col) {
                        if (\App\Services\SchemaCache::has('announcements', $col)) {
                            DB::table('announcements')->where($col, $id)->delete();
                        }
                    }
                }

                DB::table('users')->where('id', $id)->delete();
            });
        } catch (\Throwable $e) {
            Log::error('deleteUser failed: ' . $e->getMessage());
            return response()->json(['message' => 'The user could not be deleted. Please try again.'], 500);
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
            'tracking_type' => 'nullable|in:hours,output',   // kept for old clients; always saved as hours
        ]);

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $auth = $request->attributes->get('auth_user');
        if (($auth->role ?? '') === 'supervisor' && !Supervision::supervises($auth, $id)) {
            return response()->json(['message' => 'You can only change the settings of your own interns.'], 403);
        }

        DB::table('users')->where('id', $id)->update([
            'work_mode'     => $request->work_mode,
            'tracking_type' => 'hours',
        ]);

        return response()->json(['message' => 'Intern settings updated.']);
    }

    // ── Internship modality and scope (the OJT coordinator sets these) ─────────────────
    // PATCH /api/coordinator/interns/{id}/placement   { modality?: onsite|hybrid|virtual|project, scope?: local|out_of_region|inbound|outbound }
    // scope: local = CSU student at an HTE in the Caraga Region · out_of_region = at an HTE elsewhere in the Philippines ·
    //        inbound = a foreign student interning at CSU ·
    //        outbound = a CSU student interning with a foreign HTE (abroad, or virtually for a foreign company)
    // A virtual intern has no kiosk, so their work mode becomes offsite (they log days through Submit Online);
    // an on-site intern goes back to onsite. Hybrid keeps whatever the day-to-day work mode is.
    public function setPlacement(Request $request, $id)
    {
        if (!\App\Services\SchemaCache::has('users', 'modality')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $data = $request->validate([
            'modality' => 'nullable|in:onsite,hybrid,virtual,project,hte',
            'scope'    => 'nullable|in:local,out_of_region,inbound,outbound',
        ]);
        $intern = DB::table('users')->where('id', $id)->where('role', 'intern')->first();
        if (!$intern) return response()->json(['message' => 'Intern not found.'], 404);

        $update = [];
        if (!empty($data['modality'])) {
            // "hte" = follow the HTE again; any other value different from the HTE's is this intern's own (an exception)
            $hteMod = \App\Services\HteModality::forIntern($intern);
            if ($data['modality'] === 'hte') {
                if (!$hteMod) return response()->json(['message' => "This intern's HTE has no modality set yet. Set it in Partner HTEs → HTEs & MOAs."], 422);
                $data['modality'] = $hteMod;
            }
            $update['modality'] = $data['modality'];
            if ($data['modality'] === 'virtual') $update['work_mode'] = 'offsite';
            if ($data['modality'] === 'onsite')  $update['work_mode'] = 'onsite';
            if (\App\Services\HteModality::ready()) $update['modality_custom'] = $hteMod ? $data['modality'] !== $hteMod : false;
        }
        if (!empty($data['scope'])) $update['scope'] = $data['scope'];
        if (!$update) return response()->json(['message' => 'Nothing to change.'], 422);
        DB::table('users')->where('id', $id)->update($update);

        $labels = ['onsite' => 'On-site', 'hybrid' => 'Hybrid', 'virtual' => 'Virtual', 'project' => 'Project-based',
                   'local' => 'Local', 'out_of_region' => 'Out-of-region', 'inbound' => 'Inbound (international)', 'outbound' => 'Outbound (international)'];
        $auth = $request->attributes->get('auth_user');
        try { Activity::record($auth, 'Changed internship modality / scope', $intern->name . ' → ' . implode(', ', array_map(fn ($v) => $labels[$v] ?? $v, array_intersect_key($update, ['modality' => 1, 'scope' => 1]))), $request); } catch (\Throwable $e) { /* best-effort */ }
        if (isset($update['modality']) && $update['modality'] !== ($intern->modality ?? 'onsite')) {
            $how = ['onsite' => 'on-site: time in and out at the kiosk.', 'hybrid' => 'hybrid: use the kiosk on office days and Submit Online on remote days.',
                    'virtual' => 'virtual: log your days through Submit Online.',
                    'project' => 'project-based: log the days you worked on your project through Submit Online, or tap the kiosk when you are on site.'][$update['modality']];
            try { Notifier::toUser($intern->id, 'Internship Modality Updated', "Your internship is now {$how}", 'settings'); } catch (\Throwable $e) { /* best-effort */ }
        }
        return response()->json(['message' => 'Saved.', 'work_mode' => $update['work_mode'] ?? $intern->work_mode,
            'modality' => $update['modality'] ?? $intern->modality, 'modality_custom' => $update['modality_custom'] ?? ($intern->modality_custom ?? false)]);
    }

    // ── The kind of HTE ─────────────────────────────────────────────────────────────
    // PATCH /api/coordinator/htes/category   { hte: "CCIS IT Faculty", category: "IT Company" | "" }
    // Saved on the HTE's supervisor account(s); the HTE list and the Quarterly Report use it.
    public function setHteCategory(Request $request)
    {
        if (!\App\Services\SchemaCache::has('users', 'hte_category')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $data = $request->validate(['hte' => 'required|string|max:150', 'category' => 'nullable|string|max:60']);
        $sups = \App\Services\HteModality::supervisorsOf($data['hte']);
        if ($sups->isEmpty()) {
            return response()->json(['message' => 'Add an HTE supervisor account for this HTE first (Add Accounts). The category is kept on it.'], 422);
        }
        $cat = trim((string) ($data['category'] ?? '')) ?: null;
        DB::table('users')->whereIn('id', $sups->pluck('id')->all())->update(['hte_category' => $cat]);
        return response()->json(['message' => $cat ? "{$data['hte']} is now listed as {$cat}." : 'Category cleared.']);
    }

    // ── Does the HTE require a System Output Presentation? ─────────────────────────────
    // PATCH /api/coordinator/htes/output   { hte: "CCIS IT Faculty", required: true|false }
    // Saved on the HTE's supervisor account(s) and on every intern at that HTE: interns of an HTE that doesn't
    // require it no longer see "System Output Presentation" in their post-internship requirements.
    public function setHteOutput(Request $request)
    {
        if (!\App\Services\SchemaCache::has('users', 'output_required')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $data = $request->validate(['hte' => 'required|string|max:150', 'required' => 'required|boolean']);
        $req  = (bool) $data['required'];
        $sups = \App\Services\HteModality::supervisorsOf($data['hte']);
        $supIds = $sups->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($supIds) DB::table('users')->whereIn('id', $supIds)->update(['output_required' => $req]);

        $k = \App\Services\HteModality::key($data['hte']);
        $ids = DB::table('users')->where('role', 'intern')->get(['id', 'placement', 'supervisor_id'])
            ->filter(fn ($u) => in_array((int) $u->supervisor_id, $supIds, true) || \App\Services\HteModality::key($u->placement) === $k)
            ->pluck('id')->all();
        if ($ids) DB::table('users')->whereIn('id', $ids)->update(['output_required' => $req]);
        if (!$supIds && !$ids) return response()->json(['message' => 'No HTE supervisor account or intern was found for this HTE.'], 404);

        return response()->json(['message' => "{$data['hte']}: System Output Presentation is now " . ($req ? 'required' : 'not required')
            . '. ' . count($ids) . ' intern' . (count($ids) === 1 ? '' : 's') . ' updated.']);
    }

    // ── One modality per HTE ────────────────────────────────────────────────────────
    // PATCH /api/coordinator/htes/modality   { hte: "CCIS IT Faculty", modality: onsite|hybrid|virtual|project, skip_custom?: bool }
    // Saved on the HTE's supervisor account(s); every intern at that HTE gets it
    // (skip_custom = keep the interns the coordinator gave their own modality).
    public function setHteModality(Request $request)
    {
        if (!\App\Services\HteModality::ready()) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $data = $request->validate([
            'hte'         => 'required|string|max:150',
            'modality'    => 'required|in:onsite,hybrid,virtual,project',
            'skip_custom' => 'nullable|boolean',
        ]);
        $r = \App\Services\HteModality::setForHte($data['hte'], $data['modality'], (bool) ($data['skip_custom'] ?? true));
        if (!$r['supervisors'] && !$r['interns']) {
            return response()->json(['message' => 'No HTE supervisor account or intern was found for this HTE.'], 404);
        }
        $labels = ['onsite' => 'On-site', 'hybrid' => 'Hybrid', 'virtual' => 'Virtual', 'project' => 'Project-based'];
        $auth = $request->attributes->get('auth_user');
        try { Activity::record($auth, 'Set an HTE modality', "{$data['hte']} → {$labels[$data['modality']]}", $request); } catch (\Throwable $e) { /* best-effort */ }
        $msg = "{$data['hte']} is now {$labels[$data['modality']]}."
            . ($r['changed'] ? " {$r['changed']} intern" . ($r['changed'] === 1 ? ' was' : 's were') . ' updated and notified.' : '')
            . ($r['skipped'] ? " {$r['skipped']} with their own modality kept it." : '')
            . (!$r['supervisors'] ? ' Add an HTE supervisor account for this HTE so new interns get it automatically.' : '');
        return response()->json(['message' => $msg] + $r);
    }

    // ── Assign (or remove) an intern's HTE supervisor ─────────────────────────────────
    // PATCH /api/coordinator/interns/{id}/supervisor   { supervisor_id: 12 | null }
    // Done by the OJT coordinator (or the admin). The supervisor then reviews the intern's
    // time corrections, work arrangement, online days and overtime, and certifies the DTR.
    public function assignSupervisor(Request $request, $id)
    {
        $data = $request->validate([
            'supervisor_id' => 'nullable|integer',
        ]);

        $intern = DB::table('users')->where('id', $id)->where('role', 'intern')->first();
        if (!$intern) {
            return response()->json(['message' => 'Intern not found.'], 404);
        }

        $supId = $data['supervisor_id'] ?? null;
        $sup   = null;
        if ($supId) {
            $sup = DB::table('users')->where('id', $supId)->where('role', 'supervisor')->first();
            if (!$sup) {
                return response()->json(['message' => 'That supervisor account was not found.'], 422);
            }
        }
        if ((int) ($intern->supervisor_id ?? 0) === (int) ($supId ?? 0)) {
            return response()->json(['message' => 'Nothing changed.']);
        }

        DB::table('users')->where('id', $id)->update([
            'supervisor_id' => $supId,
            'updated_at'    => Carbon::now('Asia/Manila'),
        ]);
        if ($sup && \App\Services\SchemaCache::has('users', 'output_required')) {
            // the presentation rule of the new supervisor's HTE comes with it
            DB::table('users')->where('id', $id)->update(['output_required' => (bool) ($sup->output_required ?? true)]);
        }
        // and so does the HTE's modality (unless the intern has their own)
        if ($sup) \App\Services\HteModality::applyToIntern((int) $id);

        if ($sup) {
            Notifier::toUser($intern->id, 'HTE Supervisor Assigned',
                "{$sup->name} is now your HTE supervisor.", 'supervisor_assigned');
            Notifier::toUser($sup->id, 'New Intern Assigned',
                "{$intern->name} was assigned to you. You can now see their attendance and review their requests.", 'supervisor_assigned');
        }

        return response()->json([
            'message' => $sup ? "{$sup->name} is now {$intern->name}'s supervisor." : "{$intern->name} has no supervisor now.",
            'supervisor_id'   => $supId,
            'supervisor_name' => $sup->name ?? null,
        ]);
    }

    // ── Update User Photo ─────────────────────────────────
    public function updatePhoto(Request $request, $id)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = DB::table('users')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $auth = $request->attributes->get('auth_user');
        if ($auth && $auth->role === 'ojt_coordinator' && !in_array($user->role, ['intern', 'supervisor'], true)) {
            return response()->json(['message' => 'Only the admin can change this photo.'], 403);
        }

        if ($user->photo) {
            Storage::disk(config('filesystems.uploads', 'public'))->delete($user->photo);  
        }

        $photoPath = $request->file('photo')->store('profiles', config('filesystems.uploads', 'public'));

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

    // ── Staff signature (used on the DTR and reports) ──────
    public function updateSignature(Request $request, $id)
    {
        $request->validate(['signature' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        if (!\App\Services\SchemaCache::has('users', 'signature')) {
            return response()->json(['message' => 'Run php artisan migrate first.'], 422);
        }
        $auth = $request->attributes->get('auth_user');
        if ($auth && $auth->role === 'ojt_coordinator' && !in_array($user->role, ['intern', 'supervisor'], true)) {
            return response()->json(['message' => 'Only the admin can change this signature.'], 403);
        }

        if (!empty($user->signature)) {
            \App\Services\PrivateFiles::delete($user->signature);
        }
        $path = \App\Services\PrivateFiles::store($request->file('signature'), 'signatures');   // private: short-lived links only
        DB::table('users')->where('id', $id)->update(['signature' => $path]);

        return response()->json(['message' => 'Signature updated.', 'signature' => $path]);
    }
}