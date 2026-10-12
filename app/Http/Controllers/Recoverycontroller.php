<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RecoveryController extends Controller
{
    /* ───────── helpers ───────── */

    private function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($c, 0, 4) . '-' . substr($c, 4);
    }

    private function norm($s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s));
    }

    /** The user's recovery code (made on first use). $rotate = true makes a new one. */
    private function codeFor(int $userId, bool $rotate = false): string
    {
        $row = DB::table('account_recovery')->where('user_id', $userId)->first();

        if ($row && !$rotate) {
            try {
                return Crypt::decryptString($row->code);
            } catch (\Throwable $e) {
                // unreadable (e.g. APP_KEY changed): fall through and make a new one
            }
        }

        $code = $this->newCode();
        $now  = Carbon::now('Asia/Manila');
        $enc  = Crypt::encryptString($code);

        if ($row) {
            DB::table('account_recovery')->where('user_id', $userId)->update(['code' => $enc, 'updated_at' => $now]);
        } else {
            DB::table('account_recovery')->insert(['user_id' => $userId, 'code' => $enc, 'created_at' => $now, 'updated_at' => $now]);
        }
        return $code;
    }

    /** Find by ID number: student ID for interns, employee ID for staff (case-insensitive). */
    private function findUser(string $identifier)
    {
        $id = strtolower(trim($identifier));
        if ($id === '') return null;
        return DB::table('users')->whereRaw('LOWER(student_id) = ?', [$id])->first();
    }

    /** Signed-in actions: the user must type their current password. */
    private function checkedUser(Request $request)
    {
        $request->validate(['user_id' => 'required|integer', 'password' => 'required|string']);
        $user = DB::table('users')->where('id', $request->user_id)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return null;
        }
        return $user;
    }

    /* ───────── My Profile (signed in) ───────── */

    public function show(Request $request)
    {
        $user = $this->checkedUser($request);
        if (!$user) return response()->json(['message' => 'Wrong password.'], 403);

        return response()->json(['code' => $this->codeFor($user->id)]);
    }

    public function regenerate(Request $request)
    {
        $user = $this->checkedUser($request);
        if (!$user) return response()->json(['message' => 'Wrong password.'], 403);

        return response()->json(['code' => $this->codeFor($user->id, true), 'message' => 'New recovery code created.']);
    }

    public function changePassword(Request $request)
    {
        $request->validate(['new_password' => 'required|string|min:6|max:100']);
        $user = $this->checkedUser($request);   // "password" = the current password
        if (!$user) return response()->json(['message' => 'Your current password is wrong.'], 403);

        DB::table('users')->where('id', $user->id)->update([
            'password'   => Hash::make($request->new_password),
            'updated_at' => Carbon::now('Asia/Manila'),
        ]);

        // sign out every other device, keep this one
        $auth = $request->attributes->get('auth_user');
        $keep = $auth->token_id ?? 0;
        DB::table('api_tokens')->where('user_id', $user->id)->where('id', '!=', $keep)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /* ───────── Login page (not signed in) ───────── */

    // Step 1 of "Forgot password": ID number + (recovery code OR date of birth).
    // Only if they match the same account do we hand back a 10-minute pass to set a new password.
    public function verify(Request $request)
    {
        $request->validate([
            'identifier'    => 'required|string|max:150',
            'code'          => 'nullable|string|max:30',
            'date_of_birth' => 'nullable|date',
        ]);

        $fail = response()->json(['message' => "We couldn't verify those details. Check them and try again."], 422);

        if (!$request->filled('code') && !$request->filled('date_of_birth')) {
            return response()->json(['message' => 'Enter your recovery code or your date of birth.'], 422);
        }

        $user = $this->findUser($request->identifier);
        if (!$user) return $fail;

        $ok = false;
        if ($request->filled('code')) {
            $row = DB::table('account_recovery')->where('user_id', $user->id)->first();
            if ($row) {
                try {
                    $ok = hash_equals($this->norm(Crypt::decryptString($row->code)), $this->norm($request->code));
                } catch (\Throwable $e) {
                    $ok = false;
                }
            }
        }
        if (!$ok && $request->filled('date_of_birth') && $user->date_of_birth) {
            $ok = Carbon::parse($request->date_of_birth)->toDateString() === substr((string) $user->date_of_birth, 0, 10);
        }
        if (!$ok) return $fail;

        $now   = Carbon::now();
        $token = Str::random(48);
        DB::table('recovery_resets')->where('user_id', $user->id)->delete();   // one pass at a time
        DB::table('recovery_resets')->where('expires_at', '<', $now)->delete();
        DB::table('recovery_resets')->insert([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
        ]);

        return response()->json(['reset_token' => $token, 'name' => $user->name]);
    }

    // Step 2: the pass from step 1 + the new password
    public function reset(Request $request)
    {
        $request->validate([
            'reset_token'  => 'required|string|max:100',
            'new_password' => 'required|string|min:6|max:100',
        ]);

        $pass = DB::table('recovery_resets')
            ->where('token_hash', hash('sha256', $request->reset_token))
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$pass) {
            return response()->json(['message' => 'This reset window has expired. Please start again.'], 422);
        }

        DB::table('users')->where('id', $pass->user_id)->update([
            'password'   => Hash::make($request->new_password),
            'updated_at' => Carbon::now('Asia/Manila'),
        ]);

        DB::table('recovery_resets')->where('user_id', $pass->user_id)->delete();
        $this->codeFor($pass->user_id, true);                                    // the old code is used up
        DB::table('api_tokens')->where('user_id', $pass->user_id)->delete();     // sign out everywhere

        return response()->json(['message' => 'Password changed. Your recovery code was changed too: see the new one in My Profile after you sign in.']);
    }

    // Forgot recovery code → email it to the address on file
    public function sendCode(Request $request)
    {
        $request->validate(['identifier' => 'required|string|max:150']);

        $user = $this->findUser($request->identifier);

        // The code is only created from My Profile. If the user never made one, there is nothing to send.
        $row = $user ? DB::table('account_recovery')->where('user_id', $user->id)->first() : null;

        if ($user && $row && $user->email) {
            try {
                $code = Crypt::decryptString($row->code);
                Mail::raw(
                    "Hello {$user->name},\n\nYour MYTRACK recovery code is:\n\n    {$code}\n\n"
                    . "Use it on the login page (Forgot password?) to set a new password.\n"
                    . "If you didn't ask for this, you can ignore this email.\n\nCSU CCIS MYTRACK",
                    function ($m) use ($user) {
                        $m->to($user->email)->subject('Your MYTRACK recovery code');
                    }
                );
            } catch (\Throwable $e) {
                Log::error('Recovery email failed: ' . $e->getMessage());
            }
        }

        // same answer whether or not the account exists
        return response()->json(['message' => 'If an account matches, the recovery code was sent to its email address.']);
    }
}