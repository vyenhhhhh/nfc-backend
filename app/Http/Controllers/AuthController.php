<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')
            ->where('email', $request->email)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if ($request->password !== $user->password) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $hasCard = DB::table('nfc_cards')->where('user_id', $user->id)->exists();

        return response()->json([
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'role'          => $user->role,
            'work_mode'     => $user->work_mode ?? 'onsite',
            'tracking_type' => $user->tracking_type ?? 'hours',
            'has_card'      => $hasCard,
        ]);
    }

    public function me(Request $request)
    {
        $userId = $request->query('user_id');
        $user   = DB::table('users')->where('id', $userId)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($user);
    }
}