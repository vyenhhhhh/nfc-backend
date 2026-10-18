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

    /** Hours only count after the MOA is approved (Internship FAQ #6–7). */
    public static function moaApproved($internId): bool
    {
        if (!config('ojt.require_moa', true)) return true;

        return DB::table('movs')
            ->where('user_id', $internId)
            ->where('requirement', 'moa')
            ->where('status', 'approved')
            ->exists();
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