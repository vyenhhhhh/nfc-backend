<?php
//authcontroller.php
namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Services\Activity;

class AuthController extends Controller
{
    // Login protection: after 5 wrong passwords for the same email, that email is locked for 15 minutes
    // (on top of the 20-requests-a-minute limit per device in routes/api.php)
    private const MAX_TRIES   = 5;
    private const LOCK_SECONDS = 15 * 60;

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim($request->email));
        $lock  = 'login:' . $email;

        if (RateLimiter::tooManyAttempts($lock, self::MAX_TRIES)) {
            $min = max(1, (int) ceil(RateLimiter::availableIn($lock) / 60));
            return response()->json([
                'message' => "Too many wrong passwords. For your safety this account is locked for {$min} more minute" . ($min === 1 ? '' : 's') . '.',
            ], 429);
        }

        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($lock, self::LOCK_SECONDS);
            $left = RateLimiter::remaining($lock, self::MAX_TRIES);
            Activity::record($user ?: null, 'Failed sign-in', "email: {$email}", $request);
            if ($left <= 0) {
                Activity::record($user ?: null, 'Account locked for 15 minutes', "email: {$email} · too many wrong passwords", $request);
                return response()->json(['message' => 'Too many wrong passwords. For your safety this account is locked for 15 minutes.'], 429);
            }
            return response()->json([
                'message' => 'Invalid email or password.' . ($left <= 2 ? " {$left} more " . ($left === 1 ? 'try' : 'tries') . ' before the account is locked for 15 minutes.' : ''),
            ], 401);
        }

        RateLimiter::clear($lock);
        return $this->session($user, $request);
    }

    // graduated / inactive accounts can't sign in (their records stay)
    private function deactivated($user)
    {
        if (empty($user->deactivated_at ?? null)) return null;
        return response()->json([
            'message' => 'This account has been deactivated. If you think this is a mistake, contact the CCIS office.',
        ], 403);
    }

    // Sign in with Google: the account must already exist (created by the admin) with the same email
    public function googleLogin(Request $request)
    {
        $request->validate(['access_token' => 'required|string']);
        $clientId = env('GOOGLE_CLIENT_ID');
        if (!$clientId) {
            return response()->json(['message' => 'Google sign-in is not set up yet.'], 503);
        }

        // 1. the token must have been issued to OUR app
        $info = Http::get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $request->access_token]);
        if (!$info->ok() || ($info->json('aud') ?? $info->json('azp')) !== $clientId) {
            return response()->json(['message' => 'Google sign-in failed.'], 401);
        }

        // 2. read the Google account's email
        $g = Http::withToken($request->access_token)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        $email = strtolower($g->json('email') ?? '');
        if (!$g->ok() || !$email || !$g->json('email_verified')) {
            return response()->json(['message' => 'Google sign-in failed.'], 401);
        }

        // 3. only accounts that already exist can sign in
        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first();
        if (!$user) {
            return response()->json(['message' => "No MyTrack account uses $email. Ask your administrator to add it."], 403);
        }
        return $this->session($user, $request);
    }

    private function session($user, Request $request)
    {
        if ($blocked = $this->deactivated($user)) {
            Activity::record($user, 'Sign-in refused: account deactivated', null, $request);
            return $blocked;
        }

        $hasCard = DB::table('nfc_cards')->where('user_id', $user->id)->exists();

        // Sign-in token: the browser keeps the token, the database keeps only its hash
        $token = Str::random(64);
        $now   = Carbon::now();
        DB::table('api_tokens')->where('user_id', $user->id)->where('expires_at', '<', $now)->delete();
        DB::table('api_tokens')->insert([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->copy()->addDays(8),
            'created_at' => $now,
        ]);
        Activity::record($user, 'Signed in', null, $request);

        return response()->json([
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'role'          => $user->role,
            'photo'         => $user->photo ?? null,
            'work_mode'     => $user->work_mode ?? 'onsite',
            'tracking_type' => $user->tracking_type ?? 'hours',
            'dtr_mode'      => $user->dtr_mode ?? 'two',
            'has_card'      => $hasCard,
            'must_change_password' => (bool) ($user->must_change_password ?? false),
            'token'         => $token,
        ]);
    }

    // Own account for everyone; staff may open any account.
    // (interns must not read other people's personal details: birthday, student ID, contacts.)
    public function me(Request $request)
    {
        $auth    = $request->attributes->get('auth_user');
        $authId  = (int) ($auth->id ?? 0);
        $wanted  = (int) $request->query('user_id', $authId);
        $isStaff = in_array($auth->role ?? '', ['admin', 'ojt_coordinator', 'supervisor'], true);

        if (!$isStaff && $wanted !== $authId) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $user = DB::table('users')->where('id', $wanted)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        unset($user->password, $user->remember_token);

        // for the My Profile page: the linked NFC card and the supervisor's name
        $user->nfc_uid = DB::table('nfc_cards')->where('user_id', $user->id)->value('uid');
        $user->supervisor_name = !empty($user->supervisor_id)
            ? DB::table('users')->where('id', $user->supervisor_id)->value('name')
            : null;

        return response()->json($user);
    }

    // First sign-in after the Excel import: set your own password (no current password needed,
    // only allowed while the account still has the temporary one)
    // POST /api/account/first-password   { new_password }
    public function firstPassword(Request $request)
    {
        $request->validate(['new_password' => 'required|string|min:8|max:100']);
        $auth = $request->attributes->get('auth_user');
        $user = DB::table('users')->where('id', $auth->id)->first();
        if (!$user || empty($user->must_change_password)) {
            return response()->json(['message' => 'Your password was already set. Use My Profile to change it.'], 409);
        }
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json(['message' => 'Choose a new password, not the temporary one.'], 422);
        }
        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
            'updated_at' => Carbon::now('Asia/Manila'),
        ]);
        return response()->json(['message' => 'Password set. Welcome to MYTRACK!']);
    }

    // Data Privacy Act: the user agrees once, on first sign-in
    // POST /api/account/privacy-consent
    public function privacyConsent(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');
        DB::table('users')->where('id', $auth->id)->whereNull('privacy_consent_at')->update(['privacy_consent_at' => $now]);
        return response()->json(['message' => 'Thank you.', 'privacy_consent_at' => DB::table('users')->where('id', $auth->id)->value('privacy_consent_at')]);
    }

    // Sign out: the token stops working right away
    public function logout(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        if ($auth) {
            DB::table('api_tokens')->where('id', $auth->token_id)->delete();
            Activity::record($auth, 'Signed out', null, $request);
        }
        return response()->json(['message' => 'Signed out.']);
    }
}