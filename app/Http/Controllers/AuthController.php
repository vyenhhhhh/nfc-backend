<?php
//authcontroller.php
namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return $this->session($user);
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
        return $this->session($user);
    }

    private function session($user)
    {
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
            'token'         => $token,
        ]);
    }

    // Own account for everyone; staff may open any account.
    // (date_of_birth and student_id can reset a password, so interns must not read other people's.)
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

    // Sign out: the token stops working right away
    public function logout(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        if ($auth) {
            DB::table('api_tokens')->where('id', $auth->token_id)->delete();
        }
        return response()->json(['message' => 'Signed out.']);
    }
}