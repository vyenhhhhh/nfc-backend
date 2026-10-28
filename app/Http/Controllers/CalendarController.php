<?php

namespace App\Http\Controllers;

use App\Services\CalendarDays;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two calendars (see CalendarDays):
 *   University calendar  OJT coordinator / admin   POST|PUT|DELETE /calendar
 *   HTE calendar         the HTE's supervisors      POST|PUT|DELETE /supervisor/calendar
 *                        (one calendar per HTE, shared by every supervisor placed there)
 * GET /calendar returns what the signed-in user should see, each with "off" = no OJT that day.
 *   ?for=<intern id>  (staff) the days off of one intern, e.g. for their DTR
 */
class CalendarController extends Controller
{
    private function auth(Request $request)
    {
        return $request->attributes->get('auth_user');
    }

    private function hasTimestamps(): bool
    {
        static $has = null;
        return $has ??= Schema::hasColumn('calendar_events', 'created_at');
    }

    private function fmt($date): string
    {
        return Carbon::parse($date)->format('D, M j, Y');
    }

    // GET /api/calendar
    public function index(Request $request)
    {
        $auth = $this->auth($request);
        if (!CalendarDays::ready()) {
            return response()->json(DB::table('calendar_events')->orderBy('date')->get()->map(function ($e) { $e->off = true; return $e; }));
        }

        $q = DB::table('calendar_events as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.created_by')
            ->select('c.*', 'u.name as created_by_name');

        $hte = null;
        $role = $auth->role ?? null;
        if ($role === 'intern' || $role === 'supervisor') {
            $hte = CalendarDays::hteOf($auth->id);
        } elseif ($request->filled('for')) {
            $hte = CalendarDays::hteOf((int) $request->query('for'));
        }
        if ($role === 'intern' || $role === 'supervisor' || $request->filled('for')) {
            $q->where(function ($w) use ($hte) {
                $w->whereNull('c.hte_key');
                if ($hte) $w->orWhere('c.hte_key', $hte);
            });
        }
        // admin / coordinator without ?for: every HTE's days too

        $events = CalendarDays::markOff($q->orderBy('c.date')->get(), $hte);
        return response()->json($events->values());
    }

    private function base(array $data, bool $creating, $auth): array
    {
        $row = [
            'date'        => Carbon::parse($data['date'])->toDateString(),
            'type'        => $data['type'],
            'title'       => trim($data['title']),
            'description' => $data['description'] ?? null,
        ];
        if ($this->hasTimestamps()) {
            $row['updated_at'] = now();
            if ($creating) $row['created_at'] = now();
        }
        if ($creating && CalendarDays::ready()) $row['created_by'] = $auth->id ?? null;
        return $row;
    }

    /* ───────── University calendar: OJT coordinator / admin ───────── */

    private function uniRules(): array
    {
        return [
            'date'        => 'required|date',
            'type'        => 'required|in:holiday,non_working',
            'scope'       => 'nullable|in:no_ojt,school_event',
            'title'       => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ];
    }

    // POST /api/calendar
    public function store(Request $request)
    {
        $data = $request->validate($this->uniRules());
        $auth = $this->auth($request);
        $date = Carbon::parse($data['date'])->toDateString();

        $dup = DB::table('calendar_events')->whereDate('date', $date);
        if (CalendarDays::ready()) $dup->whereNull('hte_key');
        if ($dup->exists()) {
            return response()->json(['message' => 'That day already has a university event. Click it on the calendar to edit it.'], 422);
        }

        $row = $this->base($data, true, $auth);
        if (CalendarDays::ready()) $row['scope'] = $data['scope'] ?? 'no_ojt';
        $id = DB::table('calendar_events')->insertGetId($row);
        return response()->json(['message' => 'Event added.', 'id' => $id], 201);
    }

    // PUT /api/calendar/{id}   (the coordinator can also fix an HTE's event)
    public function update(Request $request, $id)
    {
        $ev = DB::table('calendar_events')->where('id', $id)->first();
        if (!$ev) return response()->json(['message' => 'Event not found.'], 404);

        $hteEvent = CalendarDays::ready() && !empty($ev->hte_key);
        $rules = $this->uniRules();
        if ($hteEvent) $rules['type'] = 'required|in:holiday,non_working,working';
        $data = $request->validate($rules);

        $row = $this->base($data, false, $this->auth($request));
        if (CalendarDays::ready() && !$hteEvent && isset($data['scope'])) $row['scope'] = $data['scope'];
        DB::table('calendar_events')->where('id', $id)->update($row);
        return response()->json(['message' => 'Event updated.']);
    }

    // DELETE /api/calendar/{id}
    public function destroy(Request $request, $id)
    {
        $ev = DB::table('calendar_events')->where('id', $id)->first();
        if (!$ev) return response()->json(['message' => 'Event not found.'], 404);
        DB::table('calendar_events')->where('id', $id)->delete();

        // an HTE's event removed by the coordinator: tell that HTE's supervisors
        if (CalendarDays::ready() && !empty($ev->hte_key)) {
            $auth = $this->auth($request);
            foreach ($this->hteUsers($ev->hte_key, 'supervisor') as $sid) {
                Notifier::toUser($sid, 'HTE calendar changed',
                    ($auth->name ?? 'The OJT coordinator') . " removed \"{$ev->title}\" ({$this->fmt($ev->date)}) from your HTE calendar.", 'calendar');
            }
        }
        return response()->json(['message' => 'Event deleted.']);
    }

