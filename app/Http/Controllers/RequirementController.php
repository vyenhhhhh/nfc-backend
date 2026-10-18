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
        ]);

        $missing = $this->missingInterns($data['requirement'], $data['phase']);
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

    // GET /api/coordinator/requirement-deadlines/missing  -> { "pre:moa": 3, ... } how many interns still lack each one
    public function missingCounts(Request $request)
    {
        $keys = (array) $request->query('keys', []);   // ["pre:moa", "during:weekly_report", ...]
        $out = [];
        foreach ($keys as $k) {
            [$phase, $req] = array_pad(explode(':', (string) $k, 2), 2, '');
            if (!in_array($phase, ['pre', 'during', 'post'], true) || $req === '') continue;
            $out[$k] = count($this->missingInterns($req, $phase));
        }
        return response()->json($out);
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

        $out = [];
        foreach ($interns as $u) {
            if (isset($done[(int) $u->id])) continue;
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