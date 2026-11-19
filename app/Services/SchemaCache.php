<?php
//SchemaCache.php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Does this table / column exist?" without asking the database every time.
 * On Supabase each Schema::hasTable / hasColumn is a slow catalog query (often 100–300 ms), and pages
 * used to make several of them on every request. Now ONE query reads every table and column of the
 * database, and the answer is kept for a day. A table or column that is not found makes it read again
 * once (so a new migration works right away), then the answer is kept for the rest of the request.
 */
class SchemaCache
{
    private static ?array $map = null;      // [table => [column => true]]
    private static bool $fresh = false;     // read straight from the database during this request

    public static function has(string $table, ?string $column = null): bool
    {
        $map = self::map();
        if (self::found($map, $table, $column)) return true;
        if (self::$fresh) return false;
        // not found in the kept copy: maybe a migration just ran, read it again once
        return self::found(self::map(true), $table, $column);
    }

    /** forget the kept copy (after php artisan migrate) */
    public static function forget(): void
    {
        Cache::forget('schema_map');
        self::$map = null;
        self::$fresh = false;
    }

    private static function found(array $map, string $table, ?string $column): bool
    {
        if (!isset($map[$table])) return false;
        return $column === null || isset($map[$table][$column]);
    }

    private static function map(bool $reload = false): array
    {
        if (self::$map !== null && !$reload) return self::$map;
        if (!$reload) {
            $kept = Cache::get('schema_map');
            if (is_array($kept) && $kept) return self::$map = $kept;
        }
        $map = [];
        try {
            // Postgres: read pg_catalog straight (much faster than information_schema on Supabase)
            $rows = DB::getDriverName() === 'pgsql'
                ? DB::select("select c.relname as table_name, a.attname as column_name
                      from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_namespace n on n.oid = c.relnamespace
                      where n.nspname = current_schema() and c.relkind in ('r', 'p', 'v') and a.attnum > 0 and not a.attisdropped")
                : DB::select("select table_name, column_name from information_schema.columns where table_schema = database()");
            foreach ($rows as $r) $map[$r->table_name][$r->column_name] = true;
        } catch (\Throwable $e) {
            return self::$map = self::$map ?? [];
        }
        self::$fresh = true;
        if ($map) Cache::put('schema_map', $map, 86400);
        return self::$map = $map;
    }
}