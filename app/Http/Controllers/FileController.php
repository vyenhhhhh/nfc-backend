<?php

namespace App\Http\Controllers;

use App\Services\PrivateFiles;
use App\Services\Supervision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/files/link?path=movs/abc.pdf  ->  { "url": "..." }   (signed in)
 *
 * Private files (see App\Services\PrivateFiles) are only handed out to the people who may see them,
 * as a link that works for a few minutes:
 *   movs/           requirement uploads   the intern who sent it, and the OJT coordinator
 *   evaluations/    signed evaluation     the admin, the OJT coordinator, the supervisor who sent it,
 *                                         and the intern's own HTE supervisor
 *   excuse-proofs/  school-event proof    the intern, their HTE supervisor, the admin, the OJT coordinator
 *   signatures/     staff signatures      staff (admin, coordinator, supervisors) and the owner
 *   work_mode/      old WFH letters       the intern, the admin, the OJT coordinator
 * Anything else (profile photos, blank forms) is public and gets its normal link.
 */
class FileController extends Controller
{
    public function link(Request $request)
    {
        $path = ltrim(str_replace('\\', '/', (string) $request->query('path', '')), '/');
        if ($path === '' || str_contains($path, '..') || strlen($path) > 255) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        $auth = $request->attributes->get('auth_user');

        if (!PrivateFiles::isPrivate($path)) {
            return response()->json(['url' => PrivateFiles::publicDisk()->url($path)]);
        }
        if (!$this->allowed($auth, $path)) {
            return response()->json(['message' => 'You are not allowed to open this file.'], 403);
        }
        $url = PrivateFiles::link($path);
        if (!$url) {
            return response()->json(['message' => 'The file is no longer there.'], 404);
        }
        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/files/open/{path}?expires=...&signature=...   (no sign-in: the signature is the permission)
     * Only for the private disk on this computer; the link comes from link() and works for a few minutes.
     */
    public function open(string $path)
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        abort_if($path === '' || str_contains($path, '..') || !PrivateFiles::isPrivate($path), 404);
        $disk = PrivateFiles::disk();
        abort_unless($disk->exists($path), 404);
        return $disk->response($path, basename($path), [
            'Cache-Control'          => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function allowed($auth, string $path): bool
    {
        if (!$auth) return false;
        $me     = (int) $auth->id;
        $role   = $auth->role ?? '';
        $folder = explode('/', $path)[0];
        $office = in_array($role, ['admin', 'ojt_coordinator'], true);

        switch ($folder) {
            case 'movs':
                $owner = DB::table('movs')->where('file_path', $path)->value('user_id');
                if ($owner === null) return false;
                return (int) $owner === $me || $role === 'ojt_coordinator';

            case 'evaluations':
                $e = DB::table('evaluations')->where('file_path', $path)->first(['intern_id', 'evaluator_id']);
                if (!$e) return false;
                return $office || (int) $e->evaluator_id === $me
                    || ($role === 'supervisor' && Supervision::supervises($auth, $e->intern_id));

            case 'excuse-proofs':
                $owner = DB::table('school_event_excuses')->where('proof_path', $path)->value('user_id');
                if ($owner === null) return false;
                return (int) $owner === $me || $office
                    || ($role === 'supervisor' && Supervision::supervises($auth, $owner));

            case 'signatures':
                if (in_array($role, ['admin', 'ojt_coordinator', 'supervisor'], true)) return true;
                return DB::table('users')->where('id', $me)->value('signature') === $path;

            case 'work_mode':
                $owner = DB::table('work_mode_requests')->where('file_path', $path)->value('user_id');
                return $owner !== null && ((int) $owner === $me || $office);
        }
        return false;
    }
}
