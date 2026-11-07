<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The internship requirements the OJT coordinator manages (table requirement_defs).
 * Falls back to config/ojt.php when the table has not been created yet (php artisan migrate).
 */
class Requirements
{
    private static ?bool $hasTable = null;

    public static function ready(): bool
    {
        if (self::$hasTable === null) {
            try { self::$hasTable = Schema::hasTable('requirement_defs'); } catch (\Throwable $e) { self::$hasTable = false; }
        }
        return self::$hasTable;
    }

    /** Active requirements of one phase (or all), in order. */
    public static function active(?string $phase = null)
    {
        if (!self::ready()) return collect();
        return DB::table('requirement_defs')
            ->where('active', true)
            ->when($phase, fn ($q) => $q->where('phase', $phase))
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /** Keys of the pre-internship requirements that must all be approved before the internship starts. */
    public static function preKeys(): array
    {
        if (self::ready()) {
            $keys = self::active('pre')->pluck('key')->all();
            if ($keys) return $keys;
        }
        return config('ojt.pre_requirements', []);
    }
}
