<?php
//AccountController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\Notifier;
use App\Services\Supervision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Accounts and security (Oct 24):
 *  - deactivate / reactivate an account (graduated interns): no sign-in, no taps, records stay
 *  - kiosks: each kiosk has its own key; an HTE kiosk only accepts the interns placed at that HTE
 *  - activity log (read)
 *  - ID card settings shared by every computer
 *  - the HTE supervisor sets an intern's DTR type (2 taps or 4 taps)
 */
class AccountController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    /* ───────────── Deactivate / reactivate ───────────── */

    // PATCH /api/admin/users/{id}/active   { active: true|false }
    public function setActive(Request $request, $id)
    {
        $data = $request->validate(['active' => 'required|boolean']);
        $auth = $this->auth($request);
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) return response()->json(['message' => 'User not found.'], 404);
        if ((int) $user->id === (int) $auth->id) {
            return response()->json(['message' => "You can't deactivate your own account."], 422);
        }
        if (!Schema::hasColumn('users', 'deactivated_at')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }

        $now = Carbon::now('Asia/Manila');
        if ($data['active']) {
            DB::table('users')->where('id', $id)->update(['deactivated_at' => null, 'updated_at' => $now]);
            return response()->json(['message' => "{$user->name} can sign in again."]);
        }

        DB::table('users')->where('id', $id)->update(['deactivated_at' => $now, 'updated_at' => $now]);
        DB::table('api_tokens')->where('user_id', $id)->delete();   // signed out everywhere right away
        return response()->json(['message' => "{$user->name} was deactivated. Their records are kept."]);
    }

    // PATCH /api/admin/users/active-batch   { ids: [..], active: true|false }   (End of term: a whole batch at once)
    public function setActiveBatch(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'integer', 'active' => 'required|boolean']);
        if (!Schema::hasColumn('users', 'deactivated_at')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $auth = $this->auth($request);
        // interns only: staff accounts are deactivated one by one in Manage Accounts
        $ids = DB::table('users')->whereIn('id', $data['ids'])->where('role', 'intern')
            ->where('id', '!=', $auth->id ?? 0)->pluck('id')->all();
        if (!$ids) return response()->json(['message' => 'No intern accounts were selected.'], 422);

        $now = Carbon::now('Asia/Manila');
        DB::table('users')->whereIn('id', $ids)->update(['deactivated_at' => $data['active'] ? null : $now, 'updated_at' => $now]);
        if (!$data['active'] && Schema::hasTable('api_tokens')) {
            DB::table('api_tokens')->whereIn('user_id', $ids)->delete();   // signed out everywhere right away
        }
        Activity::record($auth, $data['active'] ? 'Interns reactivated (batch)' : 'Interns deactivated (end of term)',
            count($ids) . ' intern account(s): ' . implode(', ', $ids), $request);

        $n = count($ids);
        return response()->json(['message' => $data['active']
            ? "{$n} intern account" . ($n > 1 ? 's' : '') . ' can sign in again.'
            : "{$n} intern account" . ($n > 1 ? 's were' : ' was') . ' deactivated. Their records are kept.']);
    }

    /* ───────────── Kiosks ───────────── */

    private static function newKey(): string
    {
        return strtoupper(Str::random(6) . '-' . Str::random(6) . '-' . Str::random(6));
    }

    private static function kioskRow($k): array
    {
        return [
            'id' => (int) $k->id, 'name' => $k->name, 'placement' => $k->placement,
            'active' => (bool) $k->active, 'last_seen_at' => $k->last_seen_at, 'created_at' => $k->created_at,
            'has_key' => !empty($k->key_enc ?? null),   // its setup link can be shown again
        ];
    }

    // GET /api/admin/kiosks  (+ the HTE names interns are placed at, for the form)
    public function kiosks()
    {
        $rows = Schema::hasTable('kiosks') ? DB::table('kiosks')->orderBy('name')->get()->map(fn ($k) => self::kioskRow($k)) : collect();
        $places = DB::table('users')->where('role', 'intern')->whereNotNull('placement')->where('placement', '!=', '')
            ->distinct()->orderBy('placement')->pluck('placement');
        return response()->json(['kiosks' => $rows->values(), 'placements' => $places->values()]);
    }

    // POST /api/admin/kiosks   { name, placement|null }  -> the key is shown ONCE
    public function addKiosk(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'placement' => 'nullable|string|max:191',
        ]);
        $key = self::newKey();
        $now = Carbon::now('Asia/Manila');
        $id  = DB::table('kiosks')->insertGetId([
            'name' => trim($data['name']), 'placement' => ($p = trim((string) ($data['placement'] ?? ''))) === '' ? null : $p,
            'key_hash' => hash('sha256', $key), 'active' => true,
        ] + self::encKey($key) + [
            'created_by' => $this->auth($request)->id ?? null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return response()->json(['message' => 'Kiosk added. Open its setup link on the kiosk device.', 'id' => $id, 'key' => $key]);
    }

    // PATCH /api/admin/kiosks/{id}   { active?, name?, placement?, new_key? }
    public function updateKiosk(Request $request, $id)
    {
        $data = $request->validate([
            'active'    => 'nullable|boolean',
            'name'      => 'nullable|string|max:120',
            'placement' => 'nullable|string|max:191',
            'new_key'   => 'nullable|boolean',
        ]);
        $k = DB::table('kiosks')->where('id', $id)->first();
        if (!$k) return response()->json(['message' => 'Kiosk not found.'], 404);

        $upd = ['updated_at' => Carbon::now('Asia/Manila')];
        if (array_key_exists('active', $data) && $data['active'] !== null) $upd['active'] = (bool) $data['active'];
        if (!empty($data['name'])) $upd['name'] = trim($data['name']);
        if ($request->has('placement')) $upd['placement'] = ($p = trim((string) $data['placement'])) === '' ? null : $p;
        $key = null;
        if (!empty($data['new_key'])) { $key = self::newKey(); $upd['key_hash'] = hash('sha256', $key); $upd += self::encKey($key); }
        DB::table('kiosks')->where('id', $id)->update($upd);

        return response()->json($key
            ? ['message' => 'New key made. The old key stops working now; set the new one on the kiosk.', 'key' => $key]
            : ['message' => 'Kiosk updated.']);
    }

    // the key, encrypted with APP_KEY, so the admin can see the setup link again (only if the column exists)
    private static function encKey(string $key): array
    {
        return Schema::hasColumn('kiosks', 'key_enc') ? ['key_enc' => \Illuminate\Support\Facades\Crypt::encryptString($key)] : [];
    }

    // GET /api/admin/kiosks/{id}/key  -> the kiosk's current key (to show its setup link again)
    public function kioskKey($id)
    {
        $k = DB::table('kiosks')->where('id', $id)->first();
        if (!$k) return response()->json(['message' => 'Kiosk not found.'], 404);
        if (empty($k->key_enc)) {
            return response()->json(['message' => 'This kiosk was added before keys could be shown again. Click "New key" once; after that you can always see its link.'], 409);
        }
        try {
            $key = \Illuminate\Support\Facades\Crypt::decryptString($k->key_enc);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The saved key could not be read (the server key changed). Click "New key".'], 409);
        }
        return response()->json(['key' => $key]);
    }

    // DELETE /api/admin/kiosks/{id}
    public function removeKiosk($id)
    {
        DB::table('kiosks')->where('id', $id)->delete();
        return response()->json(['message' => 'Kiosk removed. Its key no longer works.']);
    }

    /**
     * Used by the tap: which kiosk sent this key?
     *   ['ok' => true, 'kiosk' => row|null]   null = the main CCIS kiosk key from .env (accepts everyone)
     *   ['ok' => false]                        unknown or turned-off key
     */
    public static function kioskFor(string $key): array
    {
        if ($key === '') return ['ok' => false];
        $main = (string) config('ojt.kiosk_key');
        if ($main !== '' && hash_equals($main, $key)) return ['ok' => true, 'kiosk' => null];
        if (!\App\Services\SchemaCache::has('kiosks')) return ['ok' => false];
        $k = DB::table('kiosks')->where('key_hash', hash('sha256', $key))->first();
        if (!$k || !$k->active) return ['ok' => false];
        DB::table('kiosks')->where('id', $k->id)->update(['last_seen_at' => Carbon::now('Asia/Manila')]);
        return ['ok' => true, 'kiosk' => $k];
    }

    // GET /api/kiosk/info   (header X-Kiosk-Key) -> the kiosk's name and who can tap there, for the kiosk screen
    public function kioskInfo(Request $request)
    {
        $k = self::kioskFor((string) $request->header('X-Kiosk-Key'));
        if (!$k['ok']) return response()->json(['message' => 'This device is not an authorized attendance kiosk.'], 401);
        $kiosk = $k['kiosk'];
        return response()->json([
            'name'      => $kiosk->name ?? 'CCIS kiosk',
            'placement' => $kiosk->placement ?? null,                     // null = every intern
            'who'       => !empty($kiosk->placement) ? "Interns placed at {$kiosk->placement}" : 'Every intern',
        ]);
    }

    /** how a kiosk is written on an attendance record: its id, or "main" for the CCIS key in .env */
    public static function kioskTag($kiosk): string
    {
        return $kiosk ? (string) $kiosk->id : 'main';
    }

    /**
     * The time ins and time outs one kiosk recorded on a day, newest first:
     * [{ key, action: CHECK_IN|CHECK_OUT, name, photo, student_id, uid, time, at, offline }]
     * Taps from before the kiosk was saved on each record: an HTE kiosk shows its own interns', the CCIS kiosk the rest.
     */
    public static function kioskTaps($kiosk, string $date): array
    {
        $tag    = self::kioskTag($kiosk);
        $hasCol = Schema::hasColumn('attendance_records', 'kiosk_in');
        $rows = DB::table('attendance_records as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.date', $date)
            ->select('a.id', 'a.uid', 'a.checked_in_at', 'a.checked_out_at', 'u.name', 'u.photo', 'u.student_id', 'u.placement',
                ...($hasCol ? ['a.kiosk_in', 'a.kiosk_out'] : []),
                ...(Schema::hasColumn('attendance_records', 'offline_in') ? ['a.offline_in', 'a.offline_out'] : []))
            ->get();
        $norm  = fn ($s) => preg_replace('/\s+/', ' ', strtolower(trim((string) $s)));
        // a record with no kiosk saved: which kiosk most likely took it
        $hteKiosks = Schema::hasTable('kiosks')
            ? DB::table('kiosks')->whereNotNull('placement')->pluck('placement')->map(fn ($p) => $norm($p))->all() : [];
        $guess = function ($r) use ($kiosk, $norm, $hteKiosks) {
            if ($kiosk && !empty($kiosk->placement)) return $norm($kiosk->placement) === $norm($r->placement ?? '');
            // the main CCIS kiosk: interns whose HTE has no kiosk of its own
            return !$kiosk && !in_array($norm($r->placement ?? ''), $hteKiosks, true);
        };
        $out = [];
        foreach ($rows as $r) {
            foreach ([['CHECK_IN', 'checked_in_at', 'kiosk_in', 'offline_in'], ['CHECK_OUT', 'checked_out_at', 'kiosk_out', 'offline_out']] as [$act, $col, $kcol, $ocol]) {
                if (empty($r->$col)) continue;
                $here = $hasCol && $r->$kcol !== null ? $r->$kcol === $tag : $guess($r);
                if (!$here) continue;
                $at = Carbon::parse($r->$col, 'Asia/Manila');
                $out[] = [
                    'key' => "r{$r->id}-" . ($act === 'CHECK_IN' ? 'in' : 'out'),
                    'token' => "r{$r->id}-" . ($act === 'CHECK_IN' ? 'in' : 'out'),
                    'action' => $act, 'name' => $r->name, 'photo' => $r->photo, 'student_id' => $r->student_id ?? null,
                    'uid' => $r->uid, 'time' => $at->format('h:i:s A'), 'at' => $at->toIso8601String(),
                    'offline' => !empty($r->$ocol ?? null),
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    // GET /api/kiosk/today   (header X-Kiosk-Key) -> this kiosk's time ins and time outs today (the kiosk keeps them after a reload)
    public function kioskToday(Request $request)
    {
        $k = self::kioskFor((string) $request->header('X-Kiosk-Key'));
        if (!$k['ok']) return response()->json(['message' => 'This device is not an authorized attendance kiosk.'], 401);
        $today = Carbon::now('Asia/Manila')->toDateString();
        return response()->json(['date' => $today, 'taps' => array_slice(self::kioskTaps($k['kiosk'], $today), 0, 300)]);
    }

    // GET /api/admin/kiosks/{id}/taps?date=YYYY-MM-DD   ("main" = the CCIS kiosk) -> one kiosk's tap history for a day
    public function kioskHistory(Request $request, $id)
    {
        $kiosk = null;
        if ($id !== 'main') {
            $kiosk = DB::table('kiosks')->where('id', (int) $id)->first();
            if (!$kiosk) return response()->json(['message' => 'Kiosk not found.'], 404);
        }
        $date = (string) $request->query('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = Carbon::now('Asia/Manila')->toDateString();
        $taps = self::kioskTaps($kiosk, $date);
        return response()->json([
            'date' => $date, 'taps' => $taps,
            'ins' => count(array_filter($taps, fn ($t) => $t['action'] === 'CHECK_IN')),
            'outs' => count(array_filter($taps, fn ($t) => $t['action'] === 'CHECK_OUT')),
        ]);
    }

    /** May this intern tap at this kiosk? (CCIS kiosks: everyone; HTE kiosks: only interns placed there) */
    public static function kioskAllows($kiosk, $intern): bool
    {
        if (!$kiosk || empty($kiosk->placement)) return true;
        $norm = fn ($s) => preg_replace('/\s+/', ' ', strtolower(trim((string) $s)));
        return $norm($kiosk->placement) === $norm($intern->placement ?? '');
    }

    /* ───────────── Activity log ───────────── */

    // GET /api/admin/activity?q=&role=&page=
    public function activity(Request $request)
    {
        if (!Schema::hasTable('activity_logs')) return response()->json(['rows' => [], 'total' => 0, 'per_page' => 50]);
        $per = 50;
        $q   = trim((string) $request->query('q', ''));
        $role = (string) $request->query('role', '');
        $query = DB::table('activity_logs')
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $like = '%' . strtolower($q) . '%';
                $x->whereRaw('lower(coalesce(user_name, \'\')) like ?', [$like])
                  ->orWhereRaw('lower(action) like ?', [$like])
                  ->orWhereRaw('lower(coalesce(details, \'\')) like ?', [$like]);
            }))
            ->when($role !== '', fn ($w) => $w->where('role', $role));
        $total = (clone $query)->count();
        $page  = max(1, (int) $request->query('page', 1));
        $rows  = $query->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get();
        return response()->json(['rows' => $rows, 'total' => $total, 'per_page' => $per, 'page' => $page]);
    }

    /* ───────────── DTR type (the HTE decides) ───────────── */

    // PATCH /api/supervisor/interns/{id}/dtr-mode   { dtr_mode: two|four }
    // The intern's HTE supervisor sets it (the admin and the coordinator can too).
    public function setDtrMode(Request $request, $id)
    {
        $data = $request->validate(['dtr_mode' => 'required|in:two,four']);
        $auth = $this->auth($request);
        $intern = DB::table('users')->where('id', $id)->where('role', 'intern')->first();
        if (!$intern) return response()->json(['message' => 'Intern not found.'], 404);
        if ($auth->role === 'supervisor' && !Supervision::supervises($auth, $id)) {
            return response()->json(['message' => 'You can only change the DTR type of your own interns.'], 403);
        }
        DB::table('users')->where('id', $id)->update(['dtr_mode' => $data['dtr_mode'], 'updated_at' => Carbon::now('Asia/Manila')]);
        $label = $data['dtr_mode'] === 'four' ? '4 taps (AM in/out, PM in/out)' : '2 taps (in and out)';
        try {
            Notifier::toUser((int) $id, 'DTR Type Changed', "Your DTR type is now {$label}. Tap at the kiosk the same way from now on.", 'settings');
        } catch (\Throwable $e) { /* the change itself is saved */ }
        return response()->json(['message' => "DTR type set to {$label}."]);
    }
}