<?php
//RequirementController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\OjtHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Due dates of the internship requirements, and reminders to interns who have not submitted
 * (the coordinator's biggest pain points: delayed submissions, students not following instructions).
 */
class RequirementController extends Controller
{
    // requirements an intern can submit many times (a reminder looks at the last 7 days)
    private const REPEATING = ['weekly_report'];

    // GET /api/requirement-deadlines  -> [{ requirement, phase, due_date }]
    public function index()
    {
        return response()->json(
            DB::table('requirement_deadlines')->orderBy('due_date')->get(['requirement', 'phase', 'due_date'])
        );
    }

    // PUT /api/coordinator/requirement-deadlines   { deadlines: [{ requirement, phase, due_date|null }] }
    public function save(Request $request)
    {
        $data = $request->validate([
            'deadlines'               => 'required|array|max:100',
            'deadlines.*.requirement' => 'required|string|max:60',
            'deadlines.*.phase'       => 'required|in:pre,during,post',
            'deadlines.*.due_date'    => 'nullable|date',
        ]);
        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');

        DB::transaction(function () use ($data, $auth, $now) {
            foreach ($data['deadlines'] as $d) {
                $key = ['requirement' => $d['requirement'], 'phase' => $d['phase']];
                if (empty($d['due_date'])) {
                    DB::table('requirement_deadlines')->where($key)->delete();     // cleared = no due date
                    continue;
                }
                $exists = DB::table('requirement_deadlines')->where($key)->exists();
                $row = ['due_date' => Carbon::parse($d['due_date'])->toDateString(), 'set_by' => $auth->id ?? null, 'updated_at' => $now];
                if ($exists) {
                    DB::table('requirement_deadlines')->where($key)->update($row);
                } else {
                    DB::table('requirement_deadlines')->insert($key + $row + ['created_at' => $now]);
                }
            }
        });

        return response()->json(['message' => 'Due dates saved.']);
    }

    // POST /api/coordinator/requirement-deadlines/remind   { requirement, phase, label }
    // -> notifies every intern who has not submitted it (a rejected one counts as not submitted)
    public function remind(Request $request)
    {
        $data = $request->validate([
            'requirement' => 'required|string|max:60',
            'phase'       => 'required|in:pre,during,post',
            'label'       => 'required|string|max:120',
            'user_ids'    => 'nullable|array',          // remind only these interns (from the "who" list)
            'user_ids.*'  => 'integer',
        ]);

        $missing = $this->missingInterns($data['requirement'], $data['phase']);
        if (!empty($data['user_ids'])) {
            $missing = array_values(array_intersect($missing, array_map('intval', $data['user_ids'])));
        }
        if (!$missing) {
            return response()->json(['message' => 'Everyone has submitted it. No reminder sent.']);
        }

        $due = DB::table('requirement_deadlines')
            ->where(['requirement' => $data['requirement'], 'phase' => $data['phase']])
            ->value('due_date');
        $dueText = $due ? ' It is due on ' . Carbon::parse($due)->format('M j, Y') . '.' : '';

        foreach ($missing as $uid) {
            Notifier::toUser($uid, 'Reminder: ' . $data['label'],
                'Please submit your ' . $data['label'] . ' under Submit MOV.' . $dueText, 'requirement_reminder');
        }

        return response()->json([
            'message' => 'Reminder sent to ' . count($missing) . ' intern' . (count($missing) === 1 ? '' : 's') . '.',
        ]);
    }

    // GET /api/coordinator/requirement-deadlines/missing-list?phase=pre&requirement=moa
    // -> the interns who have not submitted it yet (name, photo, program, placement)
    public function missingList(Request $request)
    {
        $data = $request->validate([
            'requirement' => 'required|string|max:60',
            'phase'       => 'required|in:pre,during,post',
        ]);
        $ids = $this->missingInterns($data['requirement'], $data['phase']);
        if (!$ids) return response()->json([]);

        return response()->json(
            DB::table('users')->whereIn('id', $ids)->orderBy('name')
                ->get(['id', 'name', 'email', 'photo', 'program', 'placement', 'student_id'])
        );
    }

    // GET /api/staff/interns/{id}/requirements  -> every MOV of one intern (for the checklist in their profile)
    // A supervisor can only open their own interns.
    public function internRequirements(Request $request, $id)
    {
        $auth  = $request->attributes->get('auth_user');
        $scope = \App\Services\Supervision::scope($auth);
        if ($scope !== null && !in_array((int) $id, $scope, true)) {
            return response()->json(['message' => 'Intern not found.'], 404);
        }
        // only the OJT coordinator may open the files the intern uploaded;
        // admin and HTE supervisors see the status only (no file link is sent to them)
        $cols = ['id', 'requirement', 'phase', 'status', 'remarks', 'title', 'report_id', 'created_at', 'updated_at'];
        if ($auth && $auth->role === 'ojt_coordinator') {
            $cols[] = 'file_path';
            $cols[] = 'original_name';
        }
        return response()->json(
            DB::table('movs')->where('user_id', $id)->orderByDesc('id')->get($cols)
        );
    }

    // GET /api/coordinator/requirement-deadlines/missing  -> { "pre:moa": 3, ... } how many interns still lack each one
    // Counted in ONE pass (one query for interns, one for all MOVs, hours worked out once per intern),
    // instead of separate queries for every requirement — much faster on Supabase.
    public function missingCounts(Request $request)
    {
        $wanted = [];
        foreach ((array) $request->query('keys', []) as $k) {
            [$phase, $req] = array_pad(explode(':', (string) $k, 2), 2, '');
            if (in_array($phase, ['pre', 'during', 'post'], true) && $req !== '') $wanted[$k] = [$phase, $req];
        }
        if (!$wanted) return response()->json([]);

        $interns = DB::table('users')->where('role', 'intern')->get(['id', 'program', 'tracking_type']);
        $weekAgo = Carbon::now('Asia/Manila')->subDays(7);

        // who already sent each requirement (pending or approved)
        $done = [];
        DB::table('movs')
            ->whereIn('requirement', array_unique(array_column($wanted, 1)))
            ->whereIn('status', ['pending', 'approved'])
            ->get(['user_id', 'requirement', 'phase', 'created_at'])
            ->each(function ($m) use (&$done, $weekAgo) {
                // reports that repeat count only if sent in the last 7 days
                if (in_array($m->requirement, self::REPEATING, true) && Carbon::parse($m->created_at)->lt($weekAgo)) return;
                $done[($m->phase ?? 'pre') . ':' . $m->requirement][(int) $m->user_id] = true;
            });

        // post-internship requirements only count interns who finished their hours (worked out once each)
        $postOpen = [];
        if (in_array('post', array_column($wanted, 0), true)) {
            foreach ($interns as $u) {
                $postOpen[(int) $u->id] = ($u->tracking_type ?? 'hours') === 'output'
                    || OjtHours::rendered((int) $u->id) + 0.0001 >= $this->requiredHours($u->program);
            }
        }

        // during- and post-internship requirements only count interns whose internship has started
        $started = in_array('during', array_column($wanted, 0), true) || in_array('post', array_column($wanted, 0), true)
            ? $this->startedInterns() : [];

        $out = [];
        foreach ($wanted as $k => [$phase]) {
            $n = 0;
            foreach ($interns as $u) {
                $id = (int) $u->id;
                if (isset($done[$k][$id])) continue;
                if ($phase !== 'pre' && !isset($started[$id])) continue;
                if ($phase === 'post' && empty($postOpen[$id])) continue;
                $n++;
            }
            $out[$k] = $n;
        }
        return response()->json($out);
    }

    /** Interns whose internship has started: every pre-internship requirement approved (one query for all). */
    private function startedInterns(): array
    {
        if (!config('ojt.require_pre_requirements', true)) {
            return DB::table('users')->where('role', 'intern')->pluck('id')->map(fn ($i) => (int) $i)->flip()->all();
        }
        $required = config('ojt.pre_requirements', []);
        return DB::table('movs')
            ->whereIn('requirement', $required)
            ->where(function ($q) { $q->where('phase', 'pre')->orWhereNull('phase'); })
            ->where('status', 'approved')
            ->select('user_id', DB::raw('count(distinct requirement) as n'))
            ->groupBy('user_id')
            ->get()
            ->filter(fn ($r) => (int) $r->n >= count($required))
            ->pluck('user_id')->map(fn ($i) => (int) $i)->flip()->all();
    }

    /** Interns who have not submitted this requirement (pending or approved). */
    private function missingInterns(string $requirement, string $phase): array
    {
        $interns = DB::table('users')->where('role', 'intern')->get(['id', 'program', 'tracking_type']);

        $q = DB::table('movs')
            ->where('requirement', $requirement)
            ->where('phase', $phase)
            ->whereIn('status', ['pending', 'approved']);
        if (in_array($requirement, self::REPEATING, true)) {
            $q->where('created_at', '>=', Carbon::now('Asia/Manila')->subDays(7));
        }
        $done = $q->pluck('user_id')->map(fn ($id) => (int) $id)->flip();

        $started = $phase === 'pre' ? [] : $this->startedInterns();

        $out = [];
        foreach ($interns as $u) {
            if (isset($done[(int) $u->id])) continue;
            // during / post: only interns whose internship has started (all pre-internship requirements approved)
            if ($phase !== 'pre' && !isset($started[(int) $u->id])) continue;
            // post-internship requirements only open after the required hours are done
            if ($phase === 'post' && ($u->tracking_type ?? 'hours') !== 'output'
                && OjtHours::rendered((int) $u->id) + 0.0001 < $this->requiredHours($u->program)) {
                continue;
            }
            $out[] = (int) $u->id;
        }
        return $out;
    }

    private function requiredHours(?string $program): float
    {
        $p = strtoupper((string) $program);
        $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';

        return (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 460));
    }
}