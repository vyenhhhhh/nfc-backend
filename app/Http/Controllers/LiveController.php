<?php
//LiveController.php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Has anything changed?" for the live dashboards.
 *
 * GET /api/live  ->  { "v": "3f2a..." }
 *
 * The value is a fingerprint of the tables the dashboards show (how many rows, the newest id and
 * the latest update). The browser asks every few seconds; only when the fingerprint changes does it
 * download the real data again. One small query, so it stays cheap even with a far-away database.
 */
class LiveController extends Controller
{
    // tables that, when they change, should refresh the dashboards
    private const TABLES = [
        'attendance_records', 'online_submissions', 'movs', 'time_corrections',
        'nfc_requests', 'nfc_cards', 'calendar_events', 'announcements', 'notifications', 'users',
        'dtr_certifications', 'work_mode_requests', 'requirement_deadlines',
    ];

    public function show()
    {
        // which of those tables exist (and their columns) - checked once an hour, not on every call
        $tables = Cache::remember('live_tables_v3', 3600, function () {
            $found = [];
            foreach (self::TABLES as $t) {
                if (!Schema::hasTable($t)) continue;
                $found[$t] = [
                    'id'      => Schema::hasColumn($t, 'id'),
                    'updated' => Schema::hasColumn($t, 'updated_at'),
                ];
            }
            return $found;
        });

        $text  = DB::getDriverName() === 'pgsql' ? 'TEXT' : 'CHAR';
        $parts = [];
        foreach ($tables as $t => $has) {
            $parts[] = "SELECT '{$t}' AS t, COUNT(*) AS c, "
                . ($has['id'] ? 'MAX(id)' : 'NULL') . ' AS m, '
                . ($has['updated'] ? "CAST(MAX(updated_at) AS {$text})" : 'NULL') . ' AS u '
                . "FROM {$t}";
        }

        $rows = $parts ? DB::select(implode(' UNION ALL ', $parts)) : [];

        // the database may return the rows in a different order each time; sort them so the
        // fingerprint only changes when the data really changes (otherwise pages reload for nothing)
        $rows = array_map(fn ($r) => [(string) $r->t, (string) $r->c, (string) $r->m, (string) $r->u], $rows);
        sort($rows);

        return response()->json(['v' => md5(json_encode($rows))])
            ->header('Cache-Control', 'no-store');
    }
}