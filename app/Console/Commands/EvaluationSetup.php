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
 | USER EVALUATION ACCOUNTS: TEST DATABASE ONLY
 |
 |   php artisan eval:setup                  creates the evaluation accounts and their sample data
 |   php artisan eval:setup --remove-future  also deletes taps dated after today (left over from clock-change tests)
 |   php artisan eval:setup --remove-demo    also deletes the old test accounts (…@demo.test) and what they made
 |                                           (test announcements, report requests, due dates, quarterly reports),
 |                                           so the evaluation starts clean; kiosks, requirements, forms and calendar stay
 |   php artisan eval:cleanup                deletes every evaluation account and its data (after the evaluation)
 |
 | Accounts (by default one of each, shared by the respondents of that group):
 |   intern, finished OJT (past interns):     hours complete, DTR certified, requirements approved,
 |                                            grade released; System Output Presentation left to upload
 |   intern, upcoming OJT (upcoming interns): not started: pre-internship requirements to upload
 |   HTE supervisor, with their own HTE, the interns above and 2 practice interns with work waiting
 |                                            (overtime, a forgotten time out, an uncertified DTR period, the appraisal)
 |   OJT coordinator: sees every evaluation intern, with practice work waiting
 |                                            (endorsed overtime, post-internship uploads to review, grades to release)
 |   administrator
 | More of each:  php artisan eval:setup --finished=15 --upcoming=15 --supervisors=2
 | Between respondents (a shared account starts fresh again):  php artisan eval:cleanup  then  php artisan eval:setup
 |
 | Every account has the same password (Password123!, or --password=...). The list is saved to storage/app/evaluation-accounts.csv
 | (keep it private, delete it after the evaluation) and printed on the screen.
 */
class EvaluationSetup extends Command
{
    protected $signature = 'eval:setup {--remove-future : Also delete taps dated after today (from clock-change tests)} {--remove-demo : Also delete the old test accounts (@demo.test) and what they made} {--finished=1 : How many finished interns} {--upcoming=1 : How many upcoming interns} {--supervisors=1 : How many HTE supervisors (1 or 2)} {--password=Password123! : The password of every evaluation account} {--force : Do not ask to confirm}';
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
        $now = Carbon::now('Asia/Manila');

        $admin = $this->user('admin', 'CCIS', 'Administrator', 'admin', ['position' => 'System Administrator']);
        $coord = $this->user('ojt_coordinator', 'Jhoanna Victoria', 'Pitao', 'coordinator', ['position' => 'OJT Coordinator']);

        $htes = [
            ['Evaluation HTE 1', 'Government Agency', 'Butuan City', '2027-06-30'],
            ['Evaluation HTE 2', 'Educational Institution', 'Butuan City', '2027-03-31'],
        ];
        $nSup = max(1, min(2, (int) $this->option('supervisors')));
        $htes = array_slice($htes, 0, $nSup);
        $sups = [];
        foreach ($htes as $i => [$hte, $cat, $addr, $moa]) {
            $n = $i + 1;
            $sups[] = [$this->user('supervisor', 'HTE Supervisor', (string) $n, "supervisor$n", [
                'position' => 'Training Supervisor', 'placement' => $hte, 'hte_address' => $addr, 'hte_category' => $cat,
                'moa_valid_until' => $moa, 'hte_modality' => 'onsite', 'output_required' => true,
            ]), $hte];
        }

        // [first, last, program]
        $finished = [
            ['Andrea', 'Bautista', 'BSIT - 4'], ['Carlo', 'Mercado', 'BSIT - 4'], ['Danica', 'Flores', 'BSCS - 4'],
            ['Enrico', 'Salazar', 'BSIS - 4'], ['Faith', 'Domingo', 'BSIT - 4'], ['Gabriel', 'Aquino', 'BSCS - 4'],
            ['Hannah', 'Villareal', 'BSIS - 4'], ['Ivan', 'Pascual', 'BSIT - 4'], ['Joanna', 'Navarro', 'BSCS - 4'],
            ['Kevin', 'Castillo', 'BSIT - 4'], ['Lea', 'Fernandez', 'BSIS - 4'], ['Miguel', 'Santiago', 'BSCS - 4'],
            ['Nicole', 'Ramirez', 'BSIT - 4'], ['Oscar', 'Gonzales', 'BSIS - 4'], ['Patricia', 'Morales', 'BSIT - 4'],
        ];
        $upcoming = [
            ['Queenie', 'Tolentino', 'BSIT - 4'], ['Rafael', 'Mendoza', 'BSCS - 4'], ['Sophia', 'Reyes', 'BSIS - 4'],
            ['Tristan', 'Valdez', 'BSIT - 4'], ['Ursula', 'Cabrera', 'BSCS - 4'], ['Vincent', 'Dela Rosa', 'BSIT - 4'],
            ['Wendy', 'Lim', 'BSIS - 4'], ['Xavier', 'Ocampo', 'BSIT - 4'], ['Yvonne', 'Padilla', 'BSCS - 4'],
            ['Zachary', 'Ramos', 'BSIT - 4'], ['Alyssa', 'Gutierrez', 'BSIS - 4'], ['Bryan', 'Soriano', 'BSCS - 4'],
            ['Camille', 'De Leon', 'BSIT - 4'], ['Dominic', 'Espiritu', 'BSIS - 4'], ['Ella', 'Manalo', 'BSIT - 4'],
        ];
        $practice = array_slice([
            ['Practice', 'Intern A', 'BSIT - 4'], ['Practice', 'Intern B', 'BSCS - 4'],
            ['Practice', 'Intern C', 'BSIT - 4'], ['Practice', 'Intern D', 'BSIS - 4'],
        ], 0, 2 * $nSup);   // two for each supervisor
        // easy to tell apart: "Finished Intern 1", "Upcoming Intern 2", ... (the programs stay mixed)
        $finished = array_map(fn ($r, $i) => ['Finished', 'Intern ' . ($i + 1), $r[2]], $finished, array_keys($finished));
        $upcoming = array_map(fn ($r, $i) => ['Upcoming', 'Intern ' . ($i + 1), $r[2]], $upcoming, array_keys($upcoming));
        $finished = array_slice($finished, 0, max(0, min(15, (int) $this->option('finished'))));
        $upcoming = array_slice($upcoming, 0, max(0, min(15, (int) $this->option('upcoming'))));

