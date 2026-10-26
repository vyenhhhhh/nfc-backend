<?php
//ReportController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reports the OJT coordinator asks for (the "Weekly / Monthly Report" requirement).
 * The coordinator decides when a report is needed: she adds one with a name and a due date,
 * for all interns, for some programs (BSIT / BSIS / BSCS), or for specific interns.
 * Interns upload into the report (movs.report_id). Old uploads are never replaced or deleted.
 */
class ReportController extends Controller
{
    private const PROGRAMS = ['BSIT', 'BSIS', 'BSCS'];

    // "BSIT - 4" -> "BSIT" (same rule as the frontend's programKey)
    private static function programKey(?string $program): ?string
    {
        $p = strtoupper((string) $program);
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) return 'BSIT';
        if (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) return 'BSIS';
        if (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) return 'BSCS';
        return null;
    }

    private static function decode($json): array
    {
        $v = json_decode((string) $json, true);
        return is_array($v) ? array_values($v) : [];
    }

    // the report as the frontend sees it (programs / intern_ids as arrays)
    private static function shape($r): array
    {
        return [
            'id'         => (int) $r->id,
            'title'      => $r->title,
            'due_date'   => $r->due_date ? substr((string) $r->due_date, 0, 10) : null,
            'audience'   => $r->audience ?: 'all',
            'programs'   => self::decode($r->programs),
            'intern_ids' => array_map('intval', self::decode($r->intern_ids)),
            'created_at' => $r->created_at,
        ];
    }

    // does this report apply to this intern?
    private static function appliesTo($r, $intern): bool
    {
        $aud = $r->audience ?: 'all';
        if ($aud === 'programs') return in_array(self::programKey($intern->program ?? null), self::decode($r->programs), true);
        if ($aud === 'interns')  return in_array((int) $intern->id, array_map('intval', self::decode($r->intern_ids)), true);
        return true;
    }

    // ids of the interns the report is for
    private static function targets($r): array
    {
        $interns = DB::table('users')->where('role', 'intern')->get(['id', 'program']);
        return $interns->filter(fn ($u) => self::appliesTo($r, $u))->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }

    // interns the report is for who have not sent it yet (a waiting or approved upload counts as sent)
    private static function missing($r): array
    {
        $sent = DB::table('movs')->where('report_id', $r->id)->whereIn('status', ['pending', 'approved'])
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
        return array_values(array_diff(self::targets($r), $sent));
    }

    // GET /api/reports
    //   intern -> only the reports for them
    //   staff  -> every report, with how many interns it is for / sent / approved
    public function index(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        $rows = DB::table('report_schedules')
            ->orderByRaw('due_date IS NULL, due_date')->orderBy('id')->get();

        if ($auth && $auth->role === 'intern') {
            $me = DB::table('users')->where('id', $auth->id)->first(['id', 'program']);
            return response()->json($rows->filter(fn ($r) => $me && self::appliesTo($r, $me))->map(fn ($r) => self::shape($r))->values());
        }

        return response()->json($rows->map(function ($r) {
            $targets = self::targets($r);
            $subs = DB::table('movs')->where('report_id', $r->id)->get(['user_id', 'status']);
            $sent = $subs->whereIn('status', ['pending', 'approved'])->pluck('user_id')->unique()->intersect($targets)->count();
            $approved = $subs->where('status', 'approved')->pluck('user_id')->unique()->intersect($targets)->count();
            return self::shape($r) + ['total' => count($targets), 'sent' => $sent, 'approved' => $approved];
        })->values());
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'        => 'required|string|max:150',
            'due_date'     => 'nullable|date',
            'audience'     => 'required|in:all,programs,interns',
            'programs'     => 'nullable|array',
            'programs.*'   => 'in:' . implode(',', self::PROGRAMS),
            'intern_ids'   => 'nullable|array',
            'intern_ids.*' => 'integer',
        ]);
        if ($data['audience'] === 'programs' && empty($data['programs'])) {
            abort(response()->json(['message' => 'Pick at least one program.'], 422));
        }
        if ($data['audience'] === 'interns' && empty($data['intern_ids'])) {
            abort(response()->json(['message' => 'Pick at least one intern.'], 422));
        }
        return [
            'title'      => trim($data['title']),
            'due_date'   => $data['due_date'] ?? null,
            'audience'   => $data['audience'],
            'programs'   => $data['audience'] === 'programs' ? json_encode(array_values(array_unique($data['programs']))) : null,
            'intern_ids' => $data['audience'] === 'interns' ? json_encode(array_values(array_unique(array_map('intval', $data['intern_ids'])))) : null,
        ];
    }

    private static function dueText($due): string
    {
        return $due ? ' It is due on ' . Carbon::parse($due)->format('M j, Y') . '.' : '';
    }

    // POST /api/coordinator/reports  -> add a report and tell the interns it is for
    public function store(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        $data = $this->validated($request);
        $id = DB::table('report_schedules')->insertGetId($data + [
            'created_by' => $auth->id ?? null, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $r = DB::table('report_schedules')->where('id', $id)->first();

        foreach (self::targets($r) as $uid) {
            Notifier::toUser($uid, 'New report: ' . $r->title,
                'Your OJT coordinator asked for "' . $r->title . '". Upload it under Submit MOV → During Internship → Weekly / Monthly Report.'
                . self::dueText($r->due_date), 'requirement_reminder');
        }
        return response()->json(['message' => '"' . $r->title . '" added.', 'id' => $id]);
    }

    // PUT /api/coordinator/reports/{id}
    public function update(Request $request, $id)
    {
        $r = DB::table('report_schedules')->where('id', $id)->first();
        if (!$r) return response()->json(['message' => 'Report not found.'], 404);
        DB::table('report_schedules')->where('id', $id)->update($this->validated($request) + ['updated_at' => Carbon::now()]);
        return response()->json(['message' => 'Report updated.']);
    }

    // DELETE /api/coordinator/reports/{id}  -> only while nobody has uploaded to it (files are never lost)
    public function destroy($id)
    {
        $r = DB::table('report_schedules')->where('id', $id)->first();
        if (!$r) return response()->json(['message' => 'Report not found.'], 404);
        $n = DB::table('movs')->where('report_id', $id)->count();
        if ($n > 0) {
            return response()->json(['message' => "Interns already uploaded {$n} file" . ($n === 1 ? '' : 's') . ' to this report, so it can\'t be removed. You can still change its name or due date.'], 409);
        }
        DB::table('report_schedules')->where('id', $id)->delete();
        return response()->json(['message' => '"' . $r->title . '" removed.']);
    }

    // GET /api/coordinator/reports/{id}/missing -> interns who haven't sent it
    public function missingList($id)
    {
        $r = DB::table('report_schedules')->where('id', $id)->first();
        if (!$r) return response()->json([], 404);
        $ids = self::missing($r);
        if (!$ids) return response()->json([]);
        return response()->json(
            DB::table('users')->whereIn('id', $ids)->orderBy('name')
                ->get(['id', 'name', 'email', 'photo', 'program', 'placement', 'student_id'])
        );
    }

    // POST /api/coordinator/reports/{id}/remind   { user_ids?: [..] }
    public function remind(Request $request, $id)
    {
        $r = DB::table('report_schedules')->where('id', $id)->first();
        if (!$r) return response()->json(['message' => 'Report not found.'], 404);
        $data = $request->validate(['user_ids' => 'nullable|array', 'user_ids.*' => 'integer']);

        $missing = self::missing($r);
        if (!empty($data['user_ids'])) {
            $missing = array_values(array_intersect($missing, array_map('intval', $data['user_ids'])));
        }
        if (!$missing) return response()->json(['message' => 'Everyone has sent it. No reminder sent.']);

        foreach ($missing as $uid) {
            Notifier::toUser($uid, 'Reminder: ' . $r->title,
                'Please upload your "' . $r->title . '" under Submit MOV → During Internship → Weekly / Monthly Report.'
                . self::dueText($r->due_date), 'requirement_reminder');
        }
        return response()->json(['message' => 'Reminder sent to ' . count($missing) . ' intern' . (count($missing) === 1 ? '' : 's') . '.']);
    }

    // GET /api/staff/reports/overdue -> reports past their due date, with the interns who haven't sent them
    //   [{ id, title, due_date, user_ids: [..] }]   (for "Needs attention" on the staff dashboards)
    public function overdue()
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        $rows = DB::table('report_schedules')->whereNotNull('due_date')->where('due_date', '<', $today)->orderBy('due_date')->get();
        return response()->json($rows->map(fn ($r) => [
            'id' => (int) $r->id, 'title' => $r->title, 'due_date' => substr((string) $r->due_date, 0, 10), 'user_ids' => self::missing($r),
        ])->filter(fn ($x) => count($x['user_ids']) > 0)->values());
    }

    // used by MovController: may this intern upload to this report?
    public static function allowsIntern($reportId, $internId): ?object
    {
        $r = DB::table('report_schedules')->where('id', $reportId)->first();
        $u = DB::table('users')->where('id', $internId)->first(['id', 'program']);
        return ($r && $u && self::appliesTo($r, $u)) ? $r : null;
    }
}