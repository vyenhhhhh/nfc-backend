<?php
//LogActivity.php
namespace App\Http\Middleware;

use App\Services\Activity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Writes one line to the activity log for every change a signed-in user makes
 * (POST / PUT / PATCH / DELETE that succeeded). Reading pages is not logged.
 * Sign-in, sign-out and failed sign-ins are logged in AuthController.
 */
class LogActivity
{
    // route => what it means, in plain words
    private const LABELS = [
        'POST admin/users'                              => 'Added a user',
        'PATCH admin/users/{id}'                        => 'Edited a user\'s details',
        'POST admin/users/{id}/photo'                   => 'Changed a user\'s photo',
        'DELETE admin/users/{id}'                       => 'Deleted a user',
        'PATCH admin/users/{id}/active'                 => 'Changed an account\'s status',
        'PATCH users/{id}/settings'                     => 'Changed an intern\'s settings',
        'PATCH supervisor/interns/{id}/dtr-mode'        => 'Changed an intern\'s DTR type',
        'PATCH coordinator/interns/{id}/supervisor'     => 'Assigned a supervisor',
        'POST coordinator/movs/{id}/review'             => 'Reviewed a requirement',
        'POST coordinator/movs/{id}/undo'               => 'Took back a requirement review',
        'POST supervisor/validate'                      => 'Reviewed online attendance',
        'POST supervisor/online/{id}/review'            => 'Reviewed online attendance',
        'POST supervisor/overtime/{id}/review'          => 'Reviewed overtime',
        'POST coordinator/overtime/{id}/review'         => 'Reviewed overtime (final)',
        'POST supervisor/time-corrections/{id}/review'  => 'Reviewed a time correction',
        'POST supervisor/work-mode-requests/{id}/review'=> 'Reviewed a work arrangement request',
        'POST supervisor/dtr-certifications'            => 'Certified a DTR',
        'DELETE supervisor/dtr-certifications/{id}'     => 'Removed a DTR certification',
        'POST supervisor/nfc-requests/{id}/review'      => 'Reviewed an ID card request',
        'POST admin/nfc-requests/printed'               => 'Marked ID cards as printed',
        'POST admin/nfc-requests/{id}/link'             => 'Linked an NFC card',
        'POST admin/nfc-requests/{id}/claim'            => 'Released an ID card',
        'POST calendar'                                 => 'Added a calendar event',
        'PUT calendar/{id}'                             => 'Edited a calendar event',
        'DELETE calendar/{id}'                          => 'Removed a calendar event',
        'POST announcements'                            => 'Posted an announcement',
        'DELETE announcements/{id}'                     => 'Removed an announcement',
        'DELETE announcements/history/{id}'             => 'Deleted an announcement for good',
        'PUT coordinator/requirement-deadlines'         => 'Set requirement due dates',
        'POST coordinator/requirement-deadlines/remind' => 'Sent a requirement reminder',
        'POST coordinator/reports'                      => 'Added a report',
        'PUT coordinator/reports/{id}'                  => 'Edited a report',
        'DELETE coordinator/reports/{id}'               => 'Removed a report',
        'POST coordinator/reports/{id}/remind'          => 'Sent a report reminder',
        'POST admin/kiosks'                             => 'Added a kiosk',
        'PATCH admin/kiosks/{id}'                       => 'Changed a kiosk',
        'DELETE admin/kiosks/{id}'                      => 'Removed a kiosk',
        'PUT admin/settings/id-card'                    => 'Changed the ID card settings',
        'POST account/change-password'                  => 'Changed own password',
        'POST account/recovery-code/regenerate'         => 'Made a new recovery code',
        'POST account/privacy-consent'                  => 'Agreed to the Data Privacy notice',
        'POST account/first-password'                   => 'Set own password (first sign-in)',
        'POST intern/submit-online'                     => 'Submitted online attendance',
        'POST intern/submit-mov'                        => 'Uploaded a requirement',
        'DELETE intern/submit-online/{id}'              => 'Removed own online attendance',
        'DELETE intern/submit-mov/{id}'                 => 'Removed own upload',
        'POST intern/time-corrections'                  => 'Asked for a time correction',
        'POST intern/work-mode-requests'                => 'Asked for a work arrangement change',
        'DELETE intern/work-mode-requests/{id}'         => 'Cancelled a work arrangement request',
        'POST intern/nfc-request'                       => 'Asked for an ID card',
    ];

    // only these fields from the request are kept in the log (never passwords, files or signatures)
    private const KEEP = ['status', 'remarks', 'title', 'role', 'requirement', 'phase', 'date', 'from', 'to',
        'work_mode', 'tracking_type', 'dtr_mode', 'supervisor_id', 'requested_mode', 'active', 'name', 'placement', 'kind', 'audience'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $method = strtoupper($request->method());
            if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $response;
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($status >= 400) return $response;

            $auth = $request->attributes->get('auth_user');
            if (!$auth) return $response;                      // sign-in pages and the kiosk are logged elsewhere

            $uri   = preg_replace('#^api/#', '', (string) optional($request->route())->uri());
            $key   = $method . ' ' . $uri;
            if (in_array($key, ['POST logout'], true)) return $response;
            $label = self::LABELS[$key] ?? null;
            if (!$label) return $response;                     // reading-type POSTs (e.g. show recovery code) are skipped

            $parts = [];
            $id = optional($request->route())->parameter('id');
            if ($id !== null) {
                // name the person when the route points at a user
                if (str_contains($uri, 'users/{id}') || str_contains($uri, 'interns/{id}')) {
                    $who = DB::table('users')->where('id', $id)->value('name');
                    $parts[] = $who ? $who : "#{$id}";
                } else {
                    $parts[] = "#{$id}";
                }
            }
            foreach (self::KEEP as $k) {
                $v = $request->input($k);
                if ($v === null || $v === '' || is_array($v)) continue;
                $parts[] = $k . ': ' . (is_bool($v) ? ($v ? 'yes' : 'no') : mb_substr((string) $v, 0, 120));
            }
            if ($uid = $request->input('user_id')) {
                if ((int) $uid !== (int) $auth->id) {
                    $who = DB::table('users')->where('id', $uid)->value('name');
                    if ($who) $parts[] = 'intern: ' . $who;
                }
            }
            Activity::record($auth, $label, $parts ? implode(' · ', $parts) : null, $request);
        } catch (\Throwable $e) {
            // the log must never break the request
        }
        return $response;
    }
}
