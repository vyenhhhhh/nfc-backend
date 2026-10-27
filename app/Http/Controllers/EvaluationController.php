<?php
//EvaluationController.php
namespace App\Http\Controllers;

use App\Services\Activity;
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
 */
class EvaluationController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }
    private function disk() { return Storage::disk(config('filesystems.uploads', 'public')); }

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
        ]);
    }

    // GET /api/evaluations   (supervisor: own interns · coordinator / admin: everyone)
    public function index(Request $request)
    {
        if (!Schema::hasTable('evaluations')) return response()->json(['ready' => false, 'evaluations' => []]);
        $auth = $this->auth($request);
        $q = DB::table('evaluations as e')->join('users as u', 'u.id', '=', 'e.intern_id')->select('e.*', 'u.name as intern_name', 'u.supervisor_id');
        if ($auth->role === 'supervisor') $q->where('u.supervisor_id', $auth->id);
        return response()->json(['ready' => true, 'evaluations' => $q->orderByDesc('e.updated_at')->get()->map(fn ($e) => self::row($e) + ['intern_name' => $e->intern_name])]);
    }

    // POST /api/evaluations   (multipart for paper)  { intern_id, mode, ratings{}, strengths, improvements, comments, recommendation | file }
    public function store(Request $request)
    {
        if (!Schema::hasTable('evaluations')) return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        $auth = $this->auth($request);
        $data = $request->validate([
            'intern_id'      => 'required|integer',
            'mode'           => 'required|in:system,paper',
            'ratings'        => 'required_if:mode,system|nullable',
            'strengths'      => 'nullable|string|max:3000',
            'improvements'   => 'nullable|string|max:3000',
            'comments'       => 'nullable|string|max:3000',
            'recommendation' => 'nullable|in:hire,recommend,none',
            'file'           => 'required_if:mode,paper|nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $intern = DB::table('users')->where('id', $data['intern_id'])->first();
        if (!$this->allowed($auth, $intern)) return response()->json(['message' => 'You can only evaluate your own interns.'], 403);

        $now = Carbon::now('Asia/Manila');
        $row = [
            'intern_id' => $intern->id, 'evaluator_id' => $auth->id, 'evaluator_name' => $auth->name, 'evaluator_role' => $auth->role,
            'mode' => $data['mode'], 'strengths' => $data['strengths'] ?? null, 'improvements' => $data['improvements'] ?? null,
            'comments' => $data['comments'] ?? null, 'recommendation' => $data['recommendation'] ?? null, 'updated_at' => $now,
            'ratings' => null, 'average' => null, 'file_path' => null, 'original_name' => null,
        ];

        if ($data['mode'] === 'system') {
            $ratings = is_string($data['ratings']) ? json_decode($data['ratings'], true) : $data['ratings'];
            $keys = array_column(config('ojt.evaluation_criteria', []), 'key');
            $clean = [];
            foreach ($keys as $k) {
                $v = (int) ($ratings[$k] ?? 0);
                if ($v < 1 || $v > 5) return response()->json(['message' => 'Please rate every item from 1 to 5.'], 422);
                $clean[$k] = $v;
            }
            $row['ratings'] = json_encode($clean);
            $row['average'] = round(array_sum($clean) / max(count($clean), 1), 2);
        } else {
            $f = $request->file('file');
            $row['file_path'] = $f->store('evaluations', config('filesystems.uploads', 'public'));
            $row['original_name'] = mb_substr($f->getClientOriginalName(), 0, 191);
        }

        $old = DB::table('evaluations')->where('intern_id', $intern->id)->first();
        if ($old) {
            if ($old->file_path && $old->file_path !== $row['file_path']) {
                try { $this->disk()->delete($old->file_path); } catch (\Throwable $e) { /* keep going */ }
            }
            DB::table('evaluations')->where('id', $old->id)->update($row);
        } else {
            DB::table('evaluations')->insert($row + ['created_at' => $now]);
        }

        Activity::record($auth, 'Evaluation ' . ($old ? 'updated' : 'submitted') . " ({$data['mode']})", $intern->name, $request);
        try {
            Notifier::toUser($intern->id, 'Evaluation submitted', 'Your HTE supervisor\'s evaluation of your internship was submitted.', 'evaluation');
            if ($auth->role === 'supervisor') {
                Notifier::toRoles(['ojt_coordinator'], 'Evaluation submitted', "{$auth->name} submitted the evaluation of {$intern->name}.", 'evaluation');
            }
        } catch (\Throwable $e) { /* notifications are best-effort */ }

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
        if ($e->file_path) { try { $this->disk()->delete($e->file_path); } catch (\Throwable $x) { /* ignore */ } }
        DB::table('evaluations')->where('id', $id)->delete();
        Activity::record($auth, 'Evaluation removed', $intern->name ?? '', $request);
        return response()->json(['message' => 'Evaluation removed.']);
    }

    // GET /api/my-evaluation   (intern: only whether it was submitted, and when)
    public function mine(Request $request)
    {
        $auth = $this->auth($request);
        $e = Schema::hasTable('evaluations') ? DB::table('evaluations')->where('intern_id', $auth->id)->first() : null;
        return response()->json($e ? ['submitted' => true, 'mode' => $e->mode, 'evaluator_name' => $e->evaluator_name, 'updated_at' => $e->updated_at]
                                   : ['submitted' => false]);
    }
}