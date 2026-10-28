<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is a day open for an intern? Two calendars decide:
 *   1. the intern's HTE calendar: a "working" day overrides the university; a day off closes it
 *   2. the university calendar: "No OJT" closes it for everyone; "school event only" does not
 */
class CalendarDays
{
    public static function ready(): bool
    {
        static $ok = null;
        return $ok ??= Schema::hasColumn('calendar_events', 'hte_key');
    }

    /** "DICT  Caraga " -> "dict caraga" (same matching as the HTE kiosks) */
    public static function key($name): ?string
    {
        $k = preg_replace('/\s+/', ' ', strtolower(trim((string) $name)));
        return $k === '' ? null : $k;
    }

    public static function hteOf($userId): ?string
    {
        return $userId ? self::key(DB::table('users')->where('id', $userId)->value('placement')) : null;
    }

    /** Does this event close OJT (on its own)? */
    public static function closes($e): bool
    {
        if (!empty($e->hte_key)) return ($e->type ?? '') !== 'working';
        return ($e->scope ?? 'no_ojt') !== 'school_event';
    }

    /** The event that closes this date for this intern, or null when time-ins are open. */
    public static function eventFor(string $date, $internId)
    {
        if (!self::ready()) {
            return DB::table('calendar_events')->whereDate('date', $date)->first();
        }
        $hte = self::hteOf($internId);
        $events = DB::table('calendar_events')->whereDate('date', $date)
            ->where(function ($w) use ($hte) {
                $w->whereNull('hte_key');
                if ($hte) $w->orWhere('hte_key', $hte);
            })->get();

        $mine = $events->first(fn ($e) => !empty($e->hte_key));
        if ($mine) return $mine->type === 'working' ? null : $mine;
        return $events->first(fn ($e) => self::closes($e));
    }

    /**
     * Adds "off" (true = no OJT for this viewer) to each event. For an intern (or a chosen intern),
     * a university day off is not off when their HTE works that day.
     */
    public static function markOff($events, ?string $hte)
    {
        $working = [];
        foreach ($events as $e) {
            if (!empty($e->hte_key) && $e->type === 'working' && $hte && $e->hte_key === $hte) {
                $working[substr((string) $e->date, 0, 10)] = true;
            }
        }
        return $events->map(function ($e) use ($working, $hte) {
            $d = substr((string) $e->date, 0, 10);
            $e->off = self::closes($e) && !(empty($e->hte_key) && $hte && isset($working[$d]));
            return $e;
        });
    }
}