    /* ───────── HTE calendar: the HTE's supervisors ───────── */

    private function hteUsers(string $hteKey, string $role)
    {
        return DB::table('users')->where('role', $role)->whereNotNull('placement')->get(['id', 'placement'])
            ->filter(fn ($u) => CalendarDays::key($u->placement) === $hteKey)
            ->pluck('id');
    }

    /** [hte_key, hte_name] of the signed-in supervisor, or a response when they have no HTE yet */
    private function myHte(Request $request)
    {
        if (!CalendarDays::ready()) {
            return response()->json(['message' => 'Run "php artisan migrate" first to turn on the HTE calendar.'], 503);
        }
        $name = DB::table('users')->where('id', $this->auth($request)->id)->value('placement');
        $key  = CalendarDays::key($name);
        if (!$key) {
            return response()->json(['message' => 'Your HTE is not set on your account yet. Ask the admin to add it.'], 422);
        }
        return [$key, trim($name)];
    }

    private function hteRules(): array
    {
        return [
            'date'        => 'required|date',
            'type'        => 'required|in:holiday,non_working,working',
            'title'       => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ];
    }

    private function tell(string $hteKey, string $hteName, $auth, string $date, array $data, string $verb): void
    {
        $what = $data['type'] === 'working'
            ? 'a working day (interns time in even though the university has no OJT)'
            : ($data['type'] === 'holiday' ? 'a holiday' : 'a non-working day');
        Notifier::toRoles(['ojt_coordinator'], "HTE calendar {$verb}",
            "{$auth->name} ({$hteName}) marked {$this->fmt($date)} as {$what}: \"{$data['title']}\".", 'calendar');
        foreach ($this->hteUsers($hteKey, 'intern') as $iid) {
            Notifier::toUser($iid, $data['type'] === 'working' ? 'Work day at your HTE' : 'Day off at your HTE',
                Carbon::parse($date)->format('l, M j') . ": {$data['title']}. "
                . ($data['type'] === 'working' ? 'Your HTE is open: time in as usual.' : 'Time-ins are closed that day.'), 'calendar');
        }
    }

    // POST /api/supervisor/calendar
    public function storeHte(Request $request)
    {
        $hte = $this->myHte($request);
        if (!is_array($hte)) return $hte;
        [$key, $name] = $hte;
        $data = $request->validate($this->hteRules());
        $auth = $this->auth($request);
        $date = Carbon::parse($data['date'])->toDateString();

        if (DB::table('calendar_events')->whereDate('date', $date)->where('hte_key', $key)->exists()) {
            return response()->json(['message' => 'Your HTE already has an event that day. Click it on the calendar to edit it.'], 422);
        }
        if ($data['type'] === 'working') {
            $uni = DB::table('calendar_events')->whereDate('date', $date)->whereNull('hte_key')->first();
            if (!$uni || !CalendarDays::closes($uni)) {
                return response()->json(['message' => 'Interns already time in that day. "We work this day" is only for university days with no OJT.'], 422);
            }
        }

        $row = $this->base($data, true, $auth) + ['hte_key' => $key, 'hte_name' => $name, 'scope' => 'no_ojt'];
        $id  = DB::table('calendar_events')->insertGetId($row);
        $this->tell($key, $name, $auth, $date, $data, 'updated');
        return response()->json(['message' => 'Saved on your HTE calendar. Your interns and the OJT coordinator were notified.', 'id' => $id], 201);
    }

    // PUT /api/supervisor/calendar/{id}
    public function updateHte(Request $request, $id)
    {
        $hte = $this->myHte($request);
        if (!is_array($hte)) return $hte;
        [$key] = $hte;
        $data = $request->validate($this->hteRules());
        if (!DB::table('calendar_events')->where('id', $id)->where('hte_key', $key)->exists()) {
            return response()->json(['message' => 'You can only change your HTE\'s own days.'], 403);
        }
        DB::table('calendar_events')->where('id', $id)->update($this->base($data, false, $this->auth($request)));
        return response()->json(['message' => 'Updated.']);
    }

    // DELETE /api/supervisor/calendar/{id}
    public function destroyHte(Request $request, $id)
    {
        $hte = $this->myHte($request);
        if (!is_array($hte)) return $hte;
        [$key, $name] = $hte;
        $ev = DB::table('calendar_events')->where('id', $id)->where('hte_key', $key)->first();
        if (!$ev) return response()->json(['message' => 'You can only remove your HTE\'s own days.'], 403);
        DB::table('calendar_events')->where('id', $id)->delete();
        $auth = $this->auth($request);
        Notifier::toRoles(['ojt_coordinator'], 'HTE calendar updated',
            "{$auth->name} ({$name}) removed \"{$ev->title}\" ({$this->fmt($ev->date)}) from their HTE calendar.", 'calendar');
        return response()->json(['message' => 'Removed.']);
    }
}