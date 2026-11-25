<?php
//AppraisalRequirement.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Student Trainee Performance Appraisal" requirement follows the HTE supervisor's appraisal.
 *
 * When the supervisor submits the appraisal in the system (or uploads the signed paper one), the intern's
 * requirement is marked approved automatically, so the intern doesn't have to upload a copy of it.
 * It is stored as an approved MOV with no file:
 *   file_path = ''  and  original_name = ONLINE_NAME
 * If the appraisal is removed, that MOV is removed too and the requirement goes back to "Not submitted".
 * A file the intern uploaded themselves (waiting or approved) is left alone.
 */
class AppraisalRequirement
{
    public const KEY = 'performance_appraisal';
    public const ONLINE_NAME = 'Appraisal submitted online';

    /** is this MOV the one made by the online appraisal (not an uploaded file)? */
    public static function isOnline($mov): bool
    {
        return $mov
            && ($mov->requirement ?? null) === self::KEY
            && (string) ($mov->file_path ?? '') === ''
            && ($mov->original_name ?? null) === self::ONLINE_NAME;
    }

    /** the supervisor submitted the appraisal: mark the intern's requirement as approved */
    public static function markDone(int $internId, ?int $byId, ?string $byName, ?string $byRole, $at = null): void
    {
        if (!Schema::hasTable('movs')) return;
        $at = $at ? Carbon::parse($at) : Carbon::now('Asia/Manila');

        // the requirement's own name and phase (the coordinator may have renamed it)
        $def = Schema::hasTable('requirement_defs') ? DB::table('requirement_defs')->where('key', self::KEY)->first() : null;
        $phase = $def->phase ?? 'during';
        $title = $def->label ?? 'Student Trainee Performance Appraisal';

        $who = $byRole === 'ojt_coordinator' ? 'OJT coordinator' : 'HTE supervisor';
        $remarks = 'Submitted online by ' . ($byName ? "{$byName} ({$who})" : "the {$who}") . '.';

        $mine = DB::table('movs')->where('user_id', $internId)->where('requirement', self::KEY);

        // the intern already uploaded the appraisal themselves (waiting or approved): keep theirs
        $uploaded = (clone $mine)->whereIn('status', ['pending', 'approved'])
            ->where('file_path', '!=', '')->exists();
        if ($uploaded) return;

        $online = (clone $mine)->where('file_path', '')->where('original_name', self::ONLINE_NAME)->first();
        if ($online) {
            DB::table('movs')->where('id', $online->id)->update([
                'status' => 'approved', 'remarks' => $remarks, 'reviewed_by' => $byId, 'reviewed_at' => $at, 'updated_at' => $at,
            ]);
            return;
        }

        DB::table('movs')->insert([
            'user_id'       => $internId,
            'title'         => $title,
            'requirement'   => self::KEY,
            'phase'         => $phase,
            'file_path'     => '',
            'original_name' => self::ONLINE_NAME,
            'status'        => 'approved',
            'remarks'       => $remarks,
            'reviewed_by'   => $byId,
            'reviewed_at'   => $at,
            'created_at'    => $at,
            'updated_at'    => $at,
        ]);
    }

    /** the appraisal was removed: the requirement goes back to "Not submitted" */
    public static function clear(int $internId): void
    {
        if (!Schema::hasTable('movs')) return;
        DB::table('movs')->where('user_id', $internId)->where('requirement', self::KEY)
            ->where('file_path', '')->where('original_name', self::ONLINE_NAME)->delete();
    }
}