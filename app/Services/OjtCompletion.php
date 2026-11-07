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
