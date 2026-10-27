<?php
//Backup.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database backup: every table into one gzip'd JSON file in storage/app/backups.
 *   php artisan mytrack:backup                 make one (also Admin → Accounts → Backups)
 *   php artisan mytrack:restore <file>         put back the rows that are missing (never deletes or overwrites)
 * Works the same on Supabase (PostgreSQL), MySQL and SQLite. The uploaded files (photos, MOVs) are
 * in Supabase Storage and are not part of this file.
 */
class Backup
{
    public const KEEP = 12;   // how many backups the server keeps

    // sign-in tokens, caches and queues: nothing worth restoring
    private const SKIP = ['api_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
                          'password_reset_tokens', 'personal_access_tokens', 'migrations'];

    public static function dir(): string
    {
        $d = storage_path('app/backups');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function tables(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $rows = DB::select("select tablename as name from pg_tables where schemaname = current_schema() order by tablename");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = array_map(fn ($r) => (object) ['name' => array_values((array) $r)[0]], DB::select('show tables'));
        } else {
            $rows = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name");
        }
        return array_values(array_filter(array_map(fn ($r) => $r->name, $rows), fn ($t) => !in_array($t, self::SKIP, true)));
    }

    /** makes a backup; returns ['name' => ..., 'size' => bytes, 'rows' => n] */
    public static function make(): array
    {
        $now  = Carbon::now('Asia/Manila');
        $name = 'mytrack-backup-' . $now->format('Y-m-d-His') . '.json.gz';
        $path = self::dir() . DIRECTORY_SEPARATOR . $name;
        $gz   = gzopen($path, 'wb6');
        if (!$gz) throw new \RuntimeException('Could not write to storage/app/backups.');

        $total = 0;
        gzwrite($gz, '{"app":"MYTRACK","made_at":' . json_encode($now->toIso8601String()) . ',"tables":{');
        foreach (self::tables() as $i => $t) {
            gzwrite($gz, ($i ? ',' : '') . json_encode($t) . ':[');
            $first = true;
            $q = DB::table($t);
            if (Schema::hasColumn($t, 'id')) $q->orderBy('id');
            foreach ($q->cursor() as $row) {
                gzwrite($gz, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $first = false;
                $total++;
            }
            gzwrite($gz, ']');
        }
        gzwrite($gz, '}}');
        gzclose($gz);

        self::prune();
        return ['name' => $name, 'size' => filesize($path), 'rows' => $total];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . DIRECTORY_SEPARATOR . 'mytrack-backup-*.json.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f),
                      'created_at' => Carbon::createFromTimestamp(filemtime($f), 'Asia/Manila')->toDateTimeString()];
        }
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** the full path of a backup, or null (only our own file names: no "../") */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^mytrack-backup-[0-9\-]+\.json\.gz$/', $name)) return null;
        $p = self::dir() . DIRECTORY_SEPARATOR . $name;
        return is_file($p) ? $p : null;
    }

    private static function prune(): void
    {
        foreach (array_slice(self::list(), self::KEEP) as $old) {
            @unlink(self::dir() . DIRECTORY_SEPARATOR . $old['name']);
        }
    }

    /**
     * Puts back rows that are missing (same id). Existing rows are never changed or deleted,
     * so it is safe to run on the live database. Returns [table => rows added].
     */
    public static function restore(string $file, ?string $only = null): array
    {
        $json = @gzdecode(file_get_contents($file));
        $data = json_decode($json ?: '', true);
        if (!is_array($data) || ($data['app'] ?? '') !== 'MYTRACK') throw new \RuntimeException('This is not a MYTRACK backup file.');

        $done = [];
        foreach ($data['tables'] as $t => $rows) {
            if ($only && $t !== $only) continue;
            if (!Schema::hasTable($t) || !$rows) { $done[$t] = 0; continue; }
            $cols = Schema::getColumnListing($t);
            $added = 0;
            foreach (array_chunk($rows, 200) as $chunk) {
                $chunk = array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), $chunk);
                $added += DB::table($t)->insertOrIgnore($chunk);
            }
            // PostgreSQL: move the id counter past the restored ids
            if ($added && in_array('id', $cols, true) && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("select setval(pg_get_serial_sequence(?, 'id'), coalesce((select max(id) from \"{$t}\"), 1))", [$t]);
            }
            $done[$t] = $added;
        }
        return $done;
    }
}