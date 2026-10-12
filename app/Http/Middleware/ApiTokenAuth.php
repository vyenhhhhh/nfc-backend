<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Checks the "Authorization: Bearer <token>" header sent by the React app.
 *
 * Usage in routes/api.php:
 *   ApiTokenAuth::class                         -> any signed-in user
 *   ApiTokenAuth::class . ':admin,ojt_coordinator'  -> only those roles
 *   add "self" to the list to also allow a user to act on their own {id}
 *
 * Interns and other non-staff users can only ask for their own data: if the request
 * carries a user_id that is not theirs, it is refused.
 */
class ApiTokenAuth
{
    private const STAFF = ['admin', 'ojt_coordinator', 'supervisor'];

    public function handle(Request $request, Closure $next, ...$roles)
    {
        $header = (string) $request->header('Authorization', '');
        $plain  = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';

        if ($plain === '') {
            return response()->json(['message' => 'Please sign in.'], 401);
        }

        // one query: the token and its user together
        $auth = DB::table('api_tokens as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.token_hash', hash('sha256', $plain))
            ->where('t.expires_at', '>', Carbon::now())
            ->select('u.id', 'u.role', 'u.name', 'u.email', 't.id as token_id')
            ->first();

        if (!$auth) {
            return response()->json(['message' => 'Your session expired. Please sign in again.'], 401);
        }

        if ($roles) {
            $allowed = in_array($auth->role, $roles, true)
                || (in_array('self', $roles, true) && (string) $request->route('id') === (string) $auth->id);
            if (!$allowed) {
                return response()->json(['message' => 'You are not allowed to do that.'], 403);
            }
        }

        $isStaff = in_array($auth->role, self::STAFF, true);
        $asked   = $request->input('user_id');
        if (!$isStaff && $asked !== null && (string) $asked !== (string) $auth->id) {
            return response()->json(['message' => 'You can only access your own data.'], 403);
        }

        $request->attributes->set('auth_user', $auth);
        return $next($request);
    }
}