        foreach ($finished as $i => [$f, $l, $prog]) {
            [$sup, $hte] = $sups[$i % $nSup];
            $id = $this->intern($f, $l, $prog, $sup, $hte, sprintf('finished%02d', $i + 1), sprintf('26-9%04d', $i + 1), 'finished');
            $this->finishedData($id, $sup, $coord, $admin, $prog, false);
        }
        foreach ($upcoming as $i => [$f, $l, $prog]) {
            [$sup, $hte] = $sups[$i % $nSup];
            $this->intern($f, $l, $prog, $sup, $hte, sprintf('upcoming%02d', $i + 1), sprintf('26-8%04d', $i + 1), 'upcoming');
        }
        // practice interns: for the supervisors and the coordinator to act on (nobody signs in as them)
        foreach ($practice as $i => [$f, $l, $prog]) {
            [$sup, $hte] = $sups[intdiv($i, 2)];
            $id = $this->intern($f, $l, $prog, $sup, $hte, 'practice' . chr(97 + $i), sprintf('26-7%04d', $i + 1), 'practice');
            $this->finishedData($id, $sup, $coord, $admin, $prog, true);
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

    private function intern(string $f, string $l, string $prog, int $sup, string $hte, string $login, string $sid, string $kind): int
    {
        return $this->user('intern', $f, $l, $login, [
            'student_id' => $sid, 'program' => $prog, 'college' => 'CCIS', 'semester' => '2nd Semester',
            'placement' => $hte, 'supervisor_id' => $sup, 'work_mode' => 'onsite', 'modality' => 'onsite', 'scope' => 'local',
            'modality_custom' => false, 'tracking_type' => 'hours', 'dtr_mode' => 'two', 'output_required' => true, 'prior_hours' => 0,
        ], ['finished' => 'Intern: finished OJT', 'upcoming' => 'Intern: upcoming OJT', 'practice' => 'practice'][$kind]);
    }

    // ───────────────────────── a finished internship ─────────────────────────
    /**
     * The whole internship of one intern: approved pre and during requirements, a card, the attendance
     * up to the required hours, certified DTR periods, then either
     *   respondent: approved post requirements (System Output left to upload) and a released grade, or
     *   practice:   work waiting for the supervisor and the coordinator.
     */
    private function finishedData(int $id, int $sup, int $coord, int $admin, string $prog, bool $practice): void
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
        $card = ['user_id' => $id, 'uid' => $uid, 'created_at' => $start->copy()->subDays(5)];
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
        while ($total < $required + ($practice ? 10 : 1)) {
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

        $waiting = [];   // dates with work waiting: their DTR period is left for the supervisor to certify
        if ($practice) {
            // work waiting in the last week: one overtime for the supervisor, one endorsed to the coordinator,
            // one forgotten time out with a correction request (the supervisor's decision is final)
            $last = DB::table('attendance_records')->where('user_id', $id)->orderByDesc('date')->limit(3)->get();
            if ($last->count() === 3) {
                [$a, $b, $c] = [$last[0], $last[1], $last[2]];
                $waiting = [substr((string) $a->date, 0, 10), substr((string) $b->date, 0, 10), substr((string) $c->date, 0, 10)];
                $outA = Carbon::parse($a->date . ' 18:00:00', 'Asia/Manila');
                DB::table('attendance_records')->where('id', $a->id)->update($this->only('attendance_records', [
                    'checked_out_at' => $outA, 'ot_minutes' => 60, 'ot_status' => 'pending', 'ot_approved_minutes' => 0,
                    'ot_reviewed_by' => null, 'ot_reviewed_at' => null,
                ]));
                $outB = Carbon::parse($b->date . ' 18:30:00', 'Asia/Manila');
                DB::table('attendance_records')->where('id', $b->id)->update($this->only('attendance_records', [
                    'checked_out_at' => $outB, 'ot_minutes' => 90, 'ot_status' => 'endorsed', 'ot_approved_minutes' => 0,
                    'ot_endorsed_minutes' => 90, 'ot_endorsed_by' => $sup, 'ot_endorsed_at' => $outB->copy()->addDay(),
                    'ot_reviewed_by' => null, 'ot_reviewed_at' => null,
                ]));
                DB::table('attendance_records')->where('id', $c->id)->update([
                    'action' => 'TIME_IN', 'checked_out_at' => null, 'ot_minutes' => 0, 'ot_status' => null, 'ot_approved_minutes' => 0,
                ]);
                DB::table('time_corrections')->insert($this->only('time_corrections', [
                    'user_id' => $id, 'attendance_id' => $c->id, 'date' => $c->date, 'kind' => 'out',
                    'requested_out' => Carbon::parse($c->date . ' 17:00:00', 'Asia/Manila'),
                    'reason' => 'I forgot to tap my card when I left at 5:00 PM.', 'status' => 'pending',
                    'created_at' => Carbon::parse($c->date . ' 20:00:00', 'Asia/Manila'), 'updated_at' => Carbon::parse($c->date . ' 20:00:00', 'Asia/Manila'),
                ]));
            }
        }

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

        // DTR periods (1–15, 16–end of month) certified by the supervisor; a practice intern's last one is left to certify
        $dates = DB::table('attendance_records')->where('user_id', $id)->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10));
        $periods = [];
        foreach ($dates as $d) {
            $c = Carbon::parse($d);
            $from = $c->day <= 15 ? $c->copy()->startOfMonth() : $c->copy()->setDay(16);
            $to   = $c->day <= 15 ? $c->copy()->setDay(15) : $c->copy()->endOfMonth();
            $periods[$from->toDateString()] = $to->toDateString();
        }
        foreach ($periods as $from => $to) {
            if ($practice && collect($waiting)->contains(fn ($w) => $w >= $from && $w <= $to)) continue;   // left to certify
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
        if ($practice) {
            // uploaded after the internship, waiting for the coordinator
            foreach (['certificate_of_completion', 'narrative_report'] as $k) {
                if ($post->has($k)) $this->mov($id, $post[$k], 'pending', null, $end->copy()->addDays(3), true);
            }
            return;
        }

        // a respondent: post requirements approved except the System Output Presentation (theirs to upload)
        foreach ($post as $k => $r) {
            if ($k === 'system_output') continue;
            $this->mov($id, $r, 'approved', $coord, $end->copy()->addDays(5));
        }

        // the grade: the supervisor's appraisal (60%) and the coordinator's evaluation (40%), released
        $supRatings = []; foreach (Grading::keys('supervisor') as $k) $supRatings[$k] = random_int(4, 5);
        $coRatings  = []; foreach (Grading::keys('coordinator') as $k) $coRatings[$k] = random_int(4, 5);
        if (!$supRatings || !$coRatings || !Schema::hasTable('evaluations') || !Schema::hasTable('intern_grades')) return;
        $evAt = $end->copy()->addDays(2)->setTime(15, 0);
        $supName = DB::table('users')->where('id', $sup)->value('name');
        DB::table('evaluations')->insert($this->only('evaluations', [
            'intern_id' => $id, 'evaluator_id' => $sup, 'evaluator_name' => $supName, 'evaluator_role' => 'supervisor', 'mode' => 'system',
            'ratings' => json_encode($supRatings), 'average' => round(array_sum($supRatings) / count($supRatings), 2),
            'strengths' => 'Reliable and quick to learn; worked well with the team.', 'improvements' => 'Ask questions earlier when a task is unclear.',
            'recommendation' => 'recommend', 'share_comments' => true, 'created_at' => $evAt, 'updated_at' => $evAt,
        ]));
        $s = Grading::supervisor($supRatings)['total'];
        $c = Grading::coordinator($coRatings)['total'];
        $gAt = $end->copy()->addDays(10)->setTime(9, 0);
        DB::table('intern_grades')->insert($this->only('intern_grades', [
            'intern_id' => $id, 'ratings' => json_encode($coRatings), 'coordinator_score' => $c, 'supervisor_score' => $s,
            'final_grade' => Grading::final($s, $c), 'remarks' => 'Completed the internship requirements.',
            'graded_by' => $coord, 'graded_by_name' => 'Jhoanna Victoria Pitao', 'finalized_at' => $gAt, 'created_at' => $gAt, 'updated_at' => $gAt,
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
        $order = ['Administrator', 'OJT coordinator', 'HTE supervisor', 'Intern: finished OJT', 'Intern: upcoming OJT'];
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