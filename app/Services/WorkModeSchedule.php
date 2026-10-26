<?php
//WorkModeSchedule.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work arrangements with dates: an approved "work from home from Oct 26 to Oct 30"
 *   - starts on its start date (the intern's work mode becomes offsite), and
 *   - ends after its end date (the intern goes back onsite by itself).
 * There is no cron job to set up: this runs whenever the system is used
 * (the live check every few seconds, a kiosk tap, opening a profile), at most once a minute.
 */
class WorkModeSchedule
{
    // Never breaks the page that called it: if anything goes wrong (migration not run yet, cache not set up...)
    // the problem is written to storage/logs/laravel.log and the request carries on normally.
    public static function sync(bool $force = false): void
    {
        try {
            self::run($force);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WorkModeSchedule::sync skipped: ' . $e->getMessage());
        }
    }

    private static function run(bool $force): void
    {
        if (!$force) {
            // at most once a minute (if the cache isn't available, just run it)
            try { if (!Cache::add('work_mode_sync', 1, 60)) return; } catch (\Throwable $e) { /* run anyway */ }
        }
        if (!Schema::hasTable('work_mode_requests') || !Schema::hasColumn('work_mode_requests', 'applied_at')) return; // migration not run yet

        $today = Carbon::today('Asia/Manila')->toDateString();
        $now   = Carbon::now('Asia/Manila');

        // 1) approved requests whose start date has come: switch the work mode now
        $due = DB::table('work_mode_requests')->where('status', 'approved')->whereNull('applied_at')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))->get();
        foreach ($due as $r) {
            // a newer approved request for this intern wins: this one is only marked as done
            $newer = DB::table('work_mode_requests')->where('user_id', $r->user_id)->where('id', '>', $r->id)
                ->where('status', 'approved')->exists();
            if ($newer) {
                DB::table('work_mode_requests')->where('id', $r->id)->update(['applied_at' => $now, 'ended_at' => $now, 'updated_at' => $now]);
                continue;
            }
            DB::table('users')->where('id', $r->user_id)->update(['work_mode' => $r->requested_mode, 'updated_at' => $now]);
            DB::table('work_mode_requests')->where('id', $r->id)->update(['applied_at' => $now, 'updated_at' => $now]);
            if ($r->start_date) {
                Notifier::toUser($r->user_id, 'Work Arrangement Started',
                    $r->requested_mode === 'offsite'
                        ? 'Your work from home starts today. Log each workday under Submit Online.'
                        : 'You are back onsite starting today. Tap your card at the kiosk.',
                    'work_mode_review');
            }
        }

        // 2) work from home whose end date has passed: back to onsite
        $ended = DB::table('work_mode_requests')->where('status', 'approved')->where('requested_mode', 'offsite')
            ->whereNotNull('applied_at')->whereNull('ended_at')->whereNotNull('end_date')->where('end_date', '<', $today)->get();
        foreach ($ended as $r) {
            // only if nothing newer changed the work mode in the meantime
            $newer = DB::table('work_mode_requests')->where('user_id', $r->user_id)->where('id', '>', $r->id)
                ->where('status', 'approved')->exists();
            if (!$newer) {
                DB::table('users')->where('id', $r->user_id)->update(['work_mode' => 'onsite', 'updated_at' => $now]);
                Notifier::toUser($r->user_id, 'Back Onsite',
                    'Your work from home ended on ' . Carbon::parse($r->end_date)->format('M j') . '. You are back onsite: tap your card at the kiosk.',
                    'work_mode_review');
            }
            DB::table('work_mode_requests')->where('id', $r->id)->update(['ended_at' => $now, 'updated_at' => $now]);
        }
    }
}