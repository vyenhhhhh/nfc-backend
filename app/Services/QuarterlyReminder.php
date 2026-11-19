<?php
//QuarterlyReminder.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a quarter ends, the OJT coordinator gets one notification that the quarterly report is ready to review
 * (its statistics and HTE tables are already counted by the system).
 * Checked when the coordinator's notifications load, so no scheduler / cron job is needed.
 */
class QuarterlyReminder
{
    public static function check(int $userId): void
    {
        try {
            if (!\App\Services\SchemaCache::has('quarterly_reports') || !\App\Services\SchemaCache::has('notifications')) return;
            $now = Carbon::now('Asia/Manila');
            // only during the first month of a new quarter (Jan, Apr, Jul, Oct)
            if (($now->month - 1) % 3 !== 0) return;

            $q  = intdiv($now->month - 1, 3) + 1;
            $pq = $q === 1 ? 4 : $q - 1;
            $py = $q === 1 ? $now->year - 1 : $now->year;
            $tag = "Q{$pq} {$py}";

            // already reminded about this quarter?
            if (DB::table('notifications')->where('user_id', $userId)
                ->where('title', 'like', 'Quarterly report%')->where('message', 'like', "%({$tag})%")->exists()) return;

            // the quarter had no interns: nothing to report
            [$from, $to] = [Carbon::create($py, ($pq - 1) * 3 + 1, 1, 0, 0, 0, 'Asia/Manila')->toDateString(),
                            Carbon::create($py, ($pq - 1) * 3 + 1, 1, 0, 0, 0, 'Asia/Manila')->addMonths(3)->subDay()->toDateString()];
            if (!DB::table('attendance_records')->whereBetween('date', [$from, $to])->exists()) return;

            $word = ['1st', '2nd', '3rd', '4th'][$pq - 1];
            Notifier::toUser($userId, 'Quarterly report ready to review',
                "The {$word} quarter ({$tag}) has ended. Its statistics and HTE tables are already filled in by the system: check them, answer the short questions, then print or download the report.",
                'quarterly_report');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}