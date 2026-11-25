<?php
//GradeController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\Grading;
use App\Services\Notifier;
use App\Services\OjtHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The internship grade (CCIS Internship Grading Criteria):
 *   HTE supervisor appraisal (60%)  → saved by EvaluationController in "evaluations"
 *   SIP coordinator evaluation (40%) → saved here in "intern_grades"
 * The coordinator finalizes the grade; only then does the intern see it.
 */
class GradeController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    // documents the coordinator looks at while grading (latest upload of each)
    private const DOCS = ['narrative_report', 'evaluation_forms', 'performance_appraisal', 'supervisor_feedback_form', 'certificate_of_completion', 'dtr'];

    // GET /api/grades/criteria
    public function criteria()
    {
        return response()->json(Grading::config());
    }

    /** days present and late days (time in after 8:00) from the attendance log */
    /** Days present and days late of many interns with ONE query: [user_id => ['days' => n, 'late' => n]] */
    private static function attendanceMany(array $userIds): array
    {
        $out = [];
        foreach ($userIds as $id) $out[(int) $id] = ['days' => 0, 'late' => 0];
        if (!$userIds) return $out;
        $rows = DB::table('attendance_records')->whereIn('user_id', $userIds)->whereNotNull('checked_in_at')
            ->orderBy('checked_in_at')->get(['user_id', 'date', 'checked_in_at']);
        foreach ($rows->groupBy('user_id') as $uid => $userRows) $out[(int) $uid] = self::countAttendance($userRows);
        return $out;
    }

    private static function countAttendance($rows): array
    {
        $lateAfter = config('ojt.grading.late_after', '08:00');
        $days = []; $late = 0;
        foreach ($rows as $r) {
            $d = (string) $r->date;
            if (isset($days[$d])) continue;           // the first time in of the day counts
            $days[$d] = true;
            if (Carbon::parse($r->checked_in_at)->timezone('Asia/Manila')->format('H:i') > $lateAfter) $late++;
        }
        return ['days' => count($days), 'late' => $late];
    }

    // true when the intern uploaded the narrative report (waiting or approved), or when the coordinator
    // removed that requirement from the list (then it can't be asked for)
    private static function hasNarrative(int $internId): bool
    {
        if (\App\Services\SchemaCache::has('requirement_defs')) {
            $def = DB::table('requirement_defs')->where('key', 'narrative_report')->first();
            if ($def && !$def->active) return true;
        }
        return DB::table('movs')->where('user_id', $internId)->where('requirement', 'narrative_report')
            ->whereIn('status', ['pending', 'approved'])->exists();
    }

    private static function requiredHours(?string $program, $prior = 0): float
    {
        $p = strtoupper((string) $program); $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';
        // hours already rendered in an earlier term are taken off
        return max(0.0, (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 486)) - (float) $prior);
    }

    // GET /api/grades   (coordinator / admin: every intern · supervisor: their own interns)
    public function index(Request $request)
    {
        $auth = $this->auth($request);
        // grades are academic records: only the people who grade see them (the HTE supervisor and the OJT coordinator)
        if (($auth->role ?? '') === 'admin') {
            return response()->json(['message' => 'Internship grades are handled by the OJT coordinator and the HTE supervisors.'], 403);
        }
        $ready = \App\Services\SchemaCache::has('intern_grades') && \App\Services\SchemaCache::has('evaluations');
        $q = DB::table('users')->where('role', 'intern');
        if ($auth->role === 'supervisor') $q->where('supervisor_id', $auth->id);
        $interns = $q->orderBy('name')->get();
        $ids = $interns->pluck('id')->all();

        $evals = \App\Services\SchemaCache::has('evaluations') ? DB::table('evaluations')->whereIn('intern_id', $ids)->get()->keyBy('intern_id') : collect();
        $grades = \App\Services\SchemaCache::has('intern_grades') ? DB::table('intern_grades')->whereIn('intern_id', $ids)->get()->keyBy('intern_id') : collect();
        $sups = DB::table('users')->whereIn('id', $interns->pluck('supervisor_id')->filter()->unique()->all())->pluck('name', 'id');
        // the HTE's address (on the supervisor's account) for "Address of Company" on form F-OSIP-PAR-004
        $supAddr = \App\Services\SchemaCache::has('users', 'hte_address')
            ? DB::table('users')->whereIn('id', $interns->pluck('supervisor_id')->filter()->unique()->all())->pluck('hte_address', 'id') : collect();

        $docs = [];
        if ($auth->role !== 'supervisor' && \App\Services\SchemaCache::has('movs')) {
            foreach (DB::table('movs')->whereIn('user_id', $ids)->whereIn('requirement', self::DOCS)->orderBy('created_at')->get() as $m) {
                $docs[$m->user_id][$m->requirement] = ['status' => $m->status, 'file_path' => $m->file_path, 'original_name' => $m->original_name ?? null, 'created_at' => $m->created_at];
            }
        }

        $mine = $auth->role === 'supervisor';
        // the narrative report must be uploaded before the grade is released (unless the requirement was removed)
        $narrOff = \App\Services\SchemaCache::has('requirement_defs')
            && ($nd = DB::table('requirement_defs')->where('key', 'narrative_report')->first()) && !$nd->active;
        $narrIds = $narrOff || $mine ? [] : array_flip(DB::table('movs')->whereIn('user_id', $ids)->where('requirement', 'narrative_report')
            ->whereIn('status', ['pending', 'approved'])->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        // hours and attendance of every intern in two queries (not two per intern)
        $hoursOf = OjtHours::renderedMany($ids);
        $attOf   = self::attendanceMany($ids);
        $rows = $interns->map(function ($u) use ($evals, $grades, $sups, $docs, $supAddr, $mine, $hoursOf, $attOf, $narrOff, $narrIds) {
            $e = $evals[$u->id] ?? null;
            $g = $grades[$u->id] ?? null;
            $sup = $e && $e->mode === 'system' ? Grading::supervisor($e->ratings) : ['total' => null, 'categories' => []];
            $coord = $g ? Grading::coordinator($g->ratings) : ['total' => null, 'categories' => []];
            return [
                'id' => (int) $u->id, 'name' => $u->name, 'student_id' => $u->student_id ?? null, 'program' => $u->program ?? null,
                'placement' => $u->placement ?? null, 'photo' => $u->photo ?? null, 'semester' => $u->semester ?? null, 'tracking_type' => $u->tracking_type ?? 'hours',
                'supervisor_id' => $u->supervisor_id ? (int) $u->supervisor_id : null, 'supervisor_name' => $u->supervisor_id ? ($sups[$u->supervisor_id] ?? null) : null,
                'hte_address' => $u->supervisor_id ? ($supAddr[$u->supervisor_id] ?? null) : null,
                'hours' => round($hoursOf[(int) $u->id] ?? 0, 2), 'required_hours' => self::requiredHours($u->program ?? null, $u->prior_hours ?? 0),
                'attendance' => $attOf[(int) $u->id] ?? ['days' => 0, 'late' => 0],
                'appraisal' => $e && $e->mode === 'system' ? [
                    'id' => (int) $e->id, 'ratings' => json_decode($e->ratings ?: '{}', true), 'score' => $sup['total'], 'categories' => $sup['categories'],
                    'strengths' => $e->strengths, 'improvements' => $e->improvements, 'comments' => $e->comments, 'share_comments' => (bool) ($e->share_comments ?? true),
                    'evaluator_name' => $e->evaluator_name, 'updated_at' => $e->updated_at,
                ] : null,
                // the supervisor's own draft (not submitted yet): only the supervisor sees it
                'draft' => $mine && $e && $e->mode === 'draft' ? [
                    'ratings' => json_decode($e->ratings ?: '{}', true), 'strengths' => $e->strengths, 'improvements' => $e->improvements,
                    'comments' => $e->comments, 'share_comments' => (bool) ($e->share_comments ?? true), 'updated_at' => $e->updated_at,
                ] : null,
                'grade' => $g ? [
                    'ratings' => json_decode($g->ratings ?: '{}', true), 'coordinator_score' => $coord['total'], 'categories' => $coord['categories'],
                    'final_grade' => $fg = ($g->finalized_at ? (float) $g->final_grade : Grading::final($sup['total'], $coord['total'])),
                    'equivalent' => Grading::equivalent($fg),
                    'remarks' => $g->remarks, 'graded_by_name' => $g->graded_by_name, 'finalized_at' => $g->finalized_at, 'updated_at' => $g->updated_at,
                ] : null,
                'docs' => $docs[$u->id] ?? (object) [],
                'narrative_ok' => $mine ? null : ($narrOff || isset($narrIds[(int) $u->id])),
            ];
        });

        return response()->json(['ready' => $ready, 'interns' => $rows]);
    }

    // GET /api/grades/summary   (OJT coordinator: the sidebar count on Grades)
    // ready = the supervisor submitted the appraisal, the hours are complete, and the grade is not released yet
    public function summary(Request $request)
    {
        if (!\App\Services\SchemaCache::has('evaluations') || !\App\Services\SchemaCache::has('intern_grades')) return response()->json(['ready' => 0]);
        $released = DB::table('intern_grades')->whereNotNull('finalized_at')->pluck('intern_id')->map(fn ($v) => (int) $v)->all();
        $ready = 0;
        $evals = DB::table('evaluations')->where('mode', 'system')->get(['intern_id', 'ratings'])
            ->filter(fn ($e) => !in_array((int) $e->intern_id, $released, true) && Grading::supervisor($e->ratings)['total'] !== null);
        $ids = $evals->pluck('intern_id')->map(fn ($v) => (int) $v)->all();
        $users = $ids ? DB::table('users')->whereIn('id', $ids)->where('role', 'intern')->get(['id', 'program', 'prior_hours'])->keyBy('id') : collect();
        $hoursOf = OjtHours::renderedMany($ids);   // one query for all of them
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            if (!$u) continue;
            $required = self::requiredHours($u->program ?? null, $u->prior_hours ?? 0);
            if (($hoursOf[$id] ?? 0) + 0.01 >= $required && self::hasNarrative($id)) $ready++;
        }
        return response()->json(['ready' => $ready]);
    }

    // PUT /api/grades/{internId}   (OJT coordinator)  { ratings{}, remarks, finalize: bool }
    public function save(Request $request, $internId)
    {
        if (!\App\Services\SchemaCache::has('intern_grades')) return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        $auth = $this->auth($request);
        $data = $request->validate(['ratings' => 'nullable|array', 'remarks' => 'nullable|string|max:3000', 'finalize' => 'nullable|boolean']);
        $intern = DB::table('users')->where('id', $internId)->where('role', 'intern')->first();
        if (!$intern) return response()->json(['message' => 'Intern not found.'], 404);

        $old = DB::table('intern_grades')->where('intern_id', $intern->id)->first();
        if ($old && $old->finalized_at) return response()->json(['message' => 'This grade is already released. Reopen it first to make changes.'], 422);

        $ratings = Grading::clean('coordinator', $data['ratings'] ?? []);
        $coord = Grading::coordinator($ratings);
        $e = DB::table('evaluations')->where('intern_id', $intern->id)->first();
        $sup = $e && $e->mode === 'system' ? Grading::supervisor($e->ratings) : ['total' => null];
        $final = Grading::final($sup['total'], $coord['total']);
        $finalize = (bool) ($data['finalize'] ?? false);

        if ($finalize) {
            if ($sup['total'] === null) return response()->json(['message' => "The HTE supervisor hasn't finished the appraisal of {$intern->name} yet."], 422);
            if ($coord['total'] === null) return response()->json(['message' => 'Rate every item of your part before releasing the grade.'], 422);
            // the grade is released only after the intern has completed the required hours
            $required = self::requiredHours($intern->program ?? null, $intern->prior_hours ?? 0);
            $rendered = round(OjtHours::rendered((int) $intern->id), 2);
            if ($required > 0 && $rendered + 0.01 < $required) {
                return response()->json(['message' => "{$intern->name} has rendered {$rendered} of {$required} hours. Save a draft now and release the grade once the required hours are complete."], 422);
            }
            // the narrative report is graded here (25%), so it must be uploaded first (a returned one does not count)
            if (!self::hasNarrative((int) $intern->id)) {
                return response()->json(['message' => "{$intern->name} hasn't uploaded the narrative report yet. Save a draft now and release the grade once it is uploaded in Submit MOV (Post-Internship)."], 422);
            }
        }

        $now = Carbon::now('Asia/Manila');
        $row = [
            'ratings' => json_encode($ratings), 'coordinator_score' => $coord['total'], 'supervisor_score' => $sup['total'], 'final_grade' => $final,
            'remarks' => $data['remarks'] ?? null, 'graded_by' => $auth->id, 'graded_by_name' => $auth->name,
            'finalized_at' => $finalize ? $now : null, 'updated_at' => $now,
        ];
        if ($old) DB::table('intern_grades')->where('id', $old->id)->update($row);
        else DB::table('intern_grades')->insert($row + ['intern_id' => $intern->id, 'created_at' => $now]);

        Activity::record($auth, $finalize ? "Released the internship grade ({$final}%)" : 'Saved the internship grade (draft)', $intern->name, $request);
        if ($finalize) {
            try { $eq = Grading::equivalent($final); Notifier::toUser($intern->id, 'Internship Grade Released', "Your internship grade is ready: {$final}%" . ($eq ? " ({$eq['grade']}, {$eq['rating']})." : '.'), 'grade_released'); } catch (\Throwable $x) { /* best-effort */ }
        }
        return response()->json(['message' => $finalize ? "Grade released to {$intern->name}." : 'Saved. Release it when you are done.', 'final_grade' => $final]);
    }

    // POST /api/grades/{internId}/reopen   (OJT coordinator)
    public function reopen(Request $request, $internId)
    {
        $auth = $this->auth($request);
        $g = \App\Services\SchemaCache::has('intern_grades') ? DB::table('intern_grades')->where('intern_id', $internId)->first() : null;
        if (!$g) return response()->json(['message' => 'Not found.'], 404);
        DB::table('intern_grades')->where('id', $g->id)->update(['finalized_at' => null, 'updated_at' => Carbon::now('Asia/Manila')]);
        $name = DB::table('users')->where('id', $internId)->value('name');
        Activity::record($auth, 'Reopened the internship grade', $name ?? '', $request);
        return response()->json(['message' => 'Grade reopened. The intern no longer sees it until you release it again.']);
    }

    // GET /api/my-grade   (intern: the breakdown, only after the coordinator released it)
    public function mine(Request $request)
    {
        $auth = $this->auth($request);
        $g = \App\Services\SchemaCache::has('intern_grades') ? DB::table('intern_grades')->where('intern_id', $auth->id)->first() : null;
        $e = \App\Services\SchemaCache::has('evaluations') ? DB::table('evaluations')->where('intern_id', $auth->id)->first() : null;
        $appraisalDone = $e && $e->mode === 'system' && Grading::supervisor($e->ratings)['total'] !== null;
        if (!$g || !$g->finalized_at) return response()->json(['released' => false, 'appraisal_done' => $appraisalDone]);

        $sup = Grading::supervisor($e->ratings ?? null);
        $coord = Grading::coordinator($g->ratings);
        // the live appraisal may no longer give a total (edited / removed after release), so fall back to the score saved at release,
        // and last, to what the released final grade implies: (final - coordinator) / supervisor share
        $share = (config('ojt.grading.supervisor_share') ?? 60) / 100;
        $supScore = $sup['total'];
        if ($supScore === null && $g->supervisor_score !== null) $supScore = (float) $g->supervisor_score;
        if ($supScore === null && $coord['total'] !== null && $share > 0) $supScore = round(((float) $g->final_grade - $coord['total']) / $share, 2);
        $sup['total'] = $supScore;
        return response()->json([
            'released' => true, 'final_grade' => (float) $g->final_grade, 'equivalent' => Grading::equivalent((float) $g->final_grade), 'released_at' => $g->finalized_at,
            'supervisor' => ['score' => $sup['total'], 'categories' => $sup['categories'], 'by' => $e->evaluator_name ?? null,
                             'strengths' => ($e->share_comments ?? true) ? ($e->strengths ?? null) : null,
                             'improvements' => ($e->share_comments ?? true) ? ($e->improvements ?? null) : null],
            'coordinator' => ['score' => $coord['total'], 'categories' => $coord['categories'], 'by' => $g->graded_by_name],
            'remarks' => $g->remarks,
        ]);
    }
}