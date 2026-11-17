<?php
//OjtCompletion.php
namespace App\Services;

/**
 * When is an intern's OJT done?
 *  - on-site, hybrid, virtual: when the rendered hours reach the required hours of the program
 *  - project-based: when the project is done (all tasks given by the HTE), marked by the HTE supervisor
 *    and confirmed by the OJT coordinator. It may be before the required hours; if the hours are
 *    reached first, the intern keeps working until the project is done.
 */
class OjtCompletion
{
    public static function isProject($u): bool
    {
        return ($u->modality ?? null) === 'project';
    }

    /**
     * Does this intern keep a flexible schedule (never late, no undertime or tardiness on the DTR)?
     * Only project-based interns, and only when their HTE doesn't make them follow 8:00–5:00
     * (users.project_fixed_schedule on the HTE supervisor's account; not set = flexible).
     */
    public static function flexibleSchedule($u): bool
    {
        if (!self::isProject($u)) return false;
        if (empty($u->supervisor_id) || !SchemaCache::has('users', 'project_fixed_schedule')) return true;
        return !\Illuminate\Support\Facades\DB::table('users')->where('id', $u->supervisor_id)->value('project_fixed_schedule');
    }

    /** ids of the interns (from a list with modality and supervisor_id) that keep a flexible schedule, with one query */
    public static function flexibleIds($interns): array
    {
        $proj = collect($interns)->filter(fn ($u) => self::isProject($u));
        if ($proj->isEmpty()) return [];
        $fixed = SchemaCache::has('users', 'project_fixed_schedule')
            ? \Illuminate\Support\Facades\DB::table('users')->whereIn('id', $proj->pluck('supervisor_id')->filter()->unique()->all())
                ->where('project_fixed_schedule', true)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];
        return $proj->filter(fn ($u) => !in_array((int) ($u->supervisor_id ?? 0), $fixed, true))->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }

    public static function projectDone($u): bool
    {
        return !empty($u->project_confirmed_at ?? null);
    }

    public static function done($u, float $rendered, float $required): bool
    {
        if (self::isProject($u)) return self::projectDone($u);
        return $rendered + 0.0001 >= $required;
    }

    /** extra users columns the check needs (only those that exist yet) */
    public static function columns(): array
    {
        $c = ['modality'];
        if (SchemaCache::has('users', 'project_confirmed_at')) $c[] = 'project_confirmed_at';
        return $c;
    }
}