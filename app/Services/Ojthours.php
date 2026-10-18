<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How OJT hours are credited (same rules as the React app, components/DashKit.jsx):
 *  - only time inside the official hours counts: 8:00–12:00 and 1:00–5:00 (config/ojt.php)
 *    → arriving at 7:30 counts from 8:00, lunch never counts, no grace period for late taps
 *  - time after 5:00 PM is overtime: it counts only after the coordinator approves it
 *  - official hours give at most the daily cap (8 hours) a day; approved overtime is added on top
 */
class OjtHours
{
    private static function at(Carbon $day, float $hour): int
    {
        return $day->copy()->startOfDay()->addMinutes((int) round($hour * 60))->getTimestamp();
    }

    private static function overlap(int $a, int $b, int $c, int $d): int
    {
        return max(0, min($b, $d) - max($a, $c));
    }

    /** [regular seconds, overtime seconds] for one check-in / check-out pair */
    public static function split($checkedIn, $checkedOut): array
    {
        if (!$checkedIn || !$checkedOut) return [0, 0];

        $in  = Carbon::parse($checkedIn, 'Asia/Manila');
        $out = Carbon::parse($checkedOut, 'Asia/Manila');
        $a = $in->getTimestamp();
        $b = $out->getTimestamp();
        if ($b <= $a) return [0, 0];

        $s = config('ojt.schedule');
        $regular = self::overlap($a, $b, self::at($in, $s['am_in']), self::at($in, $s['am_out']))
                 + self::overlap($a, $b, self::at($in, $s['pm_in']), self::at($in, $s['pm_out']));
        $overtime = self::overlap($a, $b, self::at($in, $s['pm_out']), $in->copy()->endOfDay()->getTimestamp());

        return [$regular, $overtime];
    }

    /** Credited hours of one day, from that day's attendance rows */
    public static function day(iterable $rows): float
    {
        $cap = (float) config('ojt.daily_cap_hours', 8) * 3600;
        $regular = 0;
        $overtime = 0;
        $seen = [];
        foreach ($rows as $r) {
            if (!$r->checked_in_at || isset($seen[$r->checked_in_at])) continue;
            $seen[$r->checked_in_at] = true;
            [$reg] = self::split($r->checked_in_at, $r->checked_out_at);
            $regular  += $reg;
            $overtime += ((int) ($r->ot_approved_minutes ?? 0)) * 60;
        }
        // the cap is for the official hours only; overtime the coordinator approved is added on top
        return (min($cap, $regular) + $overtime) / 3600;
    }

    /** Total credited hours of an intern */
    public static function rendered(int $userId): float
    {
        $rows = DB::table('attendance_records')
            ->where('user_id', $userId)
            ->get(['date', 'checked_in_at', 'checked_out_at', 'ot_approved_minutes']);

        $total = 0;
        foreach ($rows->groupBy(fn ($r) => (string) $r->date) as $dayRows) {
            $total += self::day($dayRows);
        }
        return round($total, 1);
    }
}