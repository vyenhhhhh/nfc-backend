<?php
//ProjectController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\Notifier;
use App\Services\SchemaCache;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Project-based interns: the OJT is complete when the project is done.
 * Step 1: the intern's HTE supervisor marks the project as complete.
 * Step 2: the OJT coordinator confirms it. Only then do the post-internship requirements open.
 */
class ProjectController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    private function intern($id)
    {
        return DB::table('users')->where('id', $id)->where('role', 'intern')->first();
    }

    private function ready()
    {
        return SchemaCache::has('users', 'project_confirmed_at')
            ? null
            : response()->json(['message' => 'Run php artisan migrate first (project completion columns are missing).'], 503);
    }

    private function state($u): array
    {
        $name = fn ($id) => $id ? DB::table('users')->where('id', $id)->value('name') : null;
        return [
            'project_based'     => ($u->modality ?? null) === 'project',
            'marked_at'         => $u->project_marked_at ?? null,
            'marked_by_name'    => $name($u->project_marked_by ?? null),
            'confirmed_at'      => $u->project_confirmed_at ?? null,
            'confirmed_by_name' => $name($u->project_confirmed_by ?? null),
        ];
    }

    // GET /api/interns/{id}/project   (the intern, their supervisor, the coordinator, the admin)
    public function show(Request $request, $id)
    {
        if ($r = $this->ready()) return $r;
        $auth = $this->auth($request);
        $u = $this->intern($id);
        if (!$u) return response()->json(['message' => 'Intern not found.'], 404);
        $ok = in_array($auth->role, ['admin', 'ojt_coordinator'], true)
            || ($auth->role === 'intern' && (int) $auth->id === (int) $id)
            || ($auth->role === 'supervisor' && Supervision::supervises($auth, $id));
        if (!$ok) return response()->json(['message' => 'Not allowed.'], 403);
        return response()->json($this->state($u));
    }

    // POST /api/interns/{id}/project/mark   (the intern's HTE supervisor)
    public function mark(Request $request, $id)
    {
        if ($r = $this->ready()) return $r;
        $auth = $this->auth($request);
        $u = $this->intern($id);
        if (!$u) return response()->json(['message' => 'Intern not found.'], 404);
        if ($auth->role !== 'supervisor' || !Supervision::supervises($auth, $id)) {
            return response()->json(['message' => 'Only the intern\'s HTE supervisor can mark the project as complete.'], 403);
        }
        if (($u->modality ?? null) !== 'project') {
            return response()->json(['message' => 'This intern is not project-based. Their OJT is complete when the required hours are done.'], 422);
        }
        if ($u->project_marked_at) return response()->json(['message' => 'The project is already marked as complete.'] + $this->state($u));

        DB::table('users')->where('id', $id)->update([
            'project_marked_at' => Carbon::now('Asia/Manila'), 'project_marked_by' => $auth->id, 'updated_at' => Carbon::now('Asia/Manila'),
        ]);
        try {
            Notifier::toRoles(['ojt_coordinator'], 'Project Marked Complete',
                "{$auth->name} marked the project of {$u->name} as complete. Please confirm it.", 'approval');
            Notifier::toUser((int) $id, 'Project Marked Complete',
                'Your HTE supervisor marked your project as complete. It is now waiting for the OJT coordinator\'s confirmation.', 'approval');
        } catch (\Throwable $e) { /* the mark itself is saved */ }
        Activity::record($auth, 'Marked project complete', $u->name, $request);
        return response()->json(['message' => 'Project marked as complete. The OJT coordinator will confirm it.'] + $this->state($this->intern($id)));
    }

    // DELETE /api/interns/{id}/project/mark   (the supervisor takes it back, only before the coordinator confirms)
    public function unmark(Request $request, $id)
    {
        if ($r = $this->ready()) return $r;
        $auth = $this->auth($request);
        $u = $this->intern($id);
        if (!$u) return response()->json(['message' => 'Intern not found.'], 404);
        if ($auth->role !== 'supervisor' || !Supervision::supervises($auth, $id)) {
            return response()->json(['message' => 'Only the intern\'s HTE supervisor can undo this.'], 403);
        }
        if ($u->project_confirmed_at) {
            return response()->json(['message' => 'The coordinator already confirmed it. Ask the OJT coordinator to undo the confirmation first.'], 422);
        }
        DB::table('users')->where('id', $id)->update(['project_marked_at' => null, 'project_marked_by' => null, 'updated_at' => Carbon::now('Asia/Manila')]);
        Activity::record($auth, 'Undid project complete', $u->name, $request);
        return response()->json(['message' => 'The project is no longer marked as complete.'] + $this->state($this->intern($id)));
    }

    // POST /api/interns/{id}/project/confirm   (the OJT coordinator; the admin can too)
    public function confirm(Request $request, $id)
    {
        if ($r = $this->ready()) return $r;
        $auth = $this->auth($request);
        $u = $this->intern($id);
        if (!$u) return response()->json(['message' => 'Intern not found.'], 404);
        if (!in_array($auth->role, ['ojt_coordinator', 'admin'], true)) {
            return response()->json(['message' => 'Only the OJT coordinator can confirm a completed project.'], 403);
        }
        if (!$u->project_marked_at) {
            return response()->json(['message' => 'The HTE supervisor has not marked the project as complete yet.'], 422);
        }
        DB::table('users')->where('id', $id)->update([
            'project_confirmed_at' => Carbon::now('Asia/Manila'), 'project_confirmed_by' => $auth->id, 'updated_at' => Carbon::now('Asia/Manila'),
        ]);
        try {
            Notifier::toUser((int) $id, 'Project Confirmed',
                'The OJT coordinator confirmed that your project is complete. Your post-internship requirements are now open.', 'approval');
        } catch (\Throwable $e) { /* the confirmation itself is saved */ }
        Activity::record($auth, 'Confirmed project complete', $u->name, $request);
        return response()->json(['message' => 'Project confirmed. The intern\'s post-internship requirements are now open.'] + $this->state($this->intern($id)));
    }

    // DELETE /api/interns/{id}/project/confirm
    public function unconfirm(Request $request, $id)
    {
        if ($r = $this->ready()) return $r;
        $auth = $this->auth($request);
        $u = $this->intern($id);
        if (!$u) return response()->json(['message' => 'Intern not found.'], 404);
        if (!in_array($auth->role, ['ojt_coordinator', 'admin'], true)) {
            return response()->json(['message' => 'Only the OJT coordinator can undo the confirmation.'], 403);
        }
        DB::table('users')->where('id', $id)->update(['project_confirmed_at' => null, 'project_confirmed_by' => null, 'updated_at' => Carbon::now('Asia/Manila')]);
        Activity::record($auth, 'Undid project confirmation', $u->name, $request);
        return response()->json(['message' => 'The confirmation was undone.'] + $this->state($this->intern($id)));
    }
}
