<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NfcRequestController extends Controller
{
    // Intern: my card status + my latest request
    public function mine(Request $request)
    {
        $user = User::findOrFail($request->query('user_id'));
        $latest = DB::table('nfc_requests')->where('user_id', $user->id)->latest('id')->first();

        return response()->json([
            'has_card' => !empty($user->uid),
            'uid'      => $user->uid,
            'request'  => $latest,
        ]);
    }

    // Intern: apply for a card
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type'    => 'required|string|max:50',
            'notes'   => 'nullable|string|max:500',
        ]);

        $hasActive = DB::table('nfc_requests')
            ->where('user_id', $data['user_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($hasActive) {
            return response()->json(['message' => 'You already have an active NFC card request.'], 422);
        }

        DB::table('nfc_requests')->insert([
            'user_id'    => $data['user_id'],
            'type'       => $data['type'],
            'notes'      => $data['notes'] ?? null,
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Request submitted! Your supervisor will review it.']);
    }

    // Supervisor: all requests, pending first
    public function index()
    {
        return DB::table('nfc_requests as n')
            ->join('users as u', 'u.id', '=', 'n.user_id')
            ->select('n.*', 'u.name as intern_name', 'u.email as intern_email', 'u.photo as intern_photo')
            ->orderByRaw("CASE n.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'issued' THEN 2 ELSE 3 END")
            ->orderByDesc('n.id')
            ->get();
    }

    // Supervisor: approve / reject / link the card
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'      => 'required|in:approved,rejected,issued',
            'remarks'     => 'nullable|string|max:500',
            'uid'         => 'nullable|string|max:100',
            'reviewer_id' => 'required|exists:users,id',
        ]);

        $req = DB::table('nfc_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($data['status'] === 'issued') {
            if (empty($data['uid'])) {
                return response()->json(['message' => 'Scan or enter the card UID to link it.'], 422);
            }
            $taken = User::where('uid', $data['uid'])->where('id', '!=', $req->user_id)->exists();
            if ($taken) {
                return response()->json(['message' => 'That card is already linked to another user.'], 422);
            }
            User::where('id', $req->user_id)->update(['uid' => $data['uid']]);
        }

        DB::table('nfc_requests')->where('id', $id)->update([
            'status'      => $data['status'],
            'remarks'     => $data['remarks'] ?? null,
            'uid'         => $data['uid'] ?? null,
            'reviewed_by' => $data['reviewer_id'],
            'reviewed_at' => now(),
            'updated_at'  => now(),
        ]);

        $messages = [
            'issued'   => 'Card linked to the intern.',
            'approved' => 'Request approved. Link the card once it is ready.',
            'rejected' => 'Request rejected.',
        ];

        return response()->json(['message' => $messages[$data['status']]]);
    }
}