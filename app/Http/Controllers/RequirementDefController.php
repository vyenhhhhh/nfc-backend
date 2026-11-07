<?php

namespace App\Http\Controllers;

use App\Services\Requirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The list of internship requirements.
 *   GET    /requirements                     everyone signed in: the active list, by phase
 *   GET    /coordinator/requirements         the coordinator: every requirement, removed ones too
 *   POST   /coordinator/requirements         add one (phase, label, hint, repeat)
 *   PUT    /coordinator/requirements/{id}    rename / change the description / move to another phase / put back
 *   DELETE /coordinator/requirements/{id}    remove: hidden from interns, the files already sent are kept
 *   POST   /coordinator/requirements/order   new order of one phase (ids)
 */
class RequirementDefController extends Controller
{
    private const PHASES = ['pre', 'during', 'post'];

    private function notReady()
    {
        return response()->json(['message' => 'Requirements are not set up yet. Run php artisan migrate on the server.'], 500);
    }

    // only the OJT coordinator changes the list (it is an academic decision; the admin sees a read-only overview)
    private function notCoordinator(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        return ($auth->role ?? '') === 'ojt_coordinator' ? null
            : response()->json(['message' => 'Only the OJT coordinator can change the list of requirements.'], 403);
    }

    private function shape($rows): array
    {
        $out = ['pre' => [], 'during' => [], 'post' => []];
        foreach ($rows as $r) {
            if (!isset($out[$r->phase])) continue;
            $out[$r->phase][] = [
                'id' => $r->id, 'key' => $r->key, 'label' => $r->label, 'hint' => $r->hint,
                'repeat' => (bool) $r->repeat, 'active' => (bool) $r->active,
            ];
        }
        return $out;
    }

    public function index()
    {
        if (!Requirements::ready()) return $this->notReady();
        return response()->json(['requirements' => $this->shape(Requirements::active())]);
    }

    
    public function all()
    {
        if (!Requirements::ready()) return $this->notReady();
        $rows = DB::table('requirement_defs')->orderBy('sort')->orderBy('id')->get();
        // how many files interns sent for each one (so the coordinator knows before removing it)
        $sent = DB::table('movs')->whereNotNull('requirement')->groupBy('requirement')->selectRaw('requirement, count(*) as n')->pluck('n', 'requirement');
        $out = $this->shape($rows);
        foreach ($out as $ph => $list) {
            foreach ($list as $i => $r) $out[$ph][$i]['sent'] = (int) ($sent[$r['key']] ?? 0);
        }
        return response()->json(['requirements' => $out]);
    }

    public function store(Request $request)
    {
        if ($no = $this->notCoordinator($request)) return $no;
        if (!Requirements::ready()) return $this->notReady();
        $data = $request->validate([
            'phase'  => 'required|in:pre,during,post',
            'label'  => 'required|string|max:150',
            'hint'   => 'nullable|string|max:300',
            'repeat' => 'boolean',
        ]);
        $label = trim($data['label']);
        if (DB::table('requirement_defs')->whereRaw('lower(label) = ?', [mb_strtolower($label)])->where('active', true)->exists()) {
            return response()->json(['message' => "\"$label\" is already in the list."], 422);
        }
        // a short, permanent key made from the name (stored on every file the interns send)
        $base = Str::limit(Str::slug($label, '_'), 50, '') ?: 'requirement';
        $key = $base;
        for ($n = 2; DB::table('requirement_defs')->where('key', $key)->exists(); $n++) $key = "{$base}_{$n}";

        $sort = (int) DB::table('requirement_defs')->where('phase', $data['phase'])->max('sort') + 1;
        $id = DB::table('requirement_defs')->insertGetId([
            'key' => $key, 'phase' => $data['phase'], 'label' => $label, 'hint' => trim((string) ($data['hint'] ?? '')) ?: null,
            'repeat' => (bool) ($data['repeat'] ?? false), 'active' => true, 'sort' => $sort,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => "\"$label\" added.", 'id' => $id, 'key' => $key], 201);
    }

    public function update(Request $request, $id)
    {
        if ($no = $this->notCoordinator($request)) return $no;
        if (!Requirements::ready()) return $this->notReady();
        $row = DB::table('requirement_defs')->where('id', $id)->first();
        if (!$row) return response()->json(['message' => 'Requirement not found.'], 404);
        $data = $request->validate([
            'phase'  => 'sometimes|in:pre,during,post',
            'label'  => 'sometimes|required|string|max:150',
            'hint'   => 'sometimes|nullable|string|max:300',
            'repeat' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);
        $set = ['updated_at' => now()];
        if (array_key_exists('label', $data)) $set['label'] = trim($data['label']);
        if (array_key_exists('hint', $data)) $set['hint'] = trim((string) $data['hint']) ?: null;
        if (array_key_exists('repeat', $data)) $set['repeat'] = (bool) $data['repeat'];
        if (array_key_exists('active', $data)) $set['active'] = (bool) $data['active'];
        if (array_key_exists('phase', $data) && $data['phase'] !== $row->phase) {
            $set['phase'] = $data['phase'];
            $set['sort'] = (int) DB::table('requirement_defs')->where('phase', $data['phase'])->max('sort') + 1;   // goes to the end of its new phase
        }
        DB::table('requirement_defs')->where('id', $id)->update($set);
        $msg = array_key_exists('active', $data) && $data['active'] && !$row->active ? 'Requirement put back.' : 'Requirement saved.';
        return response()->json(['message' => $msg]);
    }

    public function destroy(Request $request, $id)
    {
        if ($no = $this->notCoordinator($request)) return $no;
        if (!Requirements::ready()) return $this->notReady();
        $row = DB::table('requirement_defs')->where('id', $id)->first();
        if (!$row) return response()->json(['message' => 'Requirement not found.'], 404);
        if ($row->phase === 'pre' && DB::table('requirement_defs')->where('phase', 'pre')->where('active', true)->count() <= 1) {
            return response()->json(['message' => 'Keep at least one pre-internship requirement.'], 422);
        }
        DB::table('requirement_defs')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
        return response()->json(['message' => "\"{$row->label}\" removed. Files already sent for it are kept."]);
    }

    public function order(Request $request)
    {
        if ($no = $this->notCoordinator($request)) return $no;
        if (!Requirements::ready()) return $this->notReady();
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        foreach (array_values($data['ids']) as $i => $rid) {
            DB::table('requirement_defs')->where('id', $rid)->update(['sort' => $i + 1, 'updated_at' => now()]);
        }
        return response()->json(['message' => 'Order saved.']);
    }
}
