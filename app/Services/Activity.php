<?php
//Activity.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Activity log: who did what, and when. Never breaks the action itself:
 * if the table is missing (migration not run yet) or the insert fails, it is skipped.
 */
class Activity
{
    private static ?bool $ready = null;

    public static function record(?object $user, string $action, ?string $details = null, ?Request $request = null): void
    {
        try {
            if (self::$ready === null) self::$ready = Schema::hasTable('activity_logs');
            if (!self::$ready) return;
            DB::table('activity_logs')->insert([
                'user_id'    => $user->id ?? null,
                'user_name'  => $user->name ?? null,
                'role'       => $user->role ?? null,
                'action'     => mb_substr($action, 0, 120),
                'details'    => $details !== null ? mb_substr($details, 0, 2000) : null,
                'ip'         => $request ? $request->ip() : request()->ip(),
                'created_at' => Carbon::now('Asia/Manila'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity log skipped: ' . $e->getMessage());
        }
    }
}
