<?php
//EvaluationController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\Grading;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The HTE supervisor's evaluation of an intern, two ways:
 *   system = the supervisor rates each item (1-5) and writes comments in MYTRACK
 *   paper  = the signed paper form is scanned / photographed and uploaded
 *            (by the supervisor, or by the coordinator when the supervisor hands in paper)
 * One evaluation per intern; submitting again replaces it. The criteria are in config/ojt.php.
 * When the HTE supervisor fills in the paper form instead, the intern uploads the signed copy as the
 * "Student Trainee Performance Appraisal" requirement and the OJT coordinator encodes its ratings here
 * (evaluator_role = ojt_coordinator), so it counts toward the grade like an online appraisal.
 */
class EvaluationController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    /** may this user see / evaluate this intern? supervisors: only their own interns */
    private function allowed($auth, $intern): bool
    {
        if (!$auth || !$intern || $intern->role !== 'intern') return false;
        if (in_array($auth->role, ['admin', 'ojt_coordinator'], true)) return true;
        return $auth->role === 'supervisor' && (int) $intern->supervisor_id === (int) $auth->id;
    }

    private static function row($e): array
    {
        return [
            'id' => (int) $e->id, 'intern_id' => (int) $e->intern_id, 'mode' => $e->mode,
            'ratings' => $e->ratings ? json_decode($e->ratings, true) : null, 'average' => $e->average !== null ? (float) $e->average : null,
            'strengths' => $e->strengths, 'improvements' => $e->improvements, 'comments' => $e->comments,
            'recommendation' => $e->recommendation, 'file_path' => $e->file_path, 'original_name' => $e->original_name,
            'evaluator_name' => $e->evaluator_name, 'evaluator_role' => $e->evaluator_role,
            'created_at' => $e->created_at, 'updated_at' => $e->updated_at,
        ];
    }

    // GET /api/evaluations/criteria
    public function criteria()
    {
        return response()->json([
            'criteria' => config('ojt.evaluation_criteria', []),
            'scale'    => config('ojt.evaluation_scale', []),
            'grading'  => config('ojt.grading', []),      // the CCIS supervisor appraisal used for the grade
        ]);
    }

    // GET /api/evaluations   (supervisor: own interns · coordinator / admin: everyone)
    public function index(Request $request)
    {
        if (!\App\Services\SchemaCache::has('evaluations')) return response()->json(['ready' => false, 'evaluations' => []]);
        $auth = $this->auth($request);
        // the appraisals are part of the grade: not for the admin (only the HTE supervisor and the OJT coordinator)
        if (($auth->role ?? '') === 'admin') {
            return response()->json(['message' => 'Appraisals are handled by the OJT coordinator and the HTE supervisors.'], 403);
        }
        $q = DB::table('evaluations as e')->join('users as u', 'u.id', '=', 'e.intern_id')->select('e.*', 'u.name as intern_name', 'u.supervisor_id');
        if ($auth->role === 'supervisor') $q->where('u.supervisor_id', $auth->id);
        else $q->where('e.mode', '!=', 'draft');   // a supervisor's draft is not sent to anyone yet
        return response()->json(['ready' => true, 'evaluations' => $q->orderByDesc('e.updated_at')->get()->map(fn ($e) => self::row($e) + ['intern_name' => $e->intern_name])]);
    }

    // POST /api/evaluations   (multipart for paper)  { intern_id, mode, ratings{}, strengths, improvements, comments, recommendation | file }
    public function store(Request $request)
    {
        if (!\App\Services\SchemaCache::has('evaluations')) return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        $auth = $this->auth($request);
        $data = $request->validate([
            'intern_id'      => 'required|integer',
            'mode'           => 'required|in:system,paper',
            'ratings'        => 'required_if:mode,system|nullable',
            'strengths'      => 'nullable|string|max:3000',
            'improvements'   => 'nullable|string|max:3000',
            'comments'       => 'nullable|string|max:3000',
            'recommendation' => 'nullable|in:hire,recommend,none',
            'share_comments' => 'nullable|boolean',
            'draft'          => 'nullable|boolean',   // save without submitting (the supervisor can finish it later)
            'file'           => 'required_if:mode,paper|nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $intern = DB::table('users')->where('id', $data['intern_id'])->first();
        if (!$this->allowed($auth, $intern)) return response()->json(['message' => 'You can only evaluate your own interns.'], 403);
        if (\App\Services\SchemaCache::has('intern_grades') && DB::table('intern_grades')->where('intern_id', $intern->id)->whereNotNull('finalized_at')->exists()) {
            return response()->json(['message' => 'The OJT coordinator already released this intern\'s grade, so the appraisal can no longer be changed.'], 422);
        }

        $old = DB::table('evaluations')->where('intern_id', $intern->id)->first();
        $draft = (bool) ($data['draft'] ?? false) && $data['mode'] === 'system';

        // the OJT coordinator encodes the HTE supervisor's signed paper appraisal (uploaded by the intern)
        $encoding = $auth->role !== 'supervisor';
        if ($encoding) {
            if ($auth->role !== 'ojt_coordinator') return response()->json(['message' => 'Appraisals are handled by the OJT coordinator and the HTE supervisors.'], 403);
            $draft = false;   // the coordinator copies a finished form: no drafts
            if ($old && $old->mode !== 'draft' && $old->evaluator_role === 'supervisor') {
                return response()->json(['message' => "The HTE supervisor already submitted {$intern->name}'s appraisal in the system, so there is nothing to encode."], 422);
            }
            $paper = \App\Services\SchemaCache::has('movs') && DB::table('movs')->where('user_id', $intern->id)
                ->where('requirement', \App\Services\AppraisalRequirement::KEY)->whereIn('status', ['pending', 'approved'])
                ->where('file_path', '!=', '')->exists();
            if (!$paper) {
                return response()->json(['message' => "{$intern->name} hasn't uploaded the signed paper appraisal yet (Student Trainee Performance Appraisal in Submit MOV). Encode it once it is uploaded."], 422);
            }
        }
        if ($draft && $old && $old->mode !== 'draft') {
            return response()->json(['message' => 'This appraisal was already submitted. Use Save changes instead.'], 422);
        }

        $now = Carbon::now('Asia/Manila');
        $row = [
            'intern_id' => $intern->id, 'evaluator_id' => $auth->id, 'evaluator_name' => $auth->name, 'evaluator_role' => $auth->role,
            'mode' => $data['mode'], 'strengths' => $data['strengths'] ?? null, 'improvements' => $data['improvements'] ?? null,
            'comments' => $data['comments'] ?? null, 'recommendation' => $data['recommendation'] ?? null, 'updated_at' => $now,
            'ratings' => null, 'average' => null, 'file_path' => null, 'original_name' => null,
        ];
        // may the intern read the strengths / weaknesses? (needs the share_comments column from the migration)
        if (\App\Services\SchemaCache::has('evaluations', 'share_comments')) $row['share_comments'] = (bool) ($data['share_comments'] ?? true);

        if ($data['mode'] === 'system') {
            // the CCIS supervisor appraisal (config ojt.grading.supervisor): every item 1-5
            $clean = Grading::clean('supervisor', $data['ratings']);
            if (!$draft && !Grading::complete('supervisor', $clean)) return response()->json(['message' => 'Please rate every item from 1 to 5.'], 422);
            if ($draft) $row['mode'] = 'draft';   // kept only for the supervisor: it does not count until it is submitted
            $row['ratings'] = json_encode($clean);
            $row['average'] = count($clean) ? round(array_sum($clean) / count($clean), 2) : null;
        } else {
            $f = $request->file('file');
            $row['file_path'] = \App\Services\PrivateFiles::store($f, 'evaluations');   // private: short-lived links only
            $row['original_name'] = mb_substr($f->getClientOriginalName(), 0, 191);
        }

        if ($old) {
            if ($old->file_path && $old->file_path !== $row['file_path']) {
                \App\Services\PrivateFiles::delete($old->file_path);
            }
            DB::table('evaluations')->where('id', $old->id)->update($row);
        } else {
            DB::table('evaluations')->insert($row + ['created_at' => $now]);
        }

        if ($draft) {
            Activity::record($auth, 'Saved the appraisal as a draft', $intern->name, $request);
            return response()->json(['message' => "Draft saved. {$intern->name}'s appraisal is not sent until you submit it."]);
        }
        $first = !$old || $old->mode === 'draft';   // submitting a draft is the first real submission
        Activity::record($auth, $encoding ? ('Encoded the paper appraisal' . ($first ? '' : ' (updated)'))
            : 'Evaluation ' . ($first ? 'submitted' : 'updated') . " ({$data['mode']})", $intern->name, $request);
        // the intern's "Student Trainee Performance Appraisal" requirement is done now (no upload needed from the intern)
        try {
            \App\Services\AppraisalRequirement::markDone((int) $intern->id, (int) $auth->id, $auth->name, $auth->role, $now);
        } catch (\Throwable $e) { /* the appraisal itself is saved; the checklist mark is best-effort */ }
        try {
            if ($encoding) {
                if ($first) Notifier::toUser($intern->id, 'Appraisal recorded', 'The OJT coordinator recorded your HTE supervisor\'s signed paper appraisal. It now counts toward your grade.', 'evaluation');
            } else {
                Notifier::toUser($intern->id, 'Evaluation submitted', 'Your HTE supervisor\'s evaluation of your internship was submitted.', 'evaluation');
            }
            if ($auth->role === 'supervisor') {
                Notifier::toRoles(['ojt_coordinator'], 'Evaluation submitted', "{$auth->name} submitted the evaluation of {$intern->name}.", 'evaluation');
            }
        } catch (\Throwable $e) { /* notifications are best-effort */ }

        if ($encoding) return response()->json(['message' => $first ? "Paper appraisal encoded. It now counts toward {$intern->name}'s grade." : 'Encoded ratings updated.']);
        return response()->json(['message' => $old ? 'Evaluation updated.' : 'Evaluation submitted.']);
    }

    // DELETE /api/evaluations/{id}
    public function destroy(Request $request, $id)
    {
        $auth = $this->auth($request);
        $e = DB::table('evaluations')->where('id', $id)->first();
        if (!$e) return response()->json(['message' => 'Not found.'], 404);
        $intern = DB::table('users')->where('id', $e->intern_id)->first();
        if (!$this->allowed($auth, $intern)) return response()->json(['message' => 'Not allowed.'], 403);
        \App\Services\PrivateFiles::delete($e->file_path);
        DB::table('evaluations')->where('id', $id)->delete();
        // the appraisal requirement it had marked done goes back to "Not submitted"
        try { \App\Services\AppraisalRequirement::clear((int) $e->intern_id); } catch (\Throwable $ex) { /* best-effort */ }
        Activity::record($auth, 'Evaluation removed', $intern->name ?? '', $request);
        return response()->json(['message' => 'Evaluation removed.']);
    }

    // GET /api/my-evaluation   (intern: only whether it was submitted, and when)
    public function mine(Request $request)
    {
        $auth = $this->auth($request);
        $e = \App\Services\SchemaCache::has('evaluations') ? DB::table('evaluations')->where('intern_id', $auth->id)->first() : null;
        if ($e && $e->mode === 'draft') $e = null;   // a draft is not submitted yet
        return response()->json($e ? ['submitted' => true, 'mode' => $e->mode, 'evaluator_name' => $e->evaluator_name, 'updated_at' => $e->updated_at]
                                   : ['submitted' => false]);
    }
}