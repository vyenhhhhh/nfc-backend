<?php

namespace App\Console\Commands;

use App\Services\Grading;
use App\Services\OjtHours;
use App\Services\PrivateFiles;
use App\Services\Requirements;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | USER EVALUATION ACCOUNTS: TEST DATABASE ONLY (matches the task lists in Appendix A of the manuscript)
 |
 |   php artisan eval:setup                  creates the evaluation accounts and their sample data
 |   php artisan eval:setup --remove-future  also deletes taps dated after today (left over from clock-change tests)
 |   php artisan eval:setup --remove-demo    also deletes the old test accounts (…@demo.test) and what they made
 |   php artisan eval:card <UID> <email>     moves a test NFC card to an evaluation account (between respondents)
 |   php artisan eval:cleanup                deletes every evaluation account and its data (after the evaluation)
 |
 | Run it a few days before the sessions at most: the sample days are counted back from the day it runs.
 |
 | Accounts (each respondent signs in with their own):
 |   30 interns (past and upcoming: the form asks which): internship IN PROGRESS, so every intern task of
 |      Appendix A works: pre-internship requirements approved (the kiosk accepts them), about two weeks of
 |      taps, a weekday with no tap (for online attendance), a forgotten time out (for a time correction),
 |      a weekly report requested, an announcement. They change the temporary password on first sign-in.
 |    2 HTE supervisors, each with their own HTE, half of the interns and 2 practice interns with work waiting:
 |      online attendance, overtime, an excused day and a time correction (with proofs), a DTR period to
 |      certify, a project-based intern whose project is not marked yet, and an appraisal to fill in
 |    1 OJT coordinator, with practice work waiting: online attendance, overtime and an excused day the
 |      supervisor confirmed, two requirement uploads to review, a project marked complete to confirm, an intern
 |      ready to grade (appraisal done, hours complete), and ID card requests to print and link (for the admin)
 |    1 administrator
 | Every account's password: Password123! (or --password=...). The list is saved in storage/app/evaluation-accounts.csv.
 */
class EvaluationSetup extends Command
{
    protected $signature = 'eval:setup {--remove-future : Also delete taps dated after today (from clock-change tests)} {--remove-demo : Also delete the old test accounts (@demo.test) and what they made} {--interns=30 : How many intern accounts} {--supervisors=2 : How many HTE supervisors (1 or 2)} {--password=Password123! : The password of every evaluation account} {--force : Do not ask to confirm}';
    protected $description = 'Create the user evaluation accounts and sample data (TEST database only)';

    public const DOMAIN = 'eval.test';

    // the internship of the finished interns: a past term (Monday Feb 2, 2026 onward)
    private const START = '2026-02-02';
    // days the finished interns did not work (holidays in that term)
    private const OFF = ['2026-02-25', '2026-04-02', '2026-04-03', '2026-04-09', '2026-05-01'];

    private array $cols = [];
    private array $accounts = [];
    private ?string $sharedFile = null;
    private ?string $hashed = null;

