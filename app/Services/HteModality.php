<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One internship modality per HTE (kept on the HTE supervisor's account: users.hte_modality).
 * An intern follows their HTE's modality automatically, unless the coordinator gave them their own
 * (users.modality_custom = true).
 * An intern's HTE = their supervisor's HTE; without a supervisor, the HTE with the same name as their placement.
 */
class HteModality
{
    public const LIST = ['onsite', 'hybrid', 'virtual', 'project'];
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try { self::$ready = \App\Services\SchemaCache::has('users', 'hte_modality') && \App\Services\SchemaCache::has('users', 'modality_custom'); }
            catch (\Throwable $e) { self::$ready = false; }
        }
        return self::$ready;
    }

    // HTE names are matched without extra spaces and without caring about capitals
    public static function key(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }

    /** The supervisor accounts of one HTE (by name). */
    public static function supervisorsOf(string $hte)
    {
        $k = self::key($hte);
        return DB::table('users')->where('role', 'supervisor')->whereNotNull('placement')->get(['id', 'placement', 'hte_modality'])
            ->filter(fn ($s) => self::key($s->placement) === $k)->values();
    }

    /** The modality of the HTE an intern is at, or null when that HTE has none set. */
    public static function forIntern($intern): ?string
    {
        if (!self::ready() || !$intern) return null;
        if (!empty($intern->supervisor_id)) {
            $m = DB::table('users')->where('id', $intern->supervisor_id)->value('hte_modality');
            if ($m) return $m;
        }
        if (empty($intern->placement)) return null;
        return self::supervisorsOf($intern->placement)->pluck('hte_modality')->filter()->first();
    }

    /** The work mode that goes with a modality (virtual = no kiosk, on-site = kiosk; hybrid / project keep theirs). */
    public static function workMode(string $modality, ?string $current): string
    {
        return $modality === 'virtual' ? 'offsite' : ($modality === 'onsite' ? 'onsite' : ($current ?: 'onsite'));
    }

    /** Give one intern their HTE's modality (only when they follow it). Returns the new modality when it changed. */
    public static function applyToIntern(int $internId, bool $notify = true): ?string
    {
        if (!self::ready()) return null;
        $u = DB::table('users')->where('id', $internId)->where('role', 'intern')->first();
        if (!$u || !empty($u->modality_custom)) return null;
        $m = self::forIntern($u);
        if (!$m || $m === ($u->modality ?? 'onsite')) return null;
        DB::table('users')->where('id', $internId)->update(['modality' => $m, 'work_mode' => self::workMode($m, $u->work_mode ?? null)]);
        if ($notify) self::tell($internId, $m);
        return $m;
    }

    /** Set an HTE's modality and give it to its interns. $skipCustom = keep the interns who have their own. */
    public static function setForHte(string $hte, string $modality, bool $skipCustom = true): array
    {
        $sups = self::supervisorsOf($hte);
        $supIds = $sups->pluck('id')->all();
        if ($supIds) DB::table('users')->whereIn('id', $supIds)->update(['hte_modality' => $modality]);

        $k = self::key($hte);
        $interns = DB::table('users')->where('role', 'intern')->get(['id', 'placement', 'supervisor_id', 'modality', 'modality_custom', 'work_mode'])
            ->filter(fn ($u) => in_array((int) $u->supervisor_id, array_map('intval', $supIds), true) || self::key($u->placement) === $k);
        $changed = 0; $skipped = 0;
        foreach ($interns as $u) {
            if ($skipCustom && !empty($u->modality_custom)) { $skipped++; continue; }
            $set = ['modality_custom' => false];
            if (($u->modality ?? 'onsite') !== $modality) {
                $set['modality'] = $modality;
                $set['work_mode'] = self::workMode($modality, $u->work_mode ?? null);
                $changed++;
                self::tell((int) $u->id, $modality);
            }
            DB::table('users')->where('id', $u->id)->update($set);
        }
        return ['supervisors' => count($supIds), 'interns' => $interns->count(), 'changed' => $changed, 'skipped' => $skipped];
    }

    private static function tell(int $internId, string $m): void
    {
        $how = ['onsite' => 'on-site: time in and out at the kiosk.', 'hybrid' => 'hybrid: use the kiosk on office days and Submit Online on remote days.',
                'virtual' => 'virtual: log your days through Submit Online.',
                'project' => 'project-based: log the days you worked on your project through Submit Online, or tap the kiosk when you are on site.'][$m] ?? $m;
        try { Notifier::toUser($internId, 'Internship Modality Updated', "Your HTE's internship is {$how}", 'settings'); } catch (\Throwable $e) { /* best-effort */ }
    }
}