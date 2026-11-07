<?php
//SchemaCache.php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * "Does this table / column exist?" without asking the database every time.
 * On Supabase each Schema::hasTable / hasColumn is a slow information_schema query, and a kiosk
 * tap used to make several of them. A YES is remembered for a day (a column never disappears on
 * its own); a NO is asked again each time, so a new migration works right away.
 */
class SchemaCache
{
    public static function has(string $table, ?string $column = null): bool
    {
        $key = 'schema_has:' . $table . ($column ? '.' . $column : '');
        if (Cache::get($key)) return true;
        try {
            $yes = $column ? Schema::hasColumn($table, $column) : Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
        if ($yes) Cache::put($key, true, 86400);
        return $yes;
    }
}