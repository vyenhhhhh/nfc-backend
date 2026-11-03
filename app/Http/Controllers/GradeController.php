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
    private static function attendance(int $userId): array
    {
        $lateAfter = config('ojt.grading.late_after', '08:00');
        $rows = DB::table('attendance_records')->where('user_id', $userId)->whereNotNull('checked_in_at')->get(['date', 'checked_in_at']);
        $days = []; $late = 0;
        foreach ($rows as $r) {
            $d = (string) $r->date;
            if (isset($days[$d])) continue;           // the first time in of the day counts
            $days[$d] = true;
            if (Carbon::parse($r->checked_in_at)->timezone('Asia/Manila')->format('H:i') > $lateAfter) $late++;
        }
        return ['days' => count($days), 'late' => $late];
    }

    private static function requiredHours(?string $program): float
    {
        $p = strtoupper((string) $program); $key = null;
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) $key = 'BSIT';
        elseif (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) $key = 'BSIS';
        elseif (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) $key = 'BSCS';
        return (float) (config('ojt.program_hours')[$key] ?? config('ojt.default_hours', 486));
    }

    // GET /api/grades   (coordinator / admin: every intern · supervisor: their own interns)
    public function index(Request $request)
    {
        $auth = $this->auth($request);
        $ready = Schema::hasTable('intern_grades') && Schema::hasTable('evaluations');
        $q = DB::table('users')->where('role', 'intern');
        if ($auth->role === 'supervisor') $q->where('supervisor_id', $auth->id);
        $interns = $q->orderBy('name')->get();
        $ids = $interns->pluck('id')->all();

        $evals = Schema::hasTable('evaluations') ? DB::table('evaluations')->whereIn('intern_id', $ids)->get()->keyBy('intern_id') : collect();
        $grades = Schema::hasTable('intern_grades') ? DB::table('intern_grades')->whereIn('intern_id', $ids)->get()->keyBy('intern_id') : collect();
        $sups = DB::table('users')->whereIn('id', $interns->pluck('supervisor_id')->filter()->unique()->all())->pluck('name', 'id');

        $docs = [];
        if ($auth->role !== 'supervisor' && Schema::hasTable('movs')) {
            foreach (DB::table('movs')->whereIn('user_id', $ids)->whereIn('requirement', self::DOCS)->orderBy('created_at')->get() as $m) {
                $docs[$m->user_id][$m->requirement] = ['status' => $m->status, 'file_path' => $m->file_path, 'original_name' => $m->original_name ?? null, 'created_at' => $m->created_at];
            }
        }

        $rows = $interns->map(function ($u) use ($evals, $grades, $sups, $docs) {
            $e = $evals[$u->id] ?? null;
            $g = $grades[$u->id] ?? null;
            $sup = $e && $e->mode === 'system' ? Grading::supervisor($e->ratings) : ['total' => null, 'categories' => []];
            $coord = $g ? Grading::coordinator($g->ratings) : ['total' => null, 'categories' => []];
            return [
                'id' => (int) $u->id, 'name' => $u->name, 'student_id' => $u->student_id ?? null, 'program' => $u->program ?? null,
                'placement' => $u->placement ?? null, 'photo' => $u->photo ?? null, 'semester' => $u->semester ?? null, 'tracking_type' => $u->tracking_type ?? 'hours',
                'supervisor_id' => $u->supervisor_id ? (int) $u->supervisor_id : null, 'supervisor_name' => $u->supervisor_id ? ($sups[$u->supervisor_id] ?? null) : null,
                'hours' => round(OjtHours::rendered((int) $u->id), 2), 'required_hours' => self::requiredHours($u->program ?? null),
                'attendance' => self::attendance((int) $u->id),
                'appraisal' => $e && $e->mode === 'system' ? [
                    'id' => (int) $e->id, 'ratings' => json_decode($e->ratings ?: '{}', true), 'score' => $sup['total'], 'categories' => $sup['categories'],
                    'strengths' => $e->strengths, 'improvements' => $e->improvements, 'comments' => $e->comments, 'share_comments' => (bool) ($e->share_comments ?? true),
                    'evaluator_name' => $e->evaluator_name, 'updated_at' => $e->updated_at,
                ] : null,
                'grade' => $g ? [
                    'ratings' => json_decode($g->ratings ?: '{}', true), 'coordinator_score' => $coord['total'], 'categories' => $coord['categories'],
                    'final_grade' => $fg = ($g->finalized_at ? (float) $g->final_grade : Grading::final($sup['total'], $coord['total'])),
                    'equivalent' => Grading::equivalent($fg),
                    'remarks' => $g->remarks, 'graded_by_name' => $g->graded_by_name, 'finalized_at' => $g->finalized_at, 'updated_at' => $g->updated_at,
                ] : null,
                'docs' => $docs[$u->id] ?? (object) [],
            ];
        });

        return response()->json(['ready' => $ready, 'interns' => $rows]);
    }

    // PUT /api/grades/{internId}   (OJT coordinator)  { ratings{}, remarks, finalize: bool }
    public function save(Request $request, $internId)
    {
        if (!Schema::hasTable('intern_grades')) return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
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
        $g = Schema::hasTable('intern_grades') ? DB::table('intern_grades')->where('intern_id', $internId)->first() : null;
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
        $g = Schema::hasTable('intern_grades') ? DB::table('intern_grades')->where('intern_id', $auth->id)->first() : null;
        $e = Schema::hasTable('evaluations') ? DB::table('evaluations')->where('intern_id', $auth->id)->first() : null;
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