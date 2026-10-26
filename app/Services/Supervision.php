<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Who supervises whom, and the MOA rule.
 *  - an intern's HTE supervisor is users.supervisor_id (set by the admin)
 *  - a supervisor only sees and reviews their own interns
 */
class Supervision
{
    /** Ids of the interns this signed-in user may see, or null = everyone (admin / coordinator). */
    public static function scope($auth): ?array
    {
        if (!$auth || $auth->role !== 'supervisor') return null;

        return DB::table('users')
            ->where('role', 'intern')
            ->where('supervisor_id', $auth->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Is this intern under this supervisor? */
    public static function supervises($auth, $internId): bool
    {
        return $auth && $auth->role === 'supervisor'
            && DB::table('users')->where('id', $internId)->where('supervisor_id', $auth->id)->exists();
    }

    /** The intern's supervisor id, or null if none is assigned yet. */
    public static function supervisorOf($internId): ?int
    {
        $id = DB::table('users')->where('id', $internId)->value('supervisor_id');
        return $id ? (int) $id : null;
    }

    /**
     * Pre-internship requirements that are not approved yet (empty = the internship can start).
     * The internship starts only when every pre-internship requirement is approved by the coordinator.
     */
    public static function missingPreRequirements($internId): array
    {
        if (!config('ojt.require_pre_requirements', true)) return [];

        $required = config('ojt.pre_requirements', []);
        $approved = DB::table('movs')
            ->where('user_id', $internId)
            ->whereIn('requirement', $required)
            ->where(function ($q) { $q->where('phase', 'pre')->orWhereNull('phase'); })
            ->where('status', 'approved')
            ->pluck('requirement')
            ->unique()
            ->all();

        return array_values(array_diff($required, $approved));
    }

    /** True when every pre-internship requirement is approved (the internship has started). */
    public static function internshipStarted($internId): bool
    {
        return count(self::missingPreRequirements($internId)) === 0;
    }

    /** Tell the intern's supervisor, or the coordinators when no supervisor is assigned. */
    public static function notifyReviewer($internId, string $title, string $message, string $type): void
    {
        $sup = self::supervisorOf($internId);
        if ($sup) {
            Notifier::toUser($sup, $title, $message, $type);
        } else {
            Notifier::toRoles(['ojt_coordinator'], $title, $message, $type);
        }
    }
}