    public function handle(): int
    {
        $db = config('database.default');
        $conn = config("database.connections.$db");
        $this->warn('This adds the USER EVALUATION accounts to this database:');
        $this->line('  connection: ' . $db . ' · host: ' . ($conn['host'] ?? '-') . ' · user: ' . ($conn['username'] ?? '-'));
        $this->warn('Use the TEST database only, never the real one.');
        if (!$this->option('force') && !$this->confirm('Is this the TEST database?', false)) {
            $this->info('Nothing was changed.');
            return self::SUCCESS;
        }

        if (DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->exists()) {
            $this->error('Evaluation accounts already exist. Run "php artisan eval:cleanup" first to start over.');
            return self::FAILURE;
        }

        if ($this->option('remove-demo')) {
            $demo = DB::table('users')->where('email', 'like', '%@demo.test')->pluck('id')->all();
            EvaluationCleanup::purge($demo);
            $this->info('Removed ' . count($demo) . ' old test account(s) (@demo.test) and their data.');
        }
        if ($this->option('remove-future')) $this->removeFuture();

        $this->cols = Schema::getColumnListing('users');
        $this->sharedFile = $this->samplePdf('movs/eval-sample.pdf');

        try {
            DB::transaction(function () { $this->build(); });
        } catch (\Throwable $e) {
            $this->error('Setup failed, nothing was saved: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->saveList();
        return self::SUCCESS;
    }

    // ───────────────────────── the accounts ─────────────────────────
    private function build(): void
    {
        $admin = $this->user('admin', 'CCIS', 'Administrator', 'admin', ['position' => 'System Administrator']);
        $coord = $this->user('ojt_coordinator', 'Jhoanna Victoria', 'Pitao', 'coordinator', ['position' => 'OJT Coordinator']);

        $htes = [
            ['Evaluation HTE 1', 'Government Agency', 'Butuan City', '2027-06-30'],
            ['Evaluation HTE 2', 'Educational Institution', 'Butuan City', '2027-03-31'],
        ];
        $nSup = max(1, min(2, (int) $this->option('supervisors')));
        $sups = [];
        foreach (array_slice($htes, 0, $nSup) as $i => [$hte, $cat, $addr, $moa]) {
            $n = $i + 1;
            $sups[] = [$this->user('supervisor', 'HTE Supervisor', (string) $n, "supervisor$n", [
                'position' => 'Training Supervisor', 'placement' => $hte, 'hte_address' => $addr, 'hte_category' => $cat,
                'moa_valid_until' => $moa, 'hte_modality' => 'onsite', 'output_required' => true,
            ]), $hte];
        }

        // the intern respondents: "Intern 1" … "Intern 30" (past or upcoming is asked in the form, not here)
        $programs = ['BSIT - 4', 'BSIT - 4', 'BSCS - 4', 'BSIS - 4'];
        $count = max(0, min(60, (int) $this->option('interns')));
        for ($i = 0; $i < $count; $i++) {
            [$sup, $hte] = $sups[$i % $nSup];
            $prog = $programs[$i % 4];
            $id = $this->intern('Intern', (string) ($i + 1), $prog, $sup, $hte, sprintf('intern%02d', $i + 1), sprintf('26-9%04d', $i + 1),
                'Intern', ['must_change_password' => true]);   // Appendix A, task 1: change the temporary password
            $this->inProgress($id, $coord, 12, true);
        }

        // practice interns (nobody signs in as them): the work the supervisors and the coordinator act on
        foreach ($sups as $k => [$sup, $hte]) {
            $letterA = chr(65 + 2 * $k); $letterB = chr(66 + 2 * $k);   // A, B for supervisor 1 · C, D for supervisor 2
            $a = $this->intern('Practice', "Intern $letterA", 'BSIT - 4', $sup, $hte, 'practice' . strtolower($letterA), sprintf('26-7%04d', 2 * $k + 1), 'practice');
            $days = $this->inProgress($a, $coord, 25, false);
            $this->waitingForSupervisor($a, $days);
            // B / D: a project-based intern, project not marked complete yet, no appraisal yet
            $b = $this->intern('Practice', "Intern $letterB", 'BSCS - 4', $sup, $hte, 'practice' . strtolower($letterB), sprintf('26-7%04d', 2 * $k + 2), 'practice',
                ['modality' => 'project', 'modality_custom' => true]);
            $this->inProgress($b, $coord, 15, false);
        }
        [$sup1, $hte1] = $sups[0];
        // E: work the supervisor already confirmed, two uploads to review, an ID card to link (admin)
        $e = $this->intern('Practice', 'Intern E', 'BSIT - 4', $sup1, $hte1, 'practicee', '26-70005', 'practice');
        $days = $this->inProgress($e, $coord, 25, false, ['supervisor_feedback_form', 'performance_appraisal']);
        $this->waitingForCoordinator($e, $sup1, $days);
        $this->cardRequest($e, $coord, $admin, true);
        // F: project-based, the supervisor marked the project complete: the coordinator confirms it
        $f = $this->intern('Practice', 'Intern F', 'BSIS - 4', $sup1, $hte1, 'practicef', '26-70006', 'practice',
            ['modality' => 'project', 'modality_custom' => true]);
        $this->inProgress($f, $coord, 15, false);
        DB::table('users')->where('id', $f)->update($this->only('users', ['project_marked_at' => Carbon::now('Asia/Manila')->subDay(), 'project_marked_by' => $sup1]));
        $this->cardRequest($f, $coord, $admin, false);
        // G: hours complete and the supervisor's appraisal done: the coordinator rates and releases the grade
        $g = $this->intern('Practice', 'Intern G', 'BSCS - 4', $sup1, $hte1, 'practiceg', '26-70007', 'practice');
        $this->finishedData($g, $sup1, $coord, $admin, 'BSCS - 4');

        // a weekly report the coordinator asked for, and an announcement
        if (Schema::hasTable('report_schedules')) {
            DB::table('report_schedules')->insert($this->only('report_schedules', [
                'title' => 'Weekly Report – Week 1', 'due_date' => Carbon::today('Asia/Manila')->addDays(7)->toDateString(),
                'audience' => 'all', 'created_by' => $coord, 'created_at' => Carbon::now('Asia/Manila'), 'updated_at' => Carbon::now('Asia/Manila'),
            ]));
        }
        if (Schema::hasTable('announcements')) {
            DB::table('announcements')->insert($this->only('announcements', [
                'title' => 'Welcome to the CCIS OJT system',
                'body' => 'Please submit your weekly report on time and remember to tap your ID card when you arrive and when you leave.',
                'created_by' => $coord, 'created_at' => Carbon::now('Asia/Manila'), 'updated_at' => Carbon::now('Asia/Manila'),
            ]));
        }
    }

    /** a user row (only the columns this database has) + its line in the account list */
    private function user(string $role, string $first, string $last, string $login, array $extra = [], string $group = ''): int
    {
        $now = Carbon::now('Asia/Manila');
        $pw = $this->password();
        $row = array_merge([
            'role' => $role, 'name' => trim("$first $last"), 'first_name' => $first, 'last_name' => $last,
            'email' => "$login@" . self::DOMAIN, 'password' => $this->hashed ??= Hash::make($pw),
            'student_id' => $extra['student_id'] ?? strtoupper('EV-' . $login),
            'date_of_birth' => '2003-01-15', 'contact_number' => '09' . str_pad((string) random_int(100000000, 999999999), 9, '0'),
            'must_change_password' => false,
            'privacy_consent_at' => null,   // each respondent reads and agrees to the Data Privacy notice on first sign-in
            'created_at' => $now, 'updated_at' => $now,
        ], $extra);
        $id = DB::table('users')->insertGetId(array_intersect_key($row, array_flip($this->cols)));
        if ($group !== 'practice') {
            $this->accounts[] = [
                'group' => $group ?: ['admin' => 'Administrator', 'ojt_coordinator' => 'OJT coordinator', 'supervisor' => 'HTE supervisor'][$role] ?? $role,
                'name' => trim("$first $last"), 'email' => $row['email'], 'password' => $pw,
            ];
        }
        return $id;
    }

    private function intern(string $f, string $l, string $prog, int $sup, string $hte, string $login, string $sid, string $group, array $extra = []): int
    {
        return $this->user('intern', $f, $l, $login, array_merge([
            'student_id' => $sid, 'program' => $prog, 'college' => 'CCIS', 'semester' => '1st Semester',
            'placement' => $hte, 'supervisor_id' => $sup, 'work_mode' => 'onsite', 'modality' => 'onsite', 'scope' => 'local',
            'modality_custom' => false, 'tracking_type' => 'hours', 'dtr_mode' => 'two', 'output_required' => true, 'prior_hours' => 0,
        ], $extra), $group);
    }

    // ───────────────────────── an internship in progress ─────────────────────────
    /**
     * Pre-internship requirements approved (the kiosk accepts the intern) and the last $n workdays before today:
     * full days, except (for a respondent) one workday with no tap at all, for online attendance, and one
     * with a time in but no time out, for a time correction. $pendingDuring: during-internship uploads left
     * waiting for the coordinator. Returns the workdays used, newest first.
     */
    private function inProgress(int $id, int $coord, int $n, bool $respondent, array $pendingDuring = []): array
    {
        $today = Carbon::today('Asia/Manila');
        foreach (Requirements::active('pre') as $r) $this->mov($id, $r, 'approved', $coord, $today->copy()->subDays($n * 2 + 10));
        // during-internship uploads: none yet (a respondent uploads one), except the ones left for the coordinator
        foreach (Requirements::active('during') as $r) {
            if (in_array($r->key, $pendingDuring, true)) $this->mov($id, $r, 'pending', null, $today->copy()->subDays(2), true);
        }

        // the last $n workdays (weekdays that are not a holiday or a day off on the calendar), newest first
        $days = [];
        $d = $today->copy()->subDay();
        while (count($days) < $n) {
            if (!$d->isWeekend() && !$this->dayOff($d->toDateString(), $id)) $days[] = $d->copy();
            $d->subDay();
        }
        $noTap = $respondent ? [1] : [];       // the 2nd newest workday: no tap (online attendance)
        $noOut = $respondent ? [2] : [];       // the 3rd newest: time in only (time correction)
        $rows = [];
        foreach ($days as $k => $day) {
            if (in_array($k, $noTap, true)) continue;
            $in  = $day->copy()->setTime(7, 45)->addMinutes(random_int(0, 13));
            if ($k % 7 === 5) $in = $day->copy()->setTime(8, 0)->addMinutes(random_int(5, 18));   // late now and then
            $out = $day->copy()->setTime(17, 0)->addMinutes(random_int(0, 8));
            $open = in_array($k, $noOut, true);
            $rows[] = $this->only('attendance_records', [
                'user_id' => $id, 'uid' => 'EVAL' . $id, 'date' => $day->toDateString(), 'action' => $open ? 'TIME_IN' : 'TIME_OUT',
                'checked_in_at' => $in, 'checked_out_at' => $open ? null : $out, 'created_at' => $in, 'updated_at' => $open ? $in : $out,
                'ot_minutes' => 0, 'ot_status' => null, 'ot_approved_minutes' => 0,
            ]);
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('attendance_records')->insert($chunk);
        return $days;
    }

    /** a holiday or a day off on the calendar for this intern (skipped like the real kiosk does) */
    private function dayOff(string $date, int $id): bool
    {
        try { return (bool) \App\Services\CalendarDays::eventFor($date, $id); } catch (\Throwable $e) { return false; }
    }

    /** a practice intern of a supervisor: online attendance, overtime, an excused day and a time correction, all waiting */
    private function waitingForSupervisor(int $id, array $days): void
    {
        $now = Carbon::now('Asia/Manila');
        $recs = DB::table('attendance_records')->where('user_id', $id)->orderByDesc('date')->get();
        // overtime on the newest day, a forgotten time out on the 2nd newest
        if ($r = $recs[0] ?? null) {
            DB::table('attendance_records')->where('id', $r->id)->update(['checked_out_at' => Carbon::parse(substr((string) $r->date, 0, 10) . ' 18:00:00', 'Asia/Manila'), 'ot_minutes' => 60, 'ot_status' => 'pending']);
        }
        if ($r = $recs[1] ?? null) {
            $date = substr((string) $r->date, 0, 10);
            DB::table('attendance_records')->where('id', $r->id)->update(['action' => 'TIME_IN', 'checked_out_at' => null]);
            DB::table('time_corrections')->insert($this->only('time_corrections', [
                'user_id' => $id, 'attendance_id' => $r->id, 'date' => $date, 'kind' => 'out',
                'requested_out' => Carbon::parse("$date 17:00:00", 'Asia/Manila'), 'reason' => 'I forgot to tap my card when I left at 5:00 PM.',
                'status' => 'pending', 'proof_path' => $this->samplePdf("correction-proofs/eval-$id.pdf"), 'proof_name' => 'proof-time-out.pdf',
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }
        // the 3rd and 4th newest workdays become an online day and an excused day (their taps are removed)
        $this->onlineDay($id, $days[2] ?? null, 'pending');
        $this->excusedDay($id, $days[3] ?? null, 'pending');
    }

    /** a practice intern of the coordinator: the same kinds of requests, already confirmed by the supervisor */
    private function waitingForCoordinator(int $id, int $sup, array $days): void
    {
        $now = Carbon::now('Asia/Manila');
        if ($r = DB::table('attendance_records')->where('user_id', $id)->orderByDesc('date')->first()) {
            DB::table('attendance_records')->where('id', $r->id)->update($this->only('attendance_records', [
                'checked_out_at' => Carbon::parse(substr((string) $r->date, 0, 10) . ' 18:30:00', 'Asia/Manila'), 'ot_minutes' => 90, 'ot_status' => 'endorsed',
                'ot_endorsed_minutes' => 90, 'ot_endorsed_by' => $sup, 'ot_endorsed_at' => $now->copy()->subHours(3),
            ]));
        }
        $this->onlineDay($id, $days[2] ?? null, 'endorsed', $sup);
        $this->excusedDay($id, $days[3] ?? null, 'endorsed', $sup);
    }

    private function onlineDay(int $id, ?Carbon $day, string $status, ?int $sup = null): void
    {
        if (!$day || !Schema::hasTable('online_submissions')) return;
        $date = $day->toDateString(); $now = Carbon::now('Asia/Manila');
        DB::table('attendance_records')->where('user_id', $id)->where('date', $date)->delete();
        DB::table('online_submissions')->insert($this->only('online_submissions', [
            'user_id' => $id, 'date' => $date, 'status' => $status,
            'description' => "Reason: My supervisor asked me to work from home\n\nUpdated the office inventory sheet and prepared the weekly summary report.",
            'proof_path' => $this->samplePdf("online-proofs/eval-$id.pdf"), 'proof_name' => 'proof-online-day.pdf',
            'endorsed_by' => $sup, 'endorsed_at' => $sup ? $now->copy()->subHours(5) : null,
            'created_at' => $day->copy()->setTime(18, 0), 'updated_at' => $now,
        ]));
    }

    private function excusedDay(int $id, ?Carbon $day, string $status, ?int $sup = null): void
    {
        if (!$day || !Schema::hasTable('school_event_excuses')) return;
        $date = $day->toDateString(); $now = Carbon::now('Asia/Manila');
        DB::table('attendance_records')->where('user_id', $id)->where('date', $date)->delete();
        DB::table('school_event_excuses')->insert($this->only('school_event_excuses', [
            'user_id' => $id, 'date' => $date, 'event_name' => 'University Foundation Day program', 'mandatory' => true, 'portion' => 'whole',
            'reason' => 'Attendance of all CCIS students was required.', 'status' => $status,
            'proof_path' => $this->samplePdf("excuse-proofs/eval-$id.pdf"), 'proof_name' => 'proof-school-event.pdf',
            'endorsed_by' => $sup, 'endorsed_at' => $sup ? $now->copy()->subHours(4) : null,
            'created_at' => $day->copy()->subDays(2), 'updated_at' => $now,
        ]));
    }

    /** an ID card request approved by the coordinator; $printed: waiting in "To link" for the admin, else in "To print" */
    private function cardRequest(int $id, int $coord, int $admin, bool $printed): void
    {
        if (!Schema::hasTable('nfc_requests')) return;
        $now = Carbon::now('Asia/Manila');
        DB::table('nfc_requests')->insert($this->only('nfc_requests', [
            'user_id' => $id, 'type' => 'new', 'status' => 'approved', 'reviewed_by' => $coord, 'reviewed_at' => $now->copy()->subDays(2),
            'printed_at' => $printed ? $now->copy()->subDay() : null, 'printed_by' => $printed ? $admin : null,
            'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDay(),
        ]));
    }

    // ───────────────────────── a finished internship ─────────────────────────
    /**
     * The whole internship of one intern: approved pre and during requirements, a card, the attendance
     * up to the required hours, certified DTR periods, then either
     *   respondent: approved post requirements (System Output left to upload) and a released grade, or
     *   practice:   work waiting for the supervisor and the coordinator.
     */
    /**
     * An intern ready to grade: hours complete (DTRs certified), the supervisor's appraisal submitted, the
     * certificate of completion and narrative report uploaded and waiting, the coordinator's rating not given yet.
     */
    private function finishedData(int $id, int $sup, int $coord, int $admin, string $prog): void
    {
        $required = (float) (config('ojt.program_hours.' . explode(' ', $prog)[0]) ?? config('ojt.default_hours', 486));
        $start = Carbon::parse(self::START, 'Asia/Manila');

        // requirements approved before and during the internship
        foreach (['pre', 'during'] as $phase) {
            foreach (Requirements::active($phase) as $r) {
                if ($r->key === 'weekly_report') continue;   // reports are asked for by the coordinator one by one
                $this->mov($id, $r, 'approved', $coord, $start->copy()->subDays($phase === 'pre' ? 14 : -30));
            }
        }

        // the ID card: requested, printed, linked and claimed before the internship started
        $uid = strtoupper(bin2hex(random_bytes(4)));
        $card = $this->only('nfc_cards', ['user_id' => $id, 'uid' => $uid, 'created_at' => $start->copy()->subDays(5), 'updated_at' => $start->copy()->subDays(5)]);
        if (Schema::hasColumn('nfc_cards', 'card_uuid')) $card['card_uuid'] = (string) Str::uuid();
        DB::table('nfc_cards')->insert($card);
        if (Schema::hasTable('nfc_requests')) {
            DB::table('nfc_requests')->insert(array_intersect_key([
                'user_id' => $id, 'type' => 'new', 'status' => 'issued', 'uid' => $uid, 'reviewed_by' => $coord,
                'reviewed_at' => $start->copy()->subDays(10), 'printed_at' => $start->copy()->subDays(7), 'printed_by' => $admin,
                'created_at' => $start->copy()->subDays(12), 'updated_at' => $start->copy()->subDays(5),
            ], array_flip(Schema::getColumnListing('nfc_requests'))));
        }

        // attendance: weekdays from the start until the required hours are reached
        $day = $start->copy();
        $n = 0; $total = 0.0; $batch = [];
        // a practice intern gets about one extra day: the forgotten time out below takes a day's hours away
        while ($total < $required + 1) {
            if ($day->isWeekend() || in_array($day->toDateString(), self::OFF, true)) { $day->addDay(); continue; }
            $n++;
            $late = ($n % 9 === 0) ? random_int(5, 20) : 0;                   // late now and then
            $in  = $day->copy()->setTime(7, 45)->addMinutes(random_int(0, 13));
            if ($late) $in = $day->copy()->setTime(8, 0)->addMinutes($late);
            $out = $day->copy()->setTime(17, 0)->addMinutes(random_int(0, 8));
            $ot = 0; $otStatus = null; $otApproved = 0;
            if ($n % 13 === 0) {                                              // approved overtime now and then
                $ot = 60; $otStatus = 'approved'; $otApproved = 60; $out = $day->copy()->setTime(18, 0)->addMinutes(random_int(0, 5));
            }
            $rec = [
                'user_id' => $id, 'uid' => $uid, 'date' => $day->toDateString(), 'action' => 'TIME_OUT',
                'checked_in_at' => $in, 'checked_out_at' => $out, 'created_at' => $in, 'updated_at' => $out,
                'ot_minutes' => $ot, 'ot_status' => $otStatus, 'ot_approved_minutes' => $otApproved,
                'ot_reviewed_by' => $otStatus ? $coord : null, 'ot_reviewed_at' => $otStatus ? $out->copy()->addDay() : null,
            ];
            $batch[] = $this->only('attendance_records', $rec);
            $total += max(0, 8 - $late / 60) + $otApproved / 60;
            $day->addDay();
        }
        foreach (array_chunk($batch, 100) as $chunk) DB::table('attendance_records')->insert($chunk);   // a few big inserts: fast over the network

        $lastDay = $day->copy()->subDay();
        while ($lastDay->isWeekend() || in_array($lastDay->toDateString(), self::OFF, true)) $lastDay->subDay();

        // safety net: if the hours still fall short, add days until the required hours are reached
        while (OjtHours::rendered($id) + 0.01 < $required) {
            $d = $lastDay->copy()->addDay();
            while ($d->isWeekend() || in_array($d->toDateString(), self::OFF, true)
                   || DB::table('attendance_records')->where('user_id', $id)->where('date', $d->toDateString())->exists()) $d->addDay();
            $in = $d->copy()->setTime(7, 50); $out = $d->copy()->setTime(17, 2);
            DB::table('attendance_records')->insert($this->only('attendance_records', [
                'user_id' => $id, 'uid' => $uid, 'date' => $d->toDateString(), 'action' => 'TIME_OUT',
                'checked_in_at' => $in, 'checked_out_at' => $out, 'created_at' => $in, 'updated_at' => $out, 'ot_minutes' => 0,
            ]));
            $lastDay = $d;
        }

        // DTR periods (1–15, 16–end of month), all certified by the supervisor
        $dates = DB::table('attendance_records')->where('user_id', $id)->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10));
        $periods = [];
        foreach ($dates as $d) {
            $c = Carbon::parse($d);
            $from = $c->day <= 15 ? $c->copy()->startOfMonth() : $c->copy()->setDay(16);
            $to   = $c->day <= 15 ? $c->copy()->setDay(15) : $c->copy()->endOfMonth();
            $periods[$from->toDateString()] = $to->toDateString();
        }
        foreach ($periods as $from => $to) {
            $rows = DB::table('attendance_records')->where('user_id', $id)->whereBetween('date', [$from, $to])
                ->get(['date', 'checked_in_at', 'checked_out_at', 'ot_approved_minutes']);
            $h = 0;
            foreach ($rows->groupBy(fn ($r) => (string) $r->date) as $dayRows) $h += OjtHours::day($dayRows);
            $at = Carbon::parse($to, 'Asia/Manila')->addDays(2)->setTime(10, 0);
            DB::table('dtr_certifications')->insert($this->only('dtr_certifications', [
                'user_id' => $id, 'period_from' => $from, 'period_to' => $to, 'hours' => round($h, 2),
                'certified_by' => $sup, 'certified_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]));
        }

        $end = $lastDay->copy();
        $post = Requirements::active('post')->keyBy('key');
        // uploaded after the internship and waiting for the coordinator; the rest approved (System Output only if the HTE asks)
        foreach ($post as $k => $r) {
            if (in_array($k, ['certificate_of_completion', 'narrative_report'], true)) $this->mov($id, $r, 'pending', null, $end->copy()->addDays(3), true);
            elseif ($k !== 'system_output') $this->mov($id, $r, 'approved', $coord, $end->copy()->addDays(5));
        }

        // the supervisor's appraisal (60% of the grade), submitted; the coordinator's part (40%) is the respondent's task
        $supRatings = []; foreach (Grading::keys('supervisor') as $k) $supRatings[$k] = random_int(4, 5);
        if (!$supRatings || !Schema::hasTable('evaluations')) return;
        $evAt = $end->copy()->addDays(2)->setTime(15, 0);
        $supName = DB::table('users')->where('id', $sup)->value('name');
        DB::table('evaluations')->insert($this->only('evaluations', [
            'intern_id' => $id, 'evaluator_id' => $sup, 'evaluator_name' => $supName, 'evaluator_role' => 'supervisor', 'mode' => 'system',
            'ratings' => json_encode($supRatings), 'average' => round(array_sum($supRatings) / count($supRatings), 2),
            'strengths' => 'Reliable and quick to learn; worked well with the team.', 'improvements' => 'Ask questions earlier when a task is unclear.',
            'recommendation' => 'recommend', 'share_comments' => true, 'created_at' => $evAt, 'updated_at' => $evAt,
        ]));
    }

    /** one requirement upload; a waiting one gets its own copy of the sample file (removing it can't affect the others) */
    private function mov(int $userId, object $req, string $status, ?int $reviewer, Carbon $at, bool $ownFile = false): void
    {
        $path = $ownFile ? ($this->samplePdf('movs/eval-' . $userId . '-' . $req->key . '.pdf') ?? $this->sharedFile) : $this->sharedFile;
        DB::table('movs')->insert($this->only('movs', [
            'user_id' => $userId, 'title' => $req->label ?? $req->key, 'requirement' => $req->key, 'phase' => $req->phase,
            'file_path' => $path ?? 'movs/eval-sample.pdf', 'original_name' => Str::slug($req->label ?? $req->key) . '.pdf',
            'status' => $status, 'reviewed_by' => $reviewer, 'reviewed_at' => $status === 'pending' ? null : $at->copy()->addDay(),
            'created_at' => $at, 'updated_at' => $at,
        ]));
    }

    // ───────────────────────── helpers ─────────────────────────
    private function only(string $table, array $row): array
    {
        static $cache = [];
        $cache[$table] ??= array_flip(Schema::getColumnListing($table));
        return array_intersect_key($row, $cache[$table]);
    }

    /** the same password for every evaluation account (--password, default Password123!) */
    private function password(): string
    {
        return (string) ($this->option('password') ?: 'Password123!');
    }

    /** a small one-page PDF on the private disk (what the sample uploads open) */
    private function samplePdf(string $path): ?string
    {
        $text = 'Sample file for the user evaluation';
        $stream = "BT /F1 18 Tf 72 720 Td ($text) Tj ET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [];
        foreach ($objs as $i => $o) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        try {
            PrivateFiles::disk()->put($path, $pdf);
            return $path;
        } catch (\Throwable $e) {
            $this->warn("Could not save the sample file ($path): " . $e->getMessage());
            return null;
        }
    }

    /** taps dated after today (left from tests with the computer's clock moved ahead) */
    private function removeFuture(): void
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        $ids = DB::table('attendance_records')->where('date', '>', $today)->pluck('id');
        DB::transaction(function () use ($ids, $today) {
            if (Schema::hasTable('time_corrections')) {
                DB::table('time_corrections')->where(fn ($q) => $q->whereIn('attendance_id', $ids)->orWhere('date', '>', $today))->delete();
            }
            if (Schema::hasTable('dtr_certifications')) DB::table('dtr_certifications')->where('period_from', '>', $today)->delete();
            DB::table('attendance_records')->whereIn('id', $ids)->delete();
        });
        $this->info('Removed ' . $ids->count() . " tap record(s) dated after $today.");
    }

    private function saveList(): void
    {
        $order = ['Administrator', 'OJT coordinator', 'HTE supervisor', 'Intern'];
        usort($this->accounts, fn ($a, $b) => [array_search($a['group'], $order), $a['email']] <=> [array_search($b['group'], $order), $b['email']]);

        $csv = "Group,Name,Email,Password,Respondent\n";
        foreach ($this->accounts as $a) $csv .= "\"{$a['group']}\",\"{$a['name']}\",{$a['email']},{$a['password']},\n";
        $file = storage_path('app/evaluation-accounts.csv');
        file_put_contents($file, $csv);

        $this->table(['Group', 'Name', 'Email', 'Password'], array_map(fn ($a) => [$a['group'], $a['name'], $a['email'], $a['password']], $this->accounts));
        $this->info(count($this->accounts) . ' evaluation accounts created.');
        $this->line("The list is saved in storage/app/evaluation-accounts.csv: keep it private and delete it after the evaluation.");
    